<?php

declare(strict_types=1);

namespace Danielgnh\StatamicMcp\Tests\Support;

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\ToolRegistry;
use Danielgnh\StatamicMcp\Tools\AssetsUpload;
use Danielgnh\StatamicMcp\Tools\EntriesList;

/**
 * The README's recipe verbatim: add one tool, replace one, remove one.
 */
class CustomServer extends Server
{
    #[\Override]
    protected function tools(ToolRegistry $tools): void
    {
        $tools->add(EchoUserTool::class);
        $tools->replace(EntriesList::class, DescribedEntriesList::class);
        $tools->remove(AssetsUpload::class);
    }
}
