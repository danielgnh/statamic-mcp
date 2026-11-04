<?php

namespace Danielgnh\StatamicMcp\Http\Controllers;

use Inertia\Inertia;
use Statamic\Facades\User;
use Statamic\Http\Controllers\CP\CpController;
use Symfony\Component\HttpFoundation\Response;

class McpController extends CpController
{
    public function index(): Response
    {
        return Inertia::location(cp_route(User::current()?->isSuper() ? 'mcp.guidelines.edit' : 'mcp.connections.index'));
    }
}
