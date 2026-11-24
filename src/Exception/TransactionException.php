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
        $message = 'Database transaction ' . $operation . ' outcome is unknown; the connection must not be reused. Discard the connection, Gateway, and associated entities';
        if ($businessException !== null) {
            $message .= '; business exception ' . $businessException::class . ' was superseded by the database exception';
        }

        parent::__construct($message, 0, $previous);
    }
}
