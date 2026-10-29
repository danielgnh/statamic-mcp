<?php

declare(strict_types=1);

namespace Danielgnh\StatamicMcp\Tools;

use Statamic\Contracts\Structures\CollectionTree;

class TreeChangedException extends ToolException
{
    public static function in(CollectionTree $tree, string $reason): self
    {
        return new self("another call changed the tree of collection '{$tree->collection()->handle()}' (site '{$tree->locale()}') after this one checked it: {$reason}");
    }
}
