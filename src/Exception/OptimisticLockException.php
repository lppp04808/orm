<?php

declare(strict_types=1);

namespace Pixiaoka\Orm\Exception;

/** 预期版本已变化，或目标行已不存在；本次实体写入没有成功。 */
final class OptimisticLockException extends OrmException
{
    /**
     * @param class-string $model
     */
    public function __construct(
        public readonly string $model,
        public readonly string $operation,
        public readonly int $expectedVersion,
    ) {
        parent::__construct(sprintf(
            '%s %s 乐观锁冲突：预期版本 %d 已失效，请 refresh 后重试',
            $model,
            $operation,
            $expectedVersion,
        ));
    }
}
