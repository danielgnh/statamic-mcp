<?php

namespace Danielgnh\StatamicMcp\Tools;

use Danielgnh\StatamicMcp\Tools\Concerns\ResolvesForms;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('submissions_delete')]
#[Description("Permanently delete one form submission by form handle and submission id. Needs 'delete {form} form submissions', or 'configure forms'. Files the submission uploaded stay in their asset container, as when deleting in the Control Panel. This cannot be undone.")]
#[IsDestructive]
class SubmissionsDelete extends Tool
{
    use ResolvesForms;

    #[\Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'form' => $schema->string()->description('Form handle, e.g. "contact".')->required(),
            'id' => $schema->string()->description('Submission id from submissions_list.')->required(),
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
            [
                'form' => 'required|string',
                'id' => 'required|string',
            ],
            [
                'form.required' => 'Pass a form handle, e.g. "contact" — see statamic_overview.',
                'id.required' => 'Pass a submission id from submissions_list.',
            ],
        );

        $handle = $validated['form'];

        $form = $this->findExposedForm($handle);

        $this->ensureCanDeleteSubmissions($this->user($request), $handle);

        $submission = $this->findSubmission($form, $validated['id']);

        $payload = $this->submissionPayload($submission);

        // Vendor Submission::delete() always returns true: there is no
        // SubmissionDeleting event a listener could cancel it with.
        $submission->delete();

        // Outcome statement only — no cp_url: the deleted submission's CP
        // page would 404.
        return $this->json([
            'deleted' => true,
            'form' => $handle,
            'id' => $payload['id'],
            'date' => $payload['date'],
            'result' => 'submission permanently deleted — this cannot be undone',
        ]);
    }
}
