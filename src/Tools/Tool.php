<?php

namespace Danielgnh\StatamicMcp\Tools;

use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool as BaseTool;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Collection;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\User;
use Statamic\Globals\Variables;
use Statamic\Taxonomies\LocalizedTerm;

abstract class Tool extends BaseTool
{
    public const LIVENESS_DRAFT = 'saved as draft — not live';

    public const LIVENESS_PUBLISHED = 'published';

    public const LIVENESS_WORKING_COPY = 'working copy created — live entry unchanged';

    public const LIVENESS_LIVE = 'updated — live'; // terms/globals have no draft state

    public const LIVENESS_CREATED = 'created — live'; // used later by terms_create

    final public function handle(Request $request): Response
    {
        try {
            return $this->execute($request);
        } catch (ToolException $e) {
            return Response::error($e->getMessage());
        }
    }

    abstract protected function execute(Request $request): Response;

    /**
     * The acting Statamic user, mode-agnostic: under Passport $request->user()
     * is the Eloquent model; fromUser() normalizes both (spec §5).
     */
    protected function user(Request $request): UserContract
    {
        return User::fromUser($request->user());
    }

    /**
     * @param  'collections'|'taxonomies'|'globals'  $type
     *
     * Throws when $handle is missing OR exists-but-unexposed — indistinguishable
     * by design (spec §4); the error lists only exposed handles.
     */
    protected function ensureExposed(string $type, string $handle): void
    {
        $exposed = $this->exposedHandles($type);

        if (! in_array($handle, $exposed, true)) {
            throw new ToolException($this->notFoundMessage(Str::singular($type), $handle, $exposed));
        }
    }

    /**
     * @param  'collections'|'taxonomies'|'globals'  $type
     * @return list<string> handles that exist AND pass config('statamic.mcp.resources.{$type}')
     */
    protected function exposedHandles(string $type): array
    {
        $configured = config("statamic.mcp.resources.{$type}", false);

        if ($configured === false || $configured === []) {
            return [];
        }

        $all = match ($type) {
            'collections' => Collection::handles()->all(),
            'taxonomies' => Taxonomy::handles()->all(),
            'globals' => GlobalSet::all()->map->handle()->values()->all(),
        };

        return $configured === true
            ? array_values($all)
            : array_values(array_intersect($all, $configured));
    }

    /**
     * Uniform denial message for every native-permission check (spec §6).
     * Supers auto-pass. Publish/site checks pass their own permission strings.
     */
    protected function ensurePermission(UserContract $user, string $permission): void
    {
        if ($user->isSuper() || $user->hasPermission($permission)) {
            return;
        }

        throw new ToolException(sprintf(
            "requires '%s' — grant it to a role of %s in the Control Panel",
            $permission,
            $user->email(),
        ));
    }

    protected function writesEnabled(): bool
    {
        return ! config('statamic.mcp.read_only');
    }

    protected function deletesEnabled(): bool
    {
        return $this->writesEnabled() && config('statamic.mcp.deletes');
    }

    /**
     * Compact JSON in a text block (spec §8). Response::json() encodes with
     * JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE (verified v0.8.2).
     */
    protected function json(array $data): Response
    {
        return Response::json($data);
    }

    protected function notFound(string $what, string $given, array $available): Response
    {
        return Response::error($this->notFoundMessage($what, $given, $available));
    }

    /**
     * Liveness block appended to every write response (spec §4): pass a
     * LIVENESS_* constant. editUrl() verified on Entry, LocalizedTerm, and
     * Variables in 6.x source.
     */
    protected function liveness(EntryContract|LocalizedTerm|Variables $saved, string $state): array
    {
        return [
            'result' => $state,
            'cp_edit_url' => $saved->editUrl(),
        ];
    }

    private function notFoundMessage(string $what, string $given, array $available): string
    {
        sort($available);

        return sprintf(
            "%s '%s' not found — available: %s",
            $what,
            $given,
            $available === [] ? '(none exposed)' : implode(', ', $available),
        );
    }
}
