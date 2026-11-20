<?php

declare(strict_types=1);

namespace Danielgnh\StatamicMcp\Support;

class IconName
{
    public static function leavesIconSet(mixed $name): bool
    {
        return is_string($name) && preg_match('#(^|[\\\\/])\.\.([\\\\/]|$)#', $name) === 1;
    }
}
