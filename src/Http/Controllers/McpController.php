<?php

namespace Danielgnh\StatamicMcp\Http\Controllers;

use Illuminate\Contracts\View\View;
use Inertia\Inertia;
use Statamic\Facades\User;
use Statamic\Http\Controllers\CP\CpController;
use Symfony\Component\HttpFoundation\Response;

class McpController extends CpController
{
    public function index(): View|Response
    {
        return User::current()?->isSuper()
            ? view('statamic-mcp::mcp.index')
            : Inertia::location(cp_route('mcp.connections.index'));
    }
}
