<?php

namespace Danielgnh\StatamicMcp\CP;

use Statamic\Facades\CP\Nav;
use Statamic\Facades\User;

class McpNav
{
    public static function register(): void
    {
        Nav::extend(function ($nav) {
            $nav->tools('MCP')
                ->route('mcp.index')
                ->icon('ai-sparks')
                ->can('access mcp')
                ->children(fn () => array_filter([
                    User::current()?->isSuper() ? Nav::item('Guidelines')->route('mcp.guidelines.edit') : null,
                    Nav::item('Connections')->route('mcp.connections.index'),
                ]));
        });
    }
}
