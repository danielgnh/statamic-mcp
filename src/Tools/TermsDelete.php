<?php

namespace Danielgnh\StatamicMcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Statamic\Facades\Term;

#[Name('terms_delete')]
#[Description("Permanently delete a taxonomy term by id. A term's site localizations are data overrides inside the term itself, so deleting it removes the term from every site at once. Deleting a term that entries still reference is allowed (Control Panel behavior); Statamic's reference updater then strips those references (runs on the queue; skipped when statamic.system.update_references is false). This cannot be undone. Only available when deletes are enabled in config/statamic/mcp.php.")]
#[IsDestructive]
class TermsDelete extends Tool
{
    #[\Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('Term id: "{taxonomy}::{slug}", e.g. "tags::php".')->required(),
        ];
    }

    public function shouldRegister(Request $request): bool
    {
        return $this->deletesEnabled();
    }

    protected function execute(Request $request): Response
    {
        $this->ensureDeletesEnabled();

        $validated = $request->validate(
            ['id' => 'required|string'],
            ['id.required' => 'Pass a term id: "{taxonomy}::{slug}", e.g. "tags::php".'],
        );

        $id = $validated['id'];

        if (! str_contains((string) $id, '::')) {
            throw new ToolException("term ids look like '{taxonomy}::{slug}', e.g. 'tags::php' — got '{$id}'");
        }

        [$taxonomyHandle] = explode('::', (string) $id, 2);

        $this->ensureExposed('taxonomies', $taxonomyHandle);

        $user = $this->user($request);

        // CP parity (vendor TermPolicy::delete): the delete permission alone
        // gates this — no site-access sweep, even though the delete takes
        // every site's view of the term with it. Localizations are data
        // overrides within the one term, not separate entities, so there is
        // no entries-style per-site cascade to authorize either.
        $this->ensurePermission($user, "delete {$taxonomyHandle} terms");

        if (! $term = Term::find($id)) {
            throw new ToolException("term '{$id}' not found — use terms_list with taxonomy '{$taxonomyHandle}' to see available terms");
        }

        $sites = $term->taxonomy()->sites()->all();

        // Reference-cleanup integrity (same fact as the rename path):
        // vendor's UpdateTermReferences listener keys its TermDeleted cleanup
        // off getOriginal('slug'), and a file-hydrated term has no synced
        // original — without this, references in entries would dangle
        // forever. (Term::find returns a LocalizedTerm — dirty state lives on
        // the underlying Term, hence ->term().)
        $term->term()->syncOriginal();

        // delete() returns false when a TermDeleting listener cancels
        // (approval addons do this) — never report success for it, same rule
        // as save(). Vendor never throws here and never blocks in-use terms.
        if (! $term->delete()) {
            throw new ToolException('the delete was cancelled by a listener — the term was not deleted');
        }

        // Outcome statement only — deliberately NO cp_edit_url: the deleted
        // term's CP page would 404 (amended spec exception).
        return $this->json([
            'deleted' => true,
            'id' => $id,
            'taxonomy' => $taxonomyHandle,
            'slug' => $term->slug(),
            'sites' => $sites,
            'result' => 'term permanently deleted from every site listed (localizations are data overrides within the term — none survive) — this cannot be undone',
            'note' => "references to this term in entry fields are removed by Statamic's reference updater (runs on the queue; skipped when statamic.system.update_references is false) — an immediate re-read may still show the slug in entry fields; do not rewrite references manually",
        ]);
    }
}
