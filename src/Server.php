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
use Danielgnh\StatamicMcp\Tools\EntriesPublish;
use Danielgnh\StatamicMcp\Tools\EntriesUnpublish;
use Danielgnh\StatamicMcp\Tools\EntriesUpdate;
use Danielgnh\StatamicMcp\Tools\GlobalsGet;
use Danielgnh\StatamicMcp\Tools\GlobalsUpdate;
use Danielgnh\StatamicMcp\Tools\NavigationsGet;
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

/**
 * Host apps extend this class, override tools(), and name the subclass in
 * config('statamic.mcp.server').
 */
#[Name('Statamic')]
#[Instructions('MCP server for this Statamic site. Call statamic_overview first: it returns the sites, collections, taxonomies, global sets, and asset containers you can work with, plus your own permission flags per resource. Before creating or updating content, call blueprints_get for the target blueprint — writes accept raw field data only (never augmented data). Entry creates and updates never publish — they save drafts, or working copies on revision-enabled collections. Going live is a separate call, entries_publish, gated on the collection\'s publish permission. Asset uploads are live immediately — set alt text with assets_update after uploading.')]
class Server extends McpServer
{
    /** @var array<int, class-string<Tool>> */
    protected array $tools = [
        StatamicOverview::class,
        BlueprintsGet::class,
        EntriesList::class,
        EntriesGet::class,
        EntriesCreate::class,
        EntriesUpdate::class,
        EntriesPublish::class,
        EntriesUnpublish::class,
        EntriesDelete::class,
        TermsList::class,
        TermsGet::class,
        TermsCreate::class,
        TermsUpdate::class,
        TermsDelete::class,
        GlobalsGet::class,
        GlobalsUpdate::class,
        NavigationsGet::class,
        AssetsList::class,
        AssetsGet::class,
        AssetsUpload::class,
        AssetsUpdate::class,
        AssetsDelete::class,
    ];

    public int $defaultPaginationLength = 50;

    #[\Override]
    protected function boot(): void
    {
        $registry = new ToolRegistry($this->tools);

        $this->tools($registry);

        $this->tools = $registry->all();
    }

    protected function tools(ToolRegistry $tools): void
    {
        //
    }
}
