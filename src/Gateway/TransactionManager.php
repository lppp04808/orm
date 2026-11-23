<?php

declare(strict_types=1);

namespace Pixiaoka\Orm\Gateway;

use Pixiaoka\Orm\Cache\RowCache;
use Pixiaoka\Orm\Cast\Caster;
use Pixiaoka\Orm\Context;
use Pixiaoka\Orm\Entity;
use Pixiaoka\Orm\Exception\IdentityException;
use Pixiaoka\Orm\Exception\TransactionException;
use Pixiaoka\Orm\Metadata\MetadataReader;
use Pixiaoka\Orm\Metadata\ModelMeta;
use Pixiaoka\Orm\Observability\Clock;
use Pixiaoka\Orm\Observability\EventDispatcher;
use Pixiaoka\Orm\Observability\TransactionEvent;
use Pixiaoka\Orm\Session;

/**
 * @internal 事务状态、保存点恢复和身份历史；不强持有所属 EntityGateway。
 *
 * @phpstan-type BaseEntityState array{new: bool, original: array<string, mixed>, values: array<string, mixed>}
 * @phpstan-type EntityState array{new: bool, original: array<string, mixed>, values: array<string, mixed>, relations?: array<string, mixed>, loadedRelations?: array<string, true>}
 * @phpstan-type CacheSnapshot array{items: array<string, Entity>, identities: array<string, array<string, int|float|string|bool|null>>, entities: list<Entity>}
 * @phpstan-type IdentityMetadata array{identities: array<string, BaseEntityState>, new: array<string, list<BaseEntityState|null>>}
 */
final class TransactionManager
{
    /** 回滚恢复期间禁止缓存回填和钩子状态重新污染事务基线。 */
    private bool $restoringTransaction = false;

    /** @var \WeakMap<Context, int> 每个 Session 上下文的逻辑事务深度；物理保存点由连接维护。 */
    private \WeakMap $transactionDepthByContext;

    /** @var \WeakMap<Context, list<\WeakMap<Entity, EntityState>>> 每层保存进入前的实体状态，内层回滚只恢复当前帧。 */
    private \WeakMap $transactionFramesByContext;

    /** @var \WeakMap<Context, list<CacheSnapshot>> 每个 SAVEPOINT 对应一份缓存快照。 */
    private \WeakMap $transactionCacheFramesByContext;

    /** @var \WeakMap<Context, list<IdentityMetadata>> 插入身份历史也按保存点恢复。 */
    private \WeakMap $transactionMetadataFramesByContext;

    /** @var \WeakMap<Entity, EntityState> 外层提交/回滚恢复的最终基线；内层帧不覆盖它。 */
    private \WeakMap $outerTransactionStates;

    /** @var \WeakMap<Context, bool> 对象身份避免 Fiber 销毁后的数字 ID 重用。 */
    private \WeakMap $inTransactionByContext;

    /** @var \WeakMap<Context, \WeakMap<Entity, array{new: bool, original: array<string, mixed>, values: array<string, mixed>}>> */
    private \WeakMap $txnStatesByContext;

    /** @var \WeakMap<Context, array<string, array{new: bool, original: array<string, mixed>, values: array<string, mixed>}>> 同主键共享最外层基线，禁止中途状态覆盖。 */
    private \WeakMap $txnIdentityStates;

    /** @var \WeakMap<Context, array<string, list<array{new: bool, original: array<string, mixed>, values: array<string, mixed>}|null>>> 同身份插入历史，回滚后按删除关系决定保留或失效。 */
    private \WeakMap $txnNewIdentities;

    /** @var \WeakMap<Entity, array{new: bool, original: array<string, mixed>, values: array<string, mixed>}>|null 插入前身份不完整时的对象快照。 */
    private ?\WeakMap $pendingInsertStates = null;

    /** @var \WeakMap<Context, array{items: array<string, Entity>, identities: array<string, array<string, int|float|string|bool|null>>, entities: list<Entity>}> */
    private \WeakMap $txnCacheSnapshots;

    /** @var \WeakMap<Context, bool>|null discard 返回后读取的回滚确认，下一次事务前清理。 */
    private ?\WeakMap $confirmedRollbacks = null;

    /** @var \WeakMap<Context, float> 仅用于物理事务开始事件的耗时。 */
    private \WeakMap $txnStartedAt;

    public function __construct(
        private readonly Connection $connection,
        private readonly RowCache $cache,
        private readonly MetadataReader $metadata,
        private readonly Caster $caster,
        private readonly SqlExecutor $executor,
        private readonly EventDispatcher $events,
        private readonly Clock $clock,
    ) {
        $this->transactionDepthByContext = new \WeakMap();
        $this->transactionFramesByContext = new \WeakMap();
        $this->transactionCacheFramesByContext = new \WeakMap();
        $this->transactionMetadataFramesByContext = new \WeakMap();
        $this->outerTransactionStates = new \WeakMap();
        $this->inTransactionByContext = new \WeakMap();
        $this->txnStatesByContext = new \WeakMap();
        $this->txnIdentityStates = new \WeakMap();
        $this->txnNewIdentities = new \WeakMap();
        $this->pendingInsertStates ??= new \WeakMap();
        $this->txnCacheSnapshots = new \WeakMap();
        $this->txnStartedAt = new \WeakMap();
    }

    /** @throws \Throwable */
    public function run(EntityGateway $gateway, callable $func): mixed
    {
        $this->executor->assertConnectionUsable();
        $ctx = Session::context();
        $sessionName = Session::transactionName($gateway, $ctx);
        $depth = $this->transactionDepth($ctx);
        if ($depth > 0 && !$this->connection instanceof ManagedConnection) {
            // 旧装饰器没有物理保存点能力，维持原公共 transaction() 的“复用外层”契约。
            return $func();
        }

        if ($depth === 0) {
            $this->resetTransactionDepthTracking($ctx);
            Session::clearRollbackStatus($ctx, $sessionName);
            if ($this->confirmedRollbacks !== null) {
                unset($this->confirmedRollbacks[$ctx]);
            }
            $this->pendingInsertStates = new \WeakMap();
            $this->inTransactionByContext[$ctx] = true;
            if ($this->connection instanceof ManagedConnection) {
                $this->outerTransactionStates = new \WeakMap();
            }
            $this->txnCacheSnapshots[$ctx] = $this->captureTransactionCache();
            $this->txnStatesByContext[$ctx] = $this->newTransactionStates();
            $this->txnIdentityStates[$ctx] = [];
            $this->txnNewIdentities[$ctx] = [];
            $this->txnStartedAt[$ctx] = $this->clock->now();
            $this->transactionFramesByContext[$ctx] = [];
            $this->transactionCacheFramesByContext[$ctx] = [];
            $this->transactionMetadataFramesByContext[$ctx] = [];
        }

        $this->pushTransactionFrame($ctx, $depth);
        $this->transactionDepthByContext[$ctx] = $depth + 1;
        $startedAt = $this->clock->now();
        $begun = false;
        $committed = false;
        $rolledBack = false;
        $discardConfirmed = false;
        $failure = null;
        $rollbackFailure = null;
        $rollbackAttempted = false;
        $discardAttempted = false;
        $commitAttempted = false;
        $result = null;
        try {
            try {
                Session::claimConnection($gateway, $ctx);
                try {
                    if (!$this->connection->beginTransaction()) {
                        throw new \RuntimeException('数据库事务开始失败');
                    }
                    $begun = true;
                } catch (\Throwable $e) {
                    $failure = $e;
                    $this->invalidateConnection();
                    $transactionFailure = new TransactionException('开始', null, $e);
                    $this->observeTransaction('begin', $ctx, $startedAt, $transactionFailure);
                    throw $transactionFailure;
                }
                $this->observeTransaction('begin', $ctx, $startedAt);
            } finally {
                if ($failure !== null && $depth === 0) {
                    Session::releaseConnection($gateway, $ctx);
                }
            }

            try {
                $result = $func();
                $this->rememberCachedEntities();
                if (Session::currentContext() !== $ctx) {
                    throw new \LogicException('事务回调不能切换 Fiber/协程上下文后提交');
                }
                if ($this->transactionDepth($ctx) !== $depth + 1) {
                    if (!Session::rollbackConfirmed($ctx, $sessionName)) {
                        $this->invalidateConnection();
                        throw new \LogicException('事务期间 Session 已被清理，不能继续提交；该连接已禁止复用');
                    }
                    $discardConfirmed = true;
                    throw new \LogicException('事务期间 Session 已被清理，操作已回滚，不能继续提交');
                }
                $commitAttempted = true;
                if (!$this->connection->commit()) {
                    throw new \RuntimeException('数据库事务提交失败');
                }
                $committed = true;
            } catch (\Throwable $e) {
                $failure = $e;
                if ($e instanceof TransactionException && $this->transactionDepth($ctx) === 0
                    && !Session::rollbackConfirmed($ctx, $sessionName)) {
                    $rollbackAttempted = true;
                    $discardAttempted = true;
                    $rollbackFailure = $e;
                    throw $e;
                }
                if ($commitAttempted) {
                    $this->observeTransaction('commit', $ctx, $startedAt, $e);
                }
                $discardAttempted = Session::rollbackAttempted($ctx, $sessionName);
                if ($this->transactionDepth($ctx) === 0 && Session::rollbackConfirmed($ctx, $sessionName)) {
                    $discardConfirmed = true;
                }
                if ($this->transactionDepth($ctx) === $depth + 1 && !$discardAttempted) {
                    $rollbackAttempted = true;
                    try {
                        if (!$this->connection->rollback()) {
                            throw new \RuntimeException('数据库事务回滚失败');
                        }
                        $rolledBack = true;
                        if (!$commitAttempted) {
                            if ($depth === 0) {
                                $this->restoreOuterTransaction($ctx);
                            } else {
                                $this->restoreTransactionFrame($ctx);
                            }
                        }
                    } catch (\Throwable $rollbackError) {
                        $rollbackFailure = $rollbackError;
                        $this->invalidateConnection();
                    }
                }
                if (($rolledBack || $discardConfirmed) && !$commitAttempted) {
                    throw $failure;
                }
                $this->invalidateConnection();
                $transactionFailure = new TransactionException(
                    $commitAttempted ? '提交' : '回滚',
                    $commitAttempted ? null : $failure,
                    !$commitAttempted && $rollbackFailure !== null ? $rollbackFailure : $failure,
                );
                if ($rollbackAttempted) {
                    $rollbackFailure = $transactionFailure;
                }
                throw $transactionFailure;
            }
        } finally {
            if ($begun && !$committed && !$rolledBack && !$discardConfirmed) {
                $this->invalidateConnection();
            }
            if (!$begun && $failure !== null) {
                $this->invalidateConnection();
                $this->cache->forgetKnown();
            }
            if ($begun && !$committed && $rollbackAttempted && !$discardAttempted) {
                $this->observeTransaction('rollback', $ctx, $startedAt, $rolledBack ? null : $rollbackFailure);
            }
            $this->popTransactionFrame($ctx);
            if ($this->transactionDepth($ctx) === 0) {
                Session::releaseConnection($gateway, $ctx);
                $this->clearTransaction($ctx);
            }
            if ($committed) {
                $this->observeTransaction('commit', $ctx, $startedAt);
            }
        }

        return $result;
    }

    /** @internal 由 Session::discard() 调用，回滚并解除当前上下文的事务所有权。 */
    public function releaseContext(EntityGateway $gateway, Context $context): void
    {
        $active = $this->transactionDepth($context) > 0;
        $startedAt = $active
            ? ($this->txnStartedAt[$context] ?? $this->clock->now())
            : $this->clock->now();
        $failure = null;
        $transactionFailure = null;
        try {
            if ($active) {
                $rolledBack = $this->connection instanceof ManagedConnection
                    ? $this->connection->forceRollback()
                    : $this->connection->rollback();
                if (!$rolledBack) {
                    throw new \RuntimeException('数据库事务回滚失败');
                }
                $this->restoreOuterTransaction($context);
                $this->confirmedRollbacks ??= new \WeakMap();
                $this->confirmedRollbacks[$context] = true;
            }
        } catch (\Throwable $e) {
            $failure = $e;
            $transactionFailure = new TransactionException('回滚', null, $e);
        } finally {
            if ($transactionFailure !== null) {
                $this->invalidateConnection();
            }
            $this->clearTransaction($context);
            if ($active) {
                $this->observeTransaction('rollback', $context, $startedAt, $transactionFailure);
            }
            Session::releaseConnection($gateway, $context);
        }
        if ($transactionFailure !== null) {
            throw $transactionFailure;
        }
    }

    /** @internal Session 在清理事务状态之后读取一次确认标记。 */
    public function rollbackWasConfirmed(Context $context): bool
    {
        return $this->confirmedRollbacks !== null && isset($this->confirmedRollbacks[$context]);
    }

    /** @internal 隐式换绑不能代替调用方决定未结束事务的回滚与失败处理。 */
    public function assertSessionReplaceable(Context $context): void
    {
        if ($this->inTransactionByContext[$context] ?? false) {
            throw new \LogicException('当前 Session 存在未结束事务，禁止换绑；请先显式 discard 并处理回滚结果');
        }
    }

    /** @param array<string, int|float|string|bool|null> $identityParts */
    public function recordDeletedIdentity(Entity $entity, array $identityParts): void
    {
        $context = Session::context();
        if ($this->inTransactionByContext[$context] ?? false) {
            $key = RowCache::key($entity::class, $identityParts);
            $history = $this->txnNewIdentities[$context] ?? [];
            if (isset($history[$key])) {
                $baseline = array_pop($history[$key]);
                if ($history[$key] === []) {
                    unset($history[$key]);
                }
                $this->txnNewIdentities[$context] = $history;
                if ($baseline !== null) {
                    $identities = $this->txnIdentityStates[$context] ?? [];
                    $identities[$key] ??= $baseline;
                    $this->txnIdentityStates[$context] = $identities;
                }
            }
        }
    }

    /** @param array<string, int|float|string|bool|null> $identityParts */
    public function recordInsertedIdentity(Entity $entity, ModelMeta $meta, array $identityParts): void
    {
        $context = Session::context();
        if ($this->inTransactionByContext[$context] ?? false) {
            $key = RowCache::key($entity::class, $identityParts);
            $baseline = $this->txnIdentityStates[$context][$key] ?? null;
            $states = $this->txnStatesByContext[$context] ?? $this->newTransactionStates();
            $states[$entity] = $baseline ?? ($this->pendingInsertStates[$entity] ?? $entity->captureState($meta));
            $this->txnStatesByContext[$context] = $states;
            if ($this->connection instanceof ManagedConnection) {
                // 删除原行后重插会把该主键的最终回滚目标改为已提交行；
                // 同步校正此实例的外层基线，避免继续沿用插入前的新实体状态。
                $this->outerTransactionStates[$entity] = $states[$entity];
            }
            $new = $this->txnNewIdentities[$context] ?? [];
            $new[$key] ??= [];
            $new[$key][] = $baseline;
            $this->txnNewIdentities[$context] = $new;
        }
    }

    private function invalidateConnection(): void
    {
        $this->executor->invalidateConnection();
        $this->cache->flush();
        $this->cache->forgetKnown();
    }

    private function clearTransaction(Context $context): void
    {
        $this->pendingInsertStates = new \WeakMap();
        $this->outerTransactionStates = new \WeakMap();
        unset(
            $this->transactionDepthByContext[$context],
            $this->transactionFramesByContext[$context],
            $this->transactionCacheFramesByContext[$context],
            $this->transactionMetadataFramesByContext[$context],
            $this->inTransactionByContext[$context],
            $this->txnStatesByContext[$context],
            $this->txnIdentityStates[$context],
            $this->txnNewIdentities[$context],
            $this->txnCacheSnapshots[$context],
            $this->txnStartedAt[$context],
        );
    }

    private function transactionDepth(Context $context): int
    {
        return $this->transactionDepthByContext[$context] ?? 0;
    }

    private function resetTransactionDepthTracking(Context $context): void
    {
        $this->transactionDepthByContext[$context] = 0;
    }

    /** 先快照当前状态，再写本层基线；嵌套事务不得覆盖最外层的回滚目标。 */
    private function pushTransactionFrame(Context $context, int $depth): void
    {
        if ($depth === 0) {
            // 最外层沿用既有事务状态采集，兼容不实现新能力接口的旧装饰器。
            $this->rememberCachedEntities();

            return;
        }

        $this->rememberCachedEntities();
        $snapshot = $this->captureTransactionCache();
        foreach ($snapshot['entities'] as $entity) {
            $this->rememberEntity($entity);
        }

        /** @var \WeakMap<Entity, EntityState> $frame */
        $frame = new \WeakMap();
        /**
         * @var object $entity
         */
        foreach ($this->txnStatesByContext[$context] ?? [] as $entity => $state) {
            $frame[$entity] = $entity->captureState($this->metadata->read($entity::class));
            $this->outerTransactionStates[$entity] ??= $state;
        }
        $frames = $this->transactionFramesByContext[$context] ?? [];
        $frames[] = $frame;
        $this->transactionFramesByContext[$context] = $frames;
        $cacheFrames = $this->transactionCacheFramesByContext[$context] ?? [];
        $cacheFrames[] = $snapshot;
        $this->transactionCacheFramesByContext[$context] = $cacheFrames;
        $metadataFrames = $this->transactionMetadataFramesByContext[$context] ?? [];
        $metadataFrames[] = [
            'identities' => $this->txnIdentityStates[$context] ?? [],
            'new' => $this->txnNewIdentities[$context] ?? [],
        ];
        $this->transactionMetadataFramesByContext[$context] = $metadataFrames;
    }

    private function restoreTransactionFrame(Context $context): void
    {
        // SAVEPOINT 回滚只恢复本层之前已登记的实体，最终整链回滚
        // 仍需覆盖内层首次接触、但在外层提交后才登记的新实例。
        $this->rememberCachedEntities();
        $frames = $this->transactionFramesByContext[$context] ?? [];
        $frame = end($frames);
        if ($frame instanceof \WeakMap) {
            // 内层首次接触/插入的实体没有进入帧快照，使用其本层首次登记时保留的外层基线。
            foreach ($this->txnStatesByContext[$context] ?? $this->newTransactionStates() as $entity => $state) {
                if (!isset($frame[$entity]) && isset($this->outerTransactionStates[$entity])) {
                    $entity->restoreState($this->outerTransactionStates[$entity], $this->metadata->read($entity::class));
                }
            }
            $this->restoreFrame($context, $frame);
        }
        $cacheFrames = $this->transactionCacheFramesByContext[$context] ?? [];
        $snapshot = end($cacheFrames);
        if (is_array($snapshot)) {
            $this->restoreTransactionCache($context, $snapshot);
        }
        $metadataFrames = $this->transactionMetadataFramesByContext[$context] ?? [];
        $metadata = end($metadataFrames);
        if (is_array($metadata)) {
            $this->txnIdentityStates[$context] = $metadata['identities'];
            $this->txnNewIdentities[$context] = $metadata['new'];
        }
    }

    /** @param \WeakMap<Entity, EntityState> $frame */
    private function restoreFrame(Context $context, \WeakMap $frame): void
    {
        $restored = $this->newTransactionStates();
        foreach ($frame as $entity => $state) {
            $entity->restoreState($state, $this->metadata->read($entity::class));
            $restored[$entity] = $state;
        }
        $this->txnStatesByContext[$context] = $restored;
    }

    /** @param CacheSnapshot $snapshot */
    private function restoreTransactionCache(Context $context, array $snapshot): void
    {
        $this->cache->restoreSnapshot($snapshot);
        $frame = $this->txnStatesByContext[$context] ?? $this->newTransactionStates();
        $cachedKeys = array_fill_keys(array_keys($snapshot['items']), true);
        foreach ($frame as $entity => $state) {
            if ($state['new']) {
                continue;
            }
            $meta = $this->metadata->read($entity::class);
            $parts = $this->identityParts($entity, $meta);
            if (!isset($cachedKeys[$this->cache->setKey($entity, $parts)])) {
                $this->cache->set($entity, $parts);
            }
        }
    }

    private function restoreOuterTransaction(Context $context): void
    {
        if ($this->connection instanceof ManagedConnection) {
            foreach ($this->cache->known() as $entity) {
                $this->rememberEntity($entity, $context);
            }
            foreach ($this->txnStatesByContext[$context] ?? [] as $entity => $state) {
                $this->outerTransactionStates[$entity] ??= $state;
            }
            $this->restoreFrame($context, $this->outerTransactionStates);
            $this->txnNewIdentities[$context] = [];
        } else {
            $this->restoreTransaction($context);
        }
        // 恢复后的缓存回填不得重新记录“事务中的新状态”并覆盖外层基线。
        $this->restoreCacheOnly($context);
    }

    private function restoreCacheOnly(Context $context): void
    {
        $previous = $this->restoringTransaction;
        $this->restoringTransaction = true;
        try {
            $snapshot = $this->txnCacheSnapshots[$context] ?? null;
            if ($snapshot !== null) {
                $this->restoreTransactionCache($context, $snapshot);
            }
        } finally {
            $this->restoringTransaction = $previous;
        }
    }

    private function popTransactionFrame(Context $context): void
    {
        if ($this->transactionDepth($context) === 0) {
            return;
        }
        $this->transactionDepthByContext[$context] = $this->transactionDepth($context) - 1;
        $frames = $this->transactionFramesByContext[$context] ?? [];
        $cacheFrames = $this->transactionCacheFramesByContext[$context] ?? [];
        $metadataFrames = $this->transactionMetadataFramesByContext[$context] ?? [];
        array_pop($frames);
        array_pop($cacheFrames);
        array_pop($metadataFrames);
        $this->transactionFramesByContext[$context] = $frames;
        $this->transactionCacheFramesByContext[$context] = $cacheFrames;
        $this->transactionMetadataFramesByContext[$context] = $metadataFrames;
        // 身份历史已在 restoreTransactionFrame() 中恢复；内层提交仍保留给外层回滚。
    }

    private function restoreTransaction(Context $context): void
    {
        foreach ($this->cache->known() as $entity) {
            $this->rememberEntity($entity, $context);
        }
        foreach ($this->txnStatesByContext[$context] ?? [] as $entity => $state) {
            /**
             * @var $entity Entity
             */
            $entity->restoreState($state, $this->metadata->read($entity::class));
        }
        $snapshot = $this->txnCacheSnapshots[$context] ?? null;
        if ($snapshot !== null) {
            $this->cache->restoreSnapshot($snapshot);
        }
        $cachedKeys = $snapshot === null ? [] : array_fill_keys(array_keys($snapshot['items']), true);
        foreach ($this->txnStatesByContext[$context] ?? [] as $entity => $state) {
            if ($state['new']) {
                continue;
            }
            $meta = $this->metadata->read($entity::class);
            $parts = $this->identityParts($entity, $meta);
            if (!isset($cachedKeys[$this->cache->setKey($entity, $parts)])) {
                $this->cache->set($entity, $parts);
            }
        }
    }

    private function rememberCachedEntities(): void
    {
        foreach ($this->cache->snapshot() as $entity) {
            $this->rememberEntity($entity);
        }
    }

    /** @return array{items: array<string, Entity>, identities: array<string, array<string, int|float|string|bool|null>>, entities: list<Entity>} */
    private function captureTransactionCache(): array
    {
        /** @var array{items: array<string, Entity>, identities: array<string, array<string, int|float|string|bool|null>>, entities: list<Entity>} $snapshot */
        $snapshot = $this->cache->transactionSnapshot();
        $snapshot['entities'] = array_values(array_merge($snapshot['items'], $this->cache->known()));

        return $snapshot;
    }

    /** @return \WeakMap<Entity, array{new: bool, original: array<string, mixed>, values: array<string, mixed>}> */
    private function newTransactionStates(): \WeakMap
    {
        /** @var \WeakMap<Entity, array{new: bool, original: array<string, mixed>, values: array<string, mixed>}> $states */
        $states = new \WeakMap();

        return $states;
    }

    public function rememberEntity(Entity $entity, ?Context $transactionContext = null): void
    {
        if ($this->restoringTransaction) {
            return;
        }
        $context = $transactionContext ?? Session::context();
        if (!($this->inTransactionByContext[$context] ?? false)) {
            return;
        }
        /** @var \WeakMap<Entity, array{new: bool, original: array<string, mixed>, values: array<string, mixed>}> $states */
        $states = $this->txnStatesByContext[$context] ?? $this->newTransactionStates();
        if ($this->connection instanceof ManagedConnection && !isset($states[$entity])
            && !isset($this->outerTransactionStates[$entity])) {
            $this->outerTransactionStates[$entity] = $this->captureOuterState($entity, $context);
        }
        if (isset($states[$entity])) {
            if ($entity->isNew()) {
                $this->pendingInsertStates ??= new \WeakMap();
                $this->pendingInsertStates[$entity] = $states[$entity];
            }

            return;
        }
        $meta = $this->metadata->read($entity::class);
        $pending = $this->txnNewIdentities[$context] ?? [];
        $identities = $this->txnIdentityStates[$context] ?? [];
        $key = $this->completeIdentityKey($entity, $meta);
        if ($entity->isNew() || $key === null || !isset($pending[$key])) {
            if (!$entity->isNew()) {
                $identities[$key] ??= $entity->captureState($meta);
                $this->txnIdentityStates[$context] = $identities;
                $states[$entity] = $identities[$key];
            } else {
                $states[$entity] = $entity->captureState($meta);
                $this->pendingInsertStates ??= new \WeakMap();
                $this->pendingInsertStates[$entity] = $states[$entity];
            }
            $this->txnStatesByContext[$context] = $states;

            return;
        }
        $states[$entity] = $identities[$key] ?? ['new' => true, 'original' => [], 'values' => $entity->captureState($meta)['values']];
        $this->txnStatesByContext[$context] = $states;
    }

    /** @return EntityState */
    private function captureOuterState(Entity $entity, Context $context): array
    {
        $state = $entity->captureState($this->metadata->read($entity::class));
        $states = $this->txnStatesByContext[$context] ?? $this->newTransactionStates();
        if (isset($states[$entity])) {
            return $states[$entity];
        }
        if ($state['new']) {
            return $state;
        }
        $meta = $this->metadata->read($entity::class);
        $identity = $this->completeIdentityKey($entity, $meta);
        $identityState = $identity === null ? null : ($this->txnIdentityStates[$context][$identity] ?? null);
        $history = $identity === null ? null : ($this->txnNewIdentities[$context][$identity] ?? null);
        if ($history !== null && end($history) === null && isset($this->pendingInsertStates[$entity])) {
            return $this->pendingInsertStates[$entity];
        }
        if ($identityState !== null) {
            return $identityState;
        }
        // 事务内插入后再次查询会产生另一个 PHP 实例；它自己的 original
        // 只能描述事务内的新行，不能覆盖同主键插入历史中的回滚基线。
        if ($history !== null) {
            return ['new' => true, 'original' => [], 'values' => $state['values']];
        }
        if (isset($this->pendingInsertStates[$entity])) {
            return $this->pendingInsertStates[$entity];
        }
        // 首次 rememberEntity() 常发生在业务属性已赋值、SQL 尚未执行时。
        // 此时原持久化值在 original 中，而不是当前属性中。
        $original = $entity->takeOriginal();
        foreach ($meta->fields as $field) {
            if (!$field->primary && array_key_exists($field->property, $original)) {
                $state['values'][$field->property] = $original[$field->property];
            }
        }

        return $state;
    }

    /** @return array<string, int|float|string|bool|null> */
    private function identityParts(Entity $entity, ModelMeta $meta): array
    {
        $parts = [];
        foreach ($meta->primaryKey as $field) {
            if (!$entity->propSet($field->property)) {
                throw new IdentityException($meta->class . ' 缺少主键列 ' . $field->column);
            }
            $value = $entity->{$field->property};
            if ($value === null) {
                throw new IdentityException($meta->class . ' 主键列 ' . $field->column . ' 不可空');
            }
            $parts[$field->column] = $this->caster->toDb($meta->class, $field, $value);
        }

        return $parts;
    }

    private function completeIdentityKey(Entity $entity, ModelMeta $meta): ?string
    {
        try {
            return RowCache::key($entity::class, $this->identityParts($entity, $meta));
        } catch (IdentityException) {
            return null;
        }
    }

    private function observeTransaction(string $phase, Context $context, float $started, ?\Throwable $exception = null): void
    {
        $this->executor->dispatchObserved(fn () => $this->events->dispatch(new TransactionEvent(
            $phase,
            $this->executor->contextInfo($context),
            max(0.0, $this->clock->now() - $started),
            $exception === null,
            $exception === null ? null : $exception::class,
        )));
    }
}
