<?php

declare(strict_types=1);

namespace Pixiaoka\Orm\Gateway;

/**
 * 内核需要的最小持久化端口。值只通过参数绑定进入 SQL。
 */
interface Connection
{
    /**
     * @param array<string, int|float|string|bool|null> $params
     */
    public function execute(string $sql, array $params): int;

    /**
     * 零行返回 null。多行不是按身份查找该返回的结果，实现应只取第一行。
     *
     * @param array<string, int|float|string|bool|null> $params
     * @return array<string, mixed>|null
     */
    public function queryOne(string $sql, array $params): ?array;

    /**
     * @param array<string, int|float|string|bool|null> $params
     * @return list<array<string, mixed>>
     */
    public function queryAll(string $sql, array $params): array;

    public function lastInsertId(): string;

    /** 引用一个标识符。sqlite/postgres 双引号，mysql 反引号。 */
    public function quoteIdentifier(string $identifier): string;

    /**
     * 连接是否支持 INSERT ... RETURNING（批量插入回填生成列依赖它）。
     * pgsql / sqlite 支持；mysql 不支持，需逐行 lastInsertId。
     */
    public function supportsReturning(): bool;

    public function beginTransaction(): bool;

    public function commit(): bool;

    public function rollback(): bool;
}
