<?php

namespace Danielgnh\StatamicMcp\Tools;

use Danielgnh\StatamicMcp\Tools\Concerns\ResolvesForms;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Statamic\Contracts\Forms\Form as FormContract;
use Statamic\Contracts\Forms\SubmissionQueryBuilder;
use Statamic\Facades\FormSubmission;
use Statamic\Fields\Field;

#[Name('submissions_list')]
#[Description("List a form's submissions, newest first, each with its id, date, and full data keyed by field handle (blueprints_get with type form describes the fields; a field the visitor did not send is absent from data). search is the Control Panel's search: a substring match over the text, textarea, and integer fields, where % matches any run of characters and _ any single character, and over the date in its stored form, YYYY-MM-DD HH:MM:SS in UTC. To filter by date use since (inclusive) and before (exclusive) instead: a time without an offset is read in the server timezone from statamic_overview, and a date alone means midnight, so before 2026-09-27 covers everything up to the end of the 26th. Paginated: the response carries total and next_page (null on the last page). Submissions are not per site. Nothing edits a submission, as in the Control Panel; submissions_delete removes one when deletes are enabled.")]
#[IsReadOnly]
class SubmissionsList extends Tool
{
    use ResolvesForms;

    #[\Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'form' => $schema->string()->description('Form handle, e.g. "contact" (statamic_overview lists them).')->required(),
            'search' => $schema->string()->description('Substring match over the text, textarea, and integer fields, as in the Control Panel; % and _ are wildcards. Use since and before for dates.'),
            'since' => $schema->string()->description('Only submissions on or after this date, e.g. 2026-09-20 or 2026-09-20T09:00:00+02:00.'),
            'before' => $schema->string()->description('Only submissions before this date (exclusive), e.g. 2026-09-27 for everything up to the end of the 26th.'),
            'limit' => $schema->integer()->description('Page size. Defaults to the server default (25); hard-capped at 100.'),
            'page' => $schema->integer()->default(1)->description('Page number, starting at 1.'),
        ];
    }

    protected function execute(Request $request): Response
    {
        $validated = $request->validate(
            [
                'form' => 'required|string',
                'search' => 'nullable|string',
                'since' => 'nullable|string',
                'before' => 'nullable|string',
                'limit' => 'nullable|integer|min:1',
                'page' => 'nullable|integer|min:1',
            ],
            ['form.required' => 'Pass a form handle, e.g. "contact" — see statamic_overview.'],
        );

        $handle = $validated['form'];

        $form = $this->findExposedForm($handle);

        $this->ensureCanViewSubmissions($this->user($request), $handle);

        // date is what the CP's listing sorts by; ids are microsecond
        // timestamps, so the order is stable across pages.
        $query = FormSubmission::query()
            ->where('form', $handle)
            ->orderBy('date', 'desc');

        if ($search = $validated['search'] ?? null) {
            $this->applySearch($query, $form, $search);
        }

        if ($since = $validated['since'] ?? null) {
            $query->where('date', '>=', $this->parseDate($since, 'since'));
        }

        if ($before = $validated['before'] ?? null) {
            $query->where('date', '<', $this->parseDate($before, 'before'));
        }

        $perPage = min((int) ($validated['limit'] ?? config('statamic.mcp.per_page', 25)), 100);
        $perPage = max($perPage, 1);
        $page = max((int) ($validated['page'] ?? 1), 1);

        $paginated = $query->paginate($perPage, ['*'], 'page', $page);

        return $this->json([
            'form' => $handle,
            'total' => $paginated->total(),
            'page' => $paginated->currentPage(),
            'per_page' => $paginated->perPage(),
            'next_page' => $paginated->hasMorePages() ? $paginated->currentPage() + 1 : null,
            'submissions' => collect((array) $paginated->items())
                ->map(fn ($submission) => $this->submissionPayload($submission))
                ->values()
                ->all(),
            'cp_url' => $form->showUrl(),
        ]);
    }

    /**
     * The Control Panel's search on the submissions listing: the date plus
     * the text, textarea, and integer fields (vendor QueriesFormSubmissionSearch).
     */
    private function applySearch(SubmissionQueryBuilder $query, FormContract $form, string $search): void
    {
        $query->where(function ($query) use ($form, $search) {
            $query->where('date', 'like', '%'.$search.'%');

            $form->blueprint()->fields()->all()
                ->filter(fn (Field $field) => in_array($field->type(), ['text', 'textarea', 'integer'], true))
                ->each(fn (Field $field) => $query->orWhere($field->handle(), 'like', '%'.$search.'%'));
        });
    }

    /**
     * A time without an offset is read in app.timezone, the zone
     * statamic_overview reports as server.timezone.
     */
    private function parseDate(string $date, string $parameter): Carbon
    {
        try {
            return Carbon::parse($date, config('app.timezone'));
        } catch (InvalidArgumentException) {
            throw new ToolException(sprintf("could not parse %s '%s' — use e.g. 2026-09-20 or 2026-09-20T09:00:00+02:00", $parameter, $date));
        }
    }
}
