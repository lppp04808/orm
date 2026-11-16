<?php

declare(strict_types=1);

namespace Pixiaoka\Orm\Exception;

/** 转换失败。不得被调用方当成零值成功。 */
final class CastException extends OrmException
{
    public function __construct(
        string $message,
        public readonly string $model,
        public readonly string $property,
    ) {
        parent::__construct($message);
    }
}
