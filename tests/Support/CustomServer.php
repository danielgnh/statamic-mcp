<?php

declare(strict_types=1);

namespace Danielgnh\StatamicMcp\Tests\Support;

use Danielgnh\StatamicMcp\Server;

/**
 * The README's recipe verbatim: keep the built-in set, add one tool.
 */
class CustomServer extends Server
{
    protected array $tools = [
        ...Server::TOOLS,
        EchoUserTool::class,
    ];
}
