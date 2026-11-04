<?php

declare(strict_types=1);

namespace Danielgnh\StatamicMcp\Support;

class McpRoute
{
    public static function path(): ?string
    {
        $route = config('statamic.mcp.route');

        return is_string($route) && filled($route) ? $route : null;
    }
}
