<?php

namespace Danielgnh\StatamicMcp\CP;

use Statamic\Facades\CP\Nav;
use Statamic\Facades\User;

/**
 * Tools → MCP for everyone with Access MCP, with its pages listed under it
 * the way Utilities lists its own. Guidelines is for super admins only.
 */
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
