<?php

declare(strict_types=1);

namespace Danielgnh\StatamicMcp\Tests\Support;

use Danielgnh\StatamicMcp\Tools\Tool;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * A host-app tool as the docs describe one: extends the addon base and reads
 * the acting user through it. Registered only by CustomServer.
 */
#[Name('echo_user')]
#[Description('Returns the email of the acting user.')]
#[IsReadOnly]
class EchoUserTool extends Tool
{
    protected function execute(Request $request): Response
    {
        return $this->json(['email' => $this->user($request)->email()]);
    }
}
