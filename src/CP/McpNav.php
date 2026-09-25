<?php

namespace Danielgnh\StatamicMcp\CP;

use Statamic\Facades\CP\Nav;

/**
 * Tools → MCP for everyone with Access MCP, with its pages listed under it
 * the way Utilities lists its own.
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
                ->children(fn () => [
                    Nav::item('Connections')->route('mcp.connections.index'),
                ]);
        });
    }
}
