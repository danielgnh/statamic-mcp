<?php

namespace Danielgnh\StatamicMcp;

use Laravel\Mcp\Server as McpServer;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('Statamic')]
#[Instructions('MCP server for this Statamic site. Call statamic_overview first: it returns the sites, collections, taxonomies, and global sets you can work with, plus your own permission flags per resource. Before creating or updating content, call blueprints_get for the target blueprint — writes accept raw field data only (never augmented data). Writes save drafts by default; publishing requires an explicit published: true and the matching Statamic permission.')]
class Server extends McpServer
{
    /** @var array<int, class-string<Tool>> */
    protected array $tools = [
        Tools\StatamicOverview::class,
        Tools\BlueprintsGet::class,
    ];
}
