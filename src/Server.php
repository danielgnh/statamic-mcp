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
use Danielgnh\StatamicMcp\Tools\EntriesLocalize;
use Danielgnh\StatamicMcp\Tools\EntriesPreview;
use Danielgnh\StatamicMcp\Tools\EntriesPublish;
use Danielgnh\StatamicMcp\Tools\EntriesUnpublish;
use Danielgnh\StatamicMcp\Tools\EntriesUpdate;
use Danielgnh\StatamicMcp\Tools\GlobalsGet;
use Danielgnh\StatamicMcp\Tools\GlobalsUpdate;
use Danielgnh\StatamicMcp\Tools\NavigationsGet;
use Danielgnh\StatamicMcp\Tools\NavigationsUpdate;
use Danielgnh\StatamicMcp\Tools\StatamicOverview;
use Danielgnh\StatamicMcp\Tools\SubmissionsDelete;
use Danielgnh\StatamicMcp\Tools\SubmissionsGet;
use Danielgnh\StatamicMcp\Tools\SubmissionsList;
use Danielgnh\StatamicMcp\Tools\TermsCreate;
use Danielgnh\StatamicMcp\Tools\TermsDelete;
use Danielgnh\StatamicMcp\Tools\TermsGet;
use Danielgnh\StatamicMcp\Tools\TermsList;
use Danielgnh\StatamicMcp\Tools\TermsUpdate;
use Laravel\Mcp\Server as McpServer;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Skill;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use ReflectionAttribute;
use ReflectionClass;

#[Name('Statamic')]
#[Instructions('MCP server for this Statamic site. Call statamic_overview first: it returns the sites, collections, taxonomies, global sets, asset containers, navigations, and forms you can work with, plus your own permission flags per resource, and the site\'s guidelines for agents when it has them. Before creating or updating content, call blueprints_get for the target blueprint — writes accept raw field data only (never augmented data). It also lists page builder blocks with their instructions, and the collection\'s guidelines when written; follow both. Entry creates and updates never publish — they save drafts, or working copies on revision-enabled collections. Going live is a separate call, entries_publish, gated on the collection\'s publish permission. On multisite an entry belongs to one site: entries_localize adds it to another site as a localization that inherits every field it does not override, and entries_get lists the sites under localizations. To check how a draft or working copy renders, call entries_preview and fetch its URL. To schedule a post, give it a future date and publish it: on collections whose date_behavior.future is private it stays scheduled and Statamic takes it live on that date. Asset uploads are live immediately — set alt text with assets_update after uploading. Form submissions come from submissions_list, newest first with each one\'s full data, and submissions_get; blueprints_get with type form names their fields. Nothing edits a submission, as in the Control Panel.')]
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
        EntriesLocalize::class,
        EntriesPreview::class,
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
        NavigationsUpdate::class,
        AssetsList::class,
        AssetsGet::class,
        AssetsUpload::class,
        AssetsUpdate::class,
        AssetsDelete::class,
        SubmissionsList::class,
        SubmissionsGet::class,
        SubmissionsDelete::class,
    ];

    public int $defaultPaginationLength = 50;

    private const string ADDON_TOOLS = 'statamic-mcp.addon-tools';

    private const string ADDON_SKILLS = 'statamic-mcp.addon-skills';

    /**
     * @param  class-string<Tool>  ...$tools
     */
    public static function registerAddonTools(string ...$tools): void
    {
        app()->instance(self::ADDON_TOOLS, array_values(array_unique([...self::registeredAddonTools(), ...$tools])));
    }

    /**
     * @internal
     *
     * @return list<class-string<Tool>>
     */
    public static function registeredAddonTools(): array
    {
        return app()->bound(self::ADDON_TOOLS) ? app(self::ADDON_TOOLS) : [];
    }

    /**
     * @param  string|class-string<Skill>  ...$skills
     */
    public static function registerAddonSkills(string ...$skills): void
    {
        app()->instance(self::ADDON_SKILLS, array_values(array_unique([...self::registeredAddonSkills(), ...$skills])));
    }

    /**
     * @internal
     *
     * @return list<string|class-string<Skill>>
     */
    public static function registeredAddonSkills(): array
    {
        return app()->bound(self::ADDON_SKILLS) ? app(self::ADDON_SKILLS) : [];
    }

    #[\Override]
    protected function boot(): void
    {
        $this->skills = [
            ...$this->skills,
            ...array_filter(self::registeredAddonSkills(), fn (string $skill) => ! in_array($skill, $this->skills, true)),
        ];

        $addonTools = array_values(array_diff(self::registeredAddonTools(), $this->tools));
        $registry = (new ToolRegistry($this->tools))->add(...$addonTools);

        $this->tools($registry);

        if (config('statamic.mcp.read_only')) {
            $registry->remove(...array_filter($addonTools, fn (string $tool) => ! $this->declaresReadOnly($tool)));
        }

        $this->tools = $registry->all();
        $this->defaultPaginationLength = max($this->defaultPaginationLength, count($this->tools));
        $this->maxPaginationLength = max($this->maxPaginationLength, $this->defaultPaginationLength);
    }

    /**
     * @param  class-string<Tool>  $tool
     */
    private function declaresReadOnly(string $tool): bool
    {
        $attribute = (new ReflectionClass($tool))->getAttributes(IsReadOnly::class, ReflectionAttribute::IS_INSTANCEOF)[0] ?? null;

        return $attribute?->newInstance()->value === true;
    }

    protected function tools(ToolRegistry $tools): void
    {
        //
    }
}
