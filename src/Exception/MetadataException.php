<?php

declare(strict_types=1);

namespace Pixiaoka\Orm\Exception;

/** 模型声明不合法：缺主键、代理键和自然键混用、类型不支持。 */
final class MetadataException extends OrmException
{
}
