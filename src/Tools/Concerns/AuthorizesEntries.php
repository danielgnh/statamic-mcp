<?php

namespace Danielgnh\StatamicMcp\Tools\Concerns;

use Danielgnh\StatamicMcp\Tools\ToolException;
use Illuminate\Support\Arr;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Fields\Blueprint;

/**
 * The author rules of Statamic's EntryPolicy and EntriesController. On a
 * blueprint with an author field, an entry the user is not an author of,
 * including one with no author, needs the "other authors" variant of the
 * permission. Only that permission lets a user name anyone but themselves
 * as author.
 */
trait AuthorizesEntries
{
    /**
     * @param  'edit'|'publish'|'delete'  $ability
     */
    protected function ensureEntryPermission(UserContract $user, string $ability, EntryContract $entry): void
    {
        $collection = $entry->collectionHandle();

        $this->ensurePermission($user, $this->hasAnotherAuthor($user, $entry)
            ? "{$ability} other authors {$collection} entries"
            : "{$ability} {$collection} entries");
    }

    /**
     * CP parity (EntriesController@store): the acting user becomes the author
     * when data names none, so an agent can edit what it creates. The list
     * form is what the CP puts in before validating.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function withAuthor(UserContract $user, Blueprint $blueprint, string $collection, array $data): array
    {
        if (! $blueprint->hasField('author')) {
            return $data;
        }

        if (! array_key_exists('author', $data)) {
            return [...$data, 'author' => [$user->id()]];
        }

        $permission = "edit other authors {$collection} entries";

        if (! $this->sameAuthors(data_get($data, 'author'), [$user->id()]) && ! $this->can($user, $permission)) {
            throw new ToolException(sprintf(
                "author can only be you without '%s' — grant it to a role of %s in the Control Panel, or leave author out",
                $permission,
                $user->email(),
            ));
        }

        return $data;
    }

    /**
     * CP parity (EntriesController@update): without "edit other authors" the
     * author stays as it is.
     *
     * @param  array<string, mixed>  $data
     */
    protected function ensureAuthorUnchanged(UserContract $user, EntryContract $entry, array $data): void
    {
        if (! array_key_exists('author', $data) || $this->sameAuthors(data_get($data, 'author'), $entry->authors()->all())) {
            return;
        }

        $permission = "edit other authors {$entry->collectionHandle()} entries";

        if (! $this->can($user, $permission)) {
            throw new ToolException(sprintf(
                "changing the author requires '%s' — grant it to a role of %s in the Control Panel, or leave author out of data",
                $permission,
                $user->email(),
            ));
        }
    }

    protected function hasAnotherAuthor(UserContract $user, EntryContract $entry): bool
    {
        return $entry->blueprint()->hasField('author')
            && ! $entry->authors()->contains($user->id());
    }

    /**
     * @param  array<int, mixed>  $authors
     */
    protected function sameAuthors(mixed $given, array $authors): bool
    {
        $ids = fn (mixed $value) => collect(Arr::wrap($value))
            ->filter(fn (mixed $id) => (is_string($id) || is_int($id)) && filled($id))
            ->map(fn (string|int $id) => (string) $id)
            ->sort()
            ->values()
            ->all();

        return $ids($given) === $ids($authors);
    }
}
