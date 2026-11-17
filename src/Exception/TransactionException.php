<?php

declare(strict_types=1);

namespace Pixiaoka\Orm\Exception;

/** 开始、提交或回滚结果未知，禁止在当前连接上重试、刷新或继续读写。 */
final class TransactionException extends OrmException
{
    public function __construct(
        public readonly string $operation,
        public readonly ?\Throwable $businessException,
        \Throwable $previous,
    ) {
        $message = '数据库' . $operation . '结果未知，当前连接已禁止复用；请丢弃该连接、Gateway 和关联实体';
        if ($businessException !== null) {
            $message .= '；业务异常 ' . $businessException::class . ' 已由数据库异常取代';
        }

        parent::__construct($message, 0, $previous);
    }
}
