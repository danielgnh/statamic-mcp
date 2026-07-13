<?php

declare(strict_types=1);

namespace Danielgnh\StatamicMcp;

use Danielgnh\StatamicMcp\Tools\AssetsDelete;
use Danielgnh\StatamicMcp\Tools\AssetsGet;
use Danielgnh\StatamicMcp\Tools\AssetsList;
use Danielgnh\StatamicMcp\Tools\AssetsUpdate;
use Danielgnh\StatamicMcp\Tools\AssetsUpload;
use Danielgnh\StatamicMcp\Tools\BlueprintsGet;
use Danielgnh\StatamicMcp\Tools\EntriesCreate;
use Danielgnh\StatamicMcp\Tools\EntriesDelete;
use Danielgnh\StatamicMcp\Tools\EntriesGet;
use Danielgnh\StatamicMcp\Tools\EntriesList;
use Danielgnh\StatamicMcp\Tools\EntriesUpdate;
use Danielgnh\StatamicMcp\Tools\GlobalsGet;
use Danielgnh\StatamicMcp\Tools\GlobalsUpdate;
use Danielgnh\StatamicMcp\Tools\StatamicOverview;
use Danielgnh\StatamicMcp\Tools\TermsCreate;
use Danielgnh\StatamicMcp\Tools\TermsDelete;
use Danielgnh\StatamicMcp\Tools\TermsGet;
use Danielgnh\StatamicMcp\Tools\TermsList;
use Danielgnh\StatamicMcp\Tools\TermsUpdate;
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
        StatamicOverview::class,
        BlueprintsGet::class,
        EntriesList::class,
        EntriesGet::class,
        EntriesCreate::class,
        EntriesUpdate::class,
        EntriesDelete::class,
        TermsList::class,
        TermsGet::class,
        TermsCreate::class,
        TermsUpdate::class,
        TermsDelete::class,
        GlobalsGet::class,
        GlobalsUpdate::class,
        AssetsList::class,
        AssetsGet::class,
        AssetsUpload::class,
        AssetsUpdate::class,
        AssetsDelete::class,
    ];
}
