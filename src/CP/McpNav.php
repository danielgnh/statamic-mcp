<?php

namespace Danielgnh\StatamicMcp\CP;

use Illuminate\Support\Facades\Route;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\User;

class McpNav
{
    public static function register(): void
    {
        Nav::extend(function ($nav) {
            if (! Route::has('statamic.cp.mcp.index')) {
                return;
            }

            $nav->tools('MCP')
                ->route('mcp.index')
                ->icon('ai-sparks')
                ->can('access mcp')
                ->children(fn () => array_filter([
                    Nav::item('Connections')->route('mcp.connections.index'),
                    User::current()?->isSuper() ? Nav::item('Guidelines')->route('mcp.guidelines.edit') : null,
                ]));
        });
    }
}
