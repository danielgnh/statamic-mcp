<?php

namespace Danielgnh\StatamicMcp\Console;

use Danielgnh\StatamicMcp\Support\GuidelineFiles;
use Danielgnh\StatamicMcp\Support\Sets;
use Illuminate\Console\Command;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Statamic\Console\RunsInPlease;
use Statamic\Facades\Collection;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Taxonomy;
use Statamic\Fields\Blueprint;
use Statamic\Fields\Field;

/**
 * Creates the guideline files agents read (never overwriting one) and lists
 * page builder blocks without instructions, which agents only know by name
 * until they look one up. Only resources exposed in statamic.mcp.resources
 * count.
 */
class Guidelines extends Command
{
    use RunsInPlease;

    protected $signature = 'statamic:mcp:guidelines';

    protected $description = 'Create guideline files for AI agents and list page builder blocks without instructions';

    public function handle(GuidelineFiles $files): int
    {
        $this->createStubs($files);

        $this->line('');

        $this->reportBlocks();

        return self::SUCCESS;
    }

    protected function createStubs(GuidelineFiles $files): void
    {
        $stubs = collect(['site.md' => $this->siteStub()]);

        foreach ($this->exposed('collections', Collection::handles()->all()) as $handle) {
            $stubs->put("collections/{$handle}.md", $this->collectionStub($handle));
        }

        $created = $stubs->reject(fn (string $stub, string $file) => File::exists($files->path($file)));

        foreach ($created as $file => $stub) {
            File::ensureDirectoryExists(dirname($files->path($file)));
            File::put($files->path($file), $stub);

            $this->line('  <info>Created</info>  '.Str::after($files->path($file), base_path().'/'));
        }

        if ($created->isEmpty()) {
            $this->line('  Guideline files already exist in '.Str::after($files->path(), base_path().'/').'.');
        }
    }

    protected function reportBlocks(): void
    {
        $blocks = $this->blueprints()
            ->flatMap(fn (Blueprint $blueprint) => collect($this->blocksIn($blueprint->fields()->all()))
                ->map(fn (array $block) => [...$block, 'blueprint' => (string) $blueprint->fullyQualifiedHandle()]))
            ->reject(fn (array $block) => $block['hidden']);

        $total = $blocks->unique(fn (array $block) => $block['key'])->count();

        if ($total === 0) {
            $this->line('  No page builder blocks found.');

            return;
        }

        $missing = $blocks
            ->reject(fn (array $block) => filled($block['instructions']))
            ->groupBy('key')
            ->map(fn (SupportCollection $found) => [
                data_get($found->first(), 'handle'),
                data_get($found->first(), 'field'),
                $found->pluck('blueprint')->unique()->sort()->implode(', '),
            ]);

        if ($missing->isEmpty()) {
            $this->info("  All {$total} blocks have instructions.");

            return;
        }

        $this->line(sprintf(
            '  %d of %d blocks have instructions. Agents see only the name of these until they look one up, so add instructions to each set in its blueprint or fieldset:',
            $total - $missing->count(),
            $total,
        ));

        $this->table(['Block', 'Field', 'Blueprints'], $missing->values()->all());
    }

    /**
     * Every set in these fields, including sets nested inside other sets.
     *
     * @param  SupportCollection<string, Field>  $fields
     * @return list<array{key: string, handle: string, field: string, instructions: ?string, hidden: bool}>
     */
    protected function blocksIn(SupportCollection $fields, string $prefix = ''): array
    {
        $blocks = [];

        foreach ($fields as $field) {
            $path = $prefix.$field->handle();

            foreach (Sets::of($field) as $set) {
                $blocks[] = [
                    'key' => "{$path}.{$set['handle']}",
                    'handle' => $set['handle'],
                    'field' => $path,
                    'instructions' => $set['instructions'],
                    'hidden' => $set['hidden'],
                ];

                array_push($blocks, ...$this->blocksIn($set['fields']->all(), "{$path}.{$set['handle']}."));
            }
        }

        return $blocks;
    }

    /**
     * @return SupportCollection<int, Blueprint>
     */
    protected function blueprints(): SupportCollection
    {
        $blueprints = [];

        foreach ($this->exposed('collections', Collection::handles()->all()) as $handle) {
            foreach (Collection::findByHandle($handle)?->entryBlueprints() ?? [] as $blueprint) {
                $blueprints[] = $blueprint;
            }
        }

        foreach ($this->exposed('taxonomies', Taxonomy::handles()->all()) as $handle) {
            foreach (Taxonomy::findByHandle($handle)?->termBlueprints() ?? [] as $blueprint) {
                $blueprints[] = $blueprint;
            }
        }

        foreach ($this->exposed('globals', GlobalSet::all()->map->handle()->all()) as $handle) {
            $blueprints[] = GlobalSet::findByHandle($handle)?->blueprint();
        }

        return collect($blueprints)->filter()->values();
    }

    /**
     * The same rule as Tool::exposedHandles(): true exposes every handle, a
     * list exposes those, anything else exposes nothing.
     *
     * @param  array<int, string>  $all
     * @return list<string>
     */
    protected function exposed(string $type, array $all): array
    {
        $configured = config("statamic.mcp.resources.{$type}", false);

        if ($configured === true) {
            return array_values($all);
        }

        return is_array($configured) ? array_values(array_intersect($all, $configured)) : [];
    }

    protected function siteStub(): string
    {
        return <<<'MD'
            <!--
            Guidelines for every AI agent working on this site through MCP.
            statamic_overview returns this file, so agents read it first.

            Write plain markdown below this comment: voice and tone, words to
            use or avoid, how formal to be, anything an agent should know
            before it writes for this site.

            Rules for one block belong in that block's instructions in its
            blueprint or fieldset. HTML comments like this one never reach
            an agent, so this file stays silent until you write something.
            -->

            MD;
    }

    protected function collectionStub(string $handle): string
    {
        return <<<MD
            <!--
            Guidelines for AI agents writing {$handle} entries. blueprints_get
            returns this file with every {$handle} blueprint. For one blueprint
            only, create collections/{$handle}/<blueprint>.md next to this file.

            Describe how an entry is put together: which blocks come first,
            which never repeat, how many a page usually has, which existing
            entry is a good example to follow.

            Rules for one block belong in that block's instructions in its
            blueprint or fieldset. HTML comments like this one never reach
            an agent, so this file stays silent until you write something.
            -->

            MD;
    }
}
