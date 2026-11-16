<?php

declare(strict_types=1);

namespace Pixiaoka\Orm\Exception;

/**
 * 身份不完整或用法不对。
 * 行可能已经写入（代理键回填失败）时，rowMayExist 为 true。
 */
final class IdentityException extends OrmException
{
    public function __construct(
        string $message,
        public readonly bool $rowMayExist = false,
    ) {
        parent::__construct($message);
    }
}
