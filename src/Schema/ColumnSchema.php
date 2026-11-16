<?php

declare(strict_types=1);

namespace Pixiaoka\Orm\Schema;

/**
 * 数据库列的语言无关元数据。
 *
 * default 保留驱动返回的 SQL 文本，null 表示没有默认值；
 * primaryOrder 从 1 开始，0 表示该列不是主键。
 * defaultDialect 标记 default 的 SQL 字面量规则；null 表示已解码标量文本。
 * defaultExpression 表示数据库返回的是动态表达式，而不是静态标量默认值。
 * serverEncoding 用于 PostgreSQL 原始字节转义转换；null 时仅安全处理 ASCII/Unicode。
 */
final readonly class ColumnSchema
{
    public function __construct(
        public string $name,
        public string $databaseType,
        public bool $nullable,
        public ?string $default,
        public int $primaryOrder,
        public bool $generated,
        public ?string $defaultDialect = null,
        public bool $defaultExpression = false,
        public ?string $serverEncoding = null,
    ) {
    }

    public function primary(): bool
    {
        return $this->primaryOrder > 0;
    }
}
