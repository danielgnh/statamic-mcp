<?php

namespace Danielgnh\StatamicMcp\Console;

use Danielgnh\StatamicMcp\Support\GuidelinesSet;
use Danielgnh\StatamicMcp\Support\Sets;
use Illuminate\Console\Command;
use Illuminate\Support\Collection as SupportCollection;
use Statamic\Console\RunsInPlease;
use Statamic\Facades\Collection;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Taxonomy;
use Statamic\Fields\Blueprint;
use Statamic\Fields\Field;
use Statamic\Fields\Fields;
use Statamic\Fieldtypes\Grid;
use Statamic\Fieldtypes\Group;
use Symfony\Component\Console\Terminal;

/**
 * Creates the guidelines global set agents read (never a second time) and
 * lists page builder blocks without instructions, which agents only know by
 * name until they look one up. Only resources exposed in
 * statamic.mcp.resources count.
 */
class Guidelines extends Command
{
    use RunsInPlease;

    protected $signature = 'statamic:mcp:guidelines';

    protected $description = 'Create the guidelines global set for AI agents and list page builder blocks without instructions';

    public function handle(GuidelinesSet $guidelines): int
    {
        if ($guidelines->create()) {
            $this->line("  <info>Created</info>  the {$guidelines->handle()} global set.");
            $this->line($this->wrap("Open it in the Control Panel under Globals to write the site's voice and how its entries are put together.", 2));
        } else {
            $this->line("  The {$guidelines->handle()} global set already exists.");
        }

        $this->line('');

        $this->reportBlocks($guidelines);

        return self::SUCCESS;
    }

    protected function reportBlocks(GuidelinesSet $guidelines): void
    {
        $blocks = $this->blueprints($guidelines)
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
                'field' => data_get($found->first(), 'field'),
                'handle' => data_get($found->first(), 'handle'),
                'blueprints' => $found->pluck('blueprint')->unique()->sort()->implode(', '),
            ]);

        if ($missing->isEmpty()) {
            $this->info("  All {$total} blocks have instructions.");

            return;
        }

        $this->line($this->wrap(sprintf(
            '%d of %d blocks have instructions. Agents see only the name of the rest until they look one up, so add instructions to each set in its blueprint or fieldset:',
            $total - $missing->count(),
            $total,
        ), 2));

        foreach ($missing->groupBy('blueprints') as $blueprints => $shared) {
            $this->line('');
            $this->line('<comment>'.$this->wrap("In {$blueprints}", 2).'</comment>');

            foreach ($shared->groupBy('field') as $field => $sets) {
                $this->line($this->wrap("{$field}: ".$sets->pluck('handle')->implode(', '), 4, 2));
            }
        }
    }

    /**
     * Wraps text to the terminal width, indenting continuation lines by
     * $hanging more than the first.
     */
    protected function wrap(string $text, int $indent, int $hanging = 0): string
    {
        $width = max(min((new Terminal)->getWidth(), 120) - $indent - $hanging, 40);

        return str_repeat(' ', $indent).implode(
            "\n".str_repeat(' ', $indent + $hanging),
            explode("\n", wordwrap($text, $width)),
        );
    }

    /**
     * Every set in these fields, including sets nested inside other sets,
     * grids, and groups, as blueprints_get finds them.
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
                    'key' => $this->blockKey("{$path}.{$set['handle']}", $set),
                    'handle' => $set['handle'],
                    'field' => $path,
                    'instructions' => $set['instructions'],
                    'hidden' => $set['hidden'],
                ];

                array_push($blocks, ...$this->blocksIn($set['fields']->all(), "{$path}.{$set['handle']}."));
            }

            $fieldtype = $field->fieldtype();

            if ($fieldtype instanceof Grid || $fieldtype instanceof Group) {
                array_push($blocks, ...$this->blocksIn($fieldtype->fields()->all(), "{$path}."));
            }
        }

        return $blocks;
    }

    /**
     * A set that several blueprints share through a fieldset is one block,
     * but its path doesn't say so: unrelated blueprints can each have their
     * own page_builder.hero. The same path with the same definition does.
     *
     * @param  array{display: ?string, instructions: ?string, fields: Fields}  $set
     */
    protected function blockKey(string $path, array $set): string
    {
        return $path.':'.md5(serialize([$set['display'], $set['instructions'], $set['fields']->all()->map->config()->all()]));
    }

    /**
     * The guidelines set itself is not content, so its rows are no blocks.
     *
     * @return SupportCollection<int, Blueprint>
     */
    protected function blueprints(GuidelinesSet $guidelines): SupportCollection
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
            if ($handle !== $guidelines->handle()) {
                $blueprints[] = GlobalSet::findByHandle($handle)?->blueprint();
            }
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
}
