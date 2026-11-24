<?php

declare(strict_types=1);

namespace Pixiaoka\Orm\Annotation;

use Attribute;

/**
 * DateTimeImmutable 的落库形式。默认 UTC 文本，时间戳必须显式声明。
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class Temporal
{
    public const string TEXT = 'text';
    public const string DATE = 'date';
    public const string TEXT_US = 'text_us';
    public const string UNIX = 'unix';
    public const string UNIX_MS = 'unix_ms';

    public function __construct(
        public string $storage = self::TEXT,
        public ?string $displayFormat = null,
        public ?string $displayTimezone = null,
    ) {
    }
}
