<?php

declare(strict_types=1);

namespace Danielgnh\StatamicMcp\Tests\Support\Addon;

use Danielgnh\StatamicMcp\Server;
use Illuminate\Support\ServiceProvider;

class SecondAddonProvider extends ServiceProvider
{
    public function boot(): void
    {
        Server::addTools(AddonWriteTool::class, AddonReadTool::class);
    }
}
