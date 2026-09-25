<?php

namespace Danielgnh\StatamicMcp\Tools;

use Danielgnh\StatamicMcp\Tools\Concerns\ResolvesForms;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('submissions_get')]
#[Description('Get one form submission by form handle and submission id (ids come from submissions_list or the Control Panel URL). Returns its date and full data keyed by field handle, plus cp_url. Nothing edits a submission, as in the Control Panel.')]
#[IsReadOnly]
class SubmissionsGet extends Tool
{
    use ResolvesForms;

    #[\Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'form' => $schema->string()->description('Form handle, e.g. "contact".')->required(),
            'id' => $schema->string()->description('Submission id, e.g. "1758794400.1234".')->required(),
        ];
    }

    protected function execute(Request $request): Response
    {
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

        $this->ensureCanViewSubmissions($this->user($request), $handle);

        $submission = $this->findSubmission($form, $validated['id']);

        return $this->json([
            'form' => $handle,
            ...$this->submissionPayload($submission),
            'cp_url' => cp_route('forms.submissions.show', [$handle, $submission->id()]),
        ]);
    }
}
