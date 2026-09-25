<?php

namespace Danielgnh\StatamicMcp\Tools\Concerns;

use Danielgnh\StatamicMcp\Tools\ToolException;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Fields\Blueprint;

/**
 * An entry of a collection in several sites has one localization per site,
 * each inheriting from its origin every field it does not override.
 * Requires ResolvesSites (canAccessSite()).
 */
trait LocalizesEntries
{
    /**
     * The entry's localization in each site of its collection the user may
     * access, null where there is none. Null on a collection in one site,
     * so single-site responses stay as they were.
     *
     * @return array<string, array{id: string, status: string}|null>|null
     */
    protected function localizations(UserContract $user, EntryContract $entry): ?array
    {
        $sites = $entry->collection()->sites();

        if ($sites->count() < 2) {
            return null;
        }

        return $sites
            ->filter(fn (string $site) => $this->canAccessSite($user, $site))
            ->mapWithKeys(function (string $site) use ($entry) {
                $localization = $entry->in($site);

                return [$site => $localization ? ['id' => $localization->id(), 'status' => $localization->status()] : null];
            })
            ->all();
    }

    /**
     * CP parity: a localization holds its own value only for a field marked
     * localizable. Every other field is read-only there and shows the
     * origin's value, so an override the CP can neither show nor undo is
     * refused.
     *
     * @param  array<string, mixed>  $data
     */
    protected function rejectUnlocalizableFields(EntryContract $origin, Blueprint $blueprint, array $data): void
    {
        $fields = collect(array_keys($data))
            ->reject(fn (int|string $handle) => $blueprint->field((string) $handle)?->isLocalizable())
            ->values();

        if ($fields->isEmpty()) {
            return;
        }

        throw new ToolException(sprintf(
            $fields->count() === 1
                ? "field %s is not localizable, so every site shows the value of its origin entry '%s' — change it there, or turn on Localizable for the field in blueprint '%s' to translate it here"
                : "fields %s are not localizable, so every site shows the values of their origin entry '%s' — change them there, or turn on Localizable for the fields in blueprint '%s' to translate them here",
            $fields->implode(', '),
            $origin->id(),
            $blueprint->handle(),
        ));
    }
}
