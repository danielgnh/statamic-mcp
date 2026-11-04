<?php

namespace Danielgnh\StatamicMcp\Tools\Concerns;

use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Enumerable;
use Illuminate\Support\Str;
use JsonSerializable;
use Statamic\Contracts\Assets\Asset as AssetContract;
use Statamic\Contracts\Assets\AssetContainer as AssetContainerContract;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Contracts\Data\Augmentable;
use Statamic\Contracts\Entries\Collection as CollectionContract;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Contracts\Forms\Form as FormContract;
use Statamic\Contracts\Forms\Submission as SubmissionContract;
use Statamic\Contracts\Structures\Nav as NavContract;
use Statamic\Contracts\Taxonomies\Taxonomy as TaxonomyContract;
use Statamic\Contracts\Taxonomies\Term as TermContract;
use Statamic\Facades\Compare;
use Statamic\Facades\Data;
use Statamic\Fields\Value;
use Statamic\Fields\Values;
use Statamic\Fieldtypes\AssetContainer;
use Statamic\Fieldtypes\Assets\Assets;
use Statamic\Fieldtypes\Bard;
use Statamic\Fieldtypes\Bard\Augmentor;
use Statamic\Fieldtypes\Collections;
use Statamic\Fieldtypes\Entries;
use Statamic\Fieldtypes\Grid;
use Statamic\Fieldtypes\Group;
use Statamic\Fieldtypes\Link;
use Statamic\Fieldtypes\Link\ArrayableLink;
use Statamic\Fieldtypes\Markdown;
use Statamic\Fieldtypes\Navs;
use Statamic\Fieldtypes\Replicator;
use Statamic\Fieldtypes\RowId;
use Statamic\Fieldtypes\Structures;
use Statamic\Fieldtypes\Taxonomies;
use Statamic\Fieldtypes\Terms;
use Statamic\Fieldtypes\Users;
use Statamic\Forms\Fieldtype as Forms;
use Statamic\Globals\Variables;
use Statamic\Structures\CollectionStructure;
use Statamic\Structures\Page;
use Traversable;
use UnitEnum;

/**
 * @phpstan-type Exposed array{collections: list<string>, taxonomies: list<string>, asset_containers: list<string>, navigations: list<string>, forms: list<string>, globals: list<string>, user_emails: bool}
 */
trait PresentsAugmentedData
{
    private const string STATAMIC_URL = '/([("])statamic:\/\/([^()"?#]*)([^()"]*)([)"])/im';

    /**
     * @param  array<string, mixed>  $augmented
     * @return array<string, mixed>
     */
    private function augmentedData(array $augmented, UserContract $user): array
    {
        $exposed = [
            'collections' => $this->exposedHandles('collections'),
            'taxonomies' => $this->exposedHandles('taxonomies'),
            'asset_containers' => $this->exposedHandles('asset_containers'),
            'navigations' => $this->exposedHandles('navigations'),
            'forms' => $this->exposedHandles('forms'),
            'globals' => $this->exposedHandles('globals'),
            'user_emails' => $this->can($user, 'view users'),
        ];

        return array_map(fn (mixed $value) => $this->presented($value, $exposed), $augmented);
    }

    /**
     * @param  Exposed  $exposed
     */
    private function presented(mixed $value, array $exposed): mixed
    {
        if (! $value instanceof Value) {
            return $this->presentedRelation($value, $exposed);
        }

        $source = $value->augmentable();

        if ($this->isOutsideExposure($source, $exposed) || ($source instanceof UserContract && ! $exposed['user_emails'])) {
            return null;
        }

        $fieldtype = $value->fieldtype();

        return match (true) {
            $fieldtype instanceof Bard => $this->presentedBard($value, $fieldtype, $exposed),
            $fieldtype instanceof Markdown => $this->withRaw($value, $this->withoutHiddenLinks($value->raw(), $exposed))->shallow(),
            $fieldtype instanceof Replicator, $fieldtype instanceof Grid, $fieldtype instanceof Group => $this->presentedSets($value->value(), $exposed),
            $fieldtype instanceof Link => $this->presentedLink($value->value(), $exposed),
            $fieldtype === null,
            $fieldtype instanceof Entries, $fieldtype instanceof Terms, $fieldtype instanceof Assets,
            $fieldtype instanceof Collections, $fieldtype instanceof Taxonomies, $fieldtype instanceof Navs,
            $fieldtype instanceof Structures, $fieldtype instanceof AssetContainer, $fieldtype instanceof Forms,
            $fieldtype instanceof Users => $this->presentedRelation($value->value(), $exposed),
            default => $this->presentedRelation($value->shallow()->value(), $exposed),
        };
    }

    /**
     * @param  Exposed  $exposed
     */
    private function presentedBard(Value $value, Bard $bard, array $exposed): mixed
    {
        $raw = $value->raw();

        if (is_array($raw) && $bard->isLegacyData($raw)) {
            $raw = $this->convertedLegacyData($bard, $raw);
        }

        return $this->presentedSets($this->withRaw($value, $this->withoutHiddenLinks($raw, $exposed))->value(), $exposed);
    }

    /**
     * @param  array<array<string, mixed>>  $legacy
     * @return list<mixed>
     */
    private function convertedLegacyData(Bard $bard, array $legacy): array
    {
        $nodes = [];

        foreach ($legacy as $item) {
            if ($item['type'] !== 'text') {
                $nodes[] = ['type' => 'set', 'attrs' => ['id' => app(RowId::class)->generate(), 'values' => $item]];
            } elseif (! empty($item['text'])) {
                array_push($nodes, ...(new Augmentor($bard))->renderHtmlToProsemirror($item['text'])['content']);
            }
        }

        return $nodes;
    }

    /**
     * @param  Exposed  $exposed
     */
    private function presentedRelation(mixed $items, array $exposed): mixed
    {
        if ($items instanceof Value) {
            return $this->presented($items, $exposed);
        }

        if ($items instanceof Values) {
            return $this->presentedSets($items, $exposed);
        }

        if ($this->isOutsideExposure($items, $exposed)) {
            return null;
        }

        if (Compare::isQueryBuilder($items)) {
            $items = $items->get();
        }

        $items = match (true) {
            $items instanceof Paginator => $items->items(),
            $items instanceof Enumerable => $items->all(),
            $items instanceof Augmentable, $items instanceof UnitEnum => $items,
            $items instanceof JsonSerializable => $items->jsonSerialize(),
            $items instanceof Arrayable => $items->toArray(),
            $items instanceof Traversable => iterator_to_array($items),
            is_object($items) => get_object_vars($items),
            default => $items,
        };

        if (! is_array($items)) {
            return $this->stub($items, $exposed);
        }

        $presented = array_map(
            fn (mixed $item) => $this->presentedRelation($item, $exposed),
            array_filter($items, fn (mixed $item) => ! $this->isOutsideExposure($item, $exposed)),
        );

        return array_is_list($items) ? array_values($presented) : $presented;
    }

    /**
     * @param  Exposed  $exposed
     */
    private function presentedLink(ArrayableLink $link, array $exposed): ArrayableLink
    {
        return $this->isOutsideExposure($link->value(), $exposed) ? new ArrayableLink(null) : $link;
    }

    /**
     * @param  Exposed  $exposed
     */
    private function presentedSets(mixed $value, array $exposed): mixed
    {
        return match (true) {
            $value instanceof Values => array_map(fn (mixed $field) => $this->presented($field, $exposed), $value->all()),
            is_array($value) => array_map(fn (mixed $set) => $this->presentedSets($set, $exposed), $value),
            default => $value,
        };
    }

    /**
     * @param  Exposed  $exposed
     */
    private function withoutHiddenLinks(mixed $raw, array $exposed): mixed
    {
        return match (true) {
            is_string($raw) => preg_replace_callback(
                self::STATAMIC_URL,
                fn (array $match) => $this->isOutsideExposure(Data::find($match[2]), $exposed) ? $match[1].$match[4] : $match[0],
                $raw,
            ),
            is_array($raw) => $this->withoutHiddenNodeLinks($raw, $exposed),
            default => $raw,
        };
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @param  Exposed  $exposed
     * @return array<array-key, mixed>
     */
    private function withoutHiddenNodeLinks(array $node, array $exposed): array
    {
        if (($node['type'] ?? null) === 'set') {
            return $node;
        }

        foreach ($node as $key => $item) {
            $node[$key] = match (true) {
                $key === 'href' && is_string($item) && str_starts_with($item, 'statamic://') => $this->isOutsideExposure($this->linkTarget($item), $exposed) ? '' : $item,
                $key === 'src' && is_string($item) && str_starts_with($item, 'asset::') => $this->inExposedContainer($item, $exposed) ? $item : null,
                is_array($item) => $this->withoutHiddenNodeLinks($item, $exposed),
                default => $item,
            };
        }

        return $node;
    }

    /**
     * @param  Exposed  $exposed
     */
    private function inExposedContainer(string $src, array $exposed): bool
    {
        return in_array(Str::before(Str::after($src, 'asset::'), '::'), $exposed['asset_containers'], true);
    }

    private function linkTarget(string $href): mixed
    {
        return Data::find(Str::before(Str::before(Str::after($href, 'statamic://'), '?'), '#'));
    }

    private function withRaw(Value $value, mixed $raw): Value
    {
        return new Value($raw, $value->handle(), $value->fieldtype(), $value->augmentable());
    }

    /**
     * @param  Exposed  $exposed
     */
    private function stub(mixed $item, array $exposed): mixed
    {
        return match (true) {
            $item instanceof UserContract && ! $exposed['user_emails'] => $item->toShallowAugmentedCollection()->except('email'),
            $item instanceof EntryContract, $item instanceof TermContract, $item instanceof AssetContract,
            $item instanceof FormContract, $item instanceof UserContract,
            $item instanceof Variables, $item instanceof SubmissionContract => $item->toShallowAugmentedCollection(),
            default => $item,
        };
    }

    /**
     * @return array{0: 'collections'|'taxonomies'|'asset_containers'|'navigations'|'forms'|'globals', 1: string}|null
     */
    private function exposureOf(mixed $value): ?array
    {
        return match (true) {
            $value instanceof Page && ! $value->entry() => null,
            $value instanceof EntryContract => ['collections', $value->collectionHandle()],
            $value instanceof TermContract => ['taxonomies', $value->taxonomyHandle()],
            $value instanceof AssetContract => ['asset_containers', $value->containerHandle()],
            $value instanceof CollectionContract, $value instanceof CollectionStructure => ['collections', $value->handle()],
            $value instanceof TaxonomyContract => ['taxonomies', $value->handle()],
            $value instanceof AssetContainerContract => ['asset_containers', $value->handle()],
            $value instanceof NavContract => ['navigations', $value->handle()],
            $value instanceof FormContract => ['forms', $value->handle()],
            $value instanceof SubmissionContract => ['forms', $value->form()->handle()],
            $value instanceof Variables => ['globals', $value->handle()],
            default => null,
        };
    }

    /**
     * @param  Exposed  $exposed
     */
    private function isOutsideExposure(mixed $value, array $exposed): bool
    {
        $exposure = $this->exposureOf($value);

        return $exposure !== null && ! in_array($exposure[1], $exposed[$exposure[0]], true);
    }
}
