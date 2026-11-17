<?php

declare(strict_types=1);

namespace Danielgnh\StatamicMcp\Tests\Support\Addon;

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\ToolRegistry;

class SiteServer extends Server
{
    public int $defaultPaginationLength = 10;

    #[\Override]
    protected function tools(ToolRegistry $tools): void
    {
        $tools->remove(AddonNotReadOnlyTool::class);
        $tools->replace(AddonWriteTool::class, SiteWriteTool::class);
    }
}
