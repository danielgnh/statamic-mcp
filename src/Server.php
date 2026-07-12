<?php

namespace Danielgnh\StatamicMcp;

use Laravel\Mcp\Server as McpServer;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('Statamic')]
#[Instructions('MCP server for this Statamic site. Call statamic_overview first: it returns the sites, collections, taxonomies, global sets, and asset containers you can work with, plus your own permission flags per resource. Before creating or updating content, call blueprints_get for the target blueprint — writes accept raw field data only (never augmented data). Writes save drafts by default; publishing requires an explicit published: true and the matching Statamic permission. Asset uploads are live immediately — set alt text with assets_update after uploading.')]
class Server extends McpServer
{
    // The full tool set (19 with deletes enabled) exceeds laravel/mcp's
    // 15-per-page tools/list default, and clients that never send a cursor
    // would silently miss the overflow — advertise everything in one page.
    public int $defaultPaginationLength = 50;

    /** @var array<int, class-string<Tool>> */
    protected array $tools = [
        Tools\StatamicOverview::class,
        Tools\BlueprintsGet::class,
        Tools\EntriesList::class,
        Tools\EntriesGet::class,
        Tools\EntriesCreate::class,
        Tools\EntriesUpdate::class,
        Tools\EntriesDelete::class,
        Tools\TermsList::class,
        Tools\TermsGet::class,
        Tools\TermsCreate::class,
        Tools\TermsUpdate::class,
        Tools\TermsDelete::class,
        Tools\GlobalsGet::class,
        Tools\GlobalsUpdate::class,
        Tools\AssetsList::class,
        Tools\AssetsGet::class,
        Tools\AssetsUpload::class,
        Tools\AssetsUpdate::class,
        Tools\AssetsDelete::class,
    ];
}
