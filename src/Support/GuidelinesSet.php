<?php

namespace Danielgnh\StatamicMcp\Support;

use Illuminate\Support\Collection as SupportCollection;
use Statamic\Facades\Blueprint;
use Statamic\Facades\GlobalSet;

/**
 * Guidelines for agents live in a global set, so the people who run the site
 * write them in the Control Panel and an agent may through globals_update.
 * Its site field holds voice and tone for every agent; each row of resources
 * names collections and taxonomies and says how their entries are put
 * together. Values come from the set's first site.
 */
class GuidelinesSet
{
    public function handle(): string
    {
        return config()->string('statamic.mcp.guidelines', 'guidelines');
    }

    public function site(): ?string
    {
        return $this->text($this->data()->get('site'));
    }

    /**
     * The rows naming this resource, in their order.
     *
     * @param  'collections'|'taxonomies'|'globals'  $type
     */
    public function for(string $type, string $handle): ?string
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = data_get($this->data()->all(), 'resources', []);

        $texts = collect($rows)
            ->filter(fn (array $row) => in_array($handle, (array) data_get($row, $type), true))
            ->map(fn (array $row) => $this->text(data_get($row, 'guidelines')))
            ->filter();

        return $texts->isEmpty() ? null : $texts->implode("\n\n");
    }

    /**
     * Creates the set with its blueprint, unless the site has one already.
     */
    public function create(): bool
    {
        if (GlobalSet::find($this->handle()) !== null) {
            return false;
        }

        Blueprint::make($this->handle())->setNamespace('globals')->setContents($this->blueprint())->save();

        GlobalSet::make($this->handle())->title('Guidelines')->save();

        return true;
    }

    /**
     * @return SupportCollection<string, mixed>
     */
    private function data(): SupportCollection
    {
        if (($set = GlobalSet::find($this->handle())) === null) {
            return collect();
        }

        return $set->in((string) $set->sites()->first())?->data() ?? collect();
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && filled(trim($value)) ? trim($value) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function blueprint(): array
    {
        return [
            'title' => 'Guidelines',
            'tabs' => [
                'main' => [
                    'display' => 'Main',
                    'sections' => [
                        [
                            'fields' => [
                                ['handle' => 'site', 'field' => [
                                    'type' => 'markdown',
                                    'display' => 'Site',
                                    'instructions' => 'For every agent, in everything it writes: voice and tone, words to use or avoid, how formal to be. Agents read this first, with statamic_overview.',
                                ]],
                                ['handle' => 'resources', 'field' => [
                                    'type' => 'replicator',
                                    'display' => 'Collections and taxonomies',
                                    'instructions' => 'How an entry or term is put together: which blocks open it, which never repeat, how many a page usually has, which existing entry to follow. An agent gets a row with every blueprint of the collections and taxonomies it names, through blueprints_get. A rule about one field or block belongs in that blueprint instead.',
                                    'button_label' => 'Add guidelines',
                                    'sets' => [
                                        'main' => [
                                            'display' => 'Main',
                                            'sets' => [
                                                'resource' => [
                                                    'display' => 'Guidelines',
                                                    'instructions' => 'One row per group of collections and taxonomies that share the same rules.',
                                                    'fields' => [
                                                        ['handle' => 'collections', 'field' => ['type' => 'collections', 'display' => 'Collections', 'mode' => 'select', 'width' => 50]],
                                                        ['handle' => 'taxonomies', 'field' => ['type' => 'taxonomies', 'display' => 'Taxonomies', 'mode' => 'select', 'width' => 50]],
                                                        ['handle' => 'guidelines', 'field' => ['type' => 'markdown', 'display' => 'Guidelines', 'validate' => ['required']]],
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                ]],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
