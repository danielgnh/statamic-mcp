<?php

declare(strict_types=1);

namespace Danielgnh\StatamicMcp\Tools\Concerns;

use Danielgnh\StatamicMcp\Tools\ToolException;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Contracts\Forms\Form as FormContract;
use Statamic\Facades\Form;
use Statamic\Facades\FormSubmission;
use Statamic\Forms\Form as FormInstance;
use Statamic\Forms\Submission as SubmissionInstance;

trait ResolvesForms
{
    protected function findExposedForm(string $handle): FormInstance
    {
        $this->ensureExposed('forms', $handle);

        $form = Form::find($handle);

        if (! $form instanceof FormInstance) {
            throw new ToolException($this->notFoundMessage('form', $handle, $this->exposedHandles('forms')));
        }

        return $form;
    }

    protected function canViewSubmissions(UserContract $user, string $handle): bool
    {
        return $this->can($user, 'configure forms') || $this->can($user, "view {$handle} form submissions");
    }

    protected function canDeleteSubmissions(UserContract $user, string $handle): bool
    {
        return $this->can($user, 'configure forms') || $this->can($user, "delete {$handle} form submissions");
    }

    protected function ensureCanViewSubmissions(UserContract $user, string $handle): void
    {
        if ($this->can($user, 'configure forms')) {
            return;
        }

        $this->ensurePermission($user, "view {$handle} form submissions");
    }

    protected function ensureCanDeleteSubmissions(UserContract $user, string $handle): void
    {
        if ($this->can($user, 'configure forms')) {
            return;
        }

        $this->ensurePermission($user, "delete {$handle} form submissions");
    }

    protected function findSubmission(FormContract $form, string $id): SubmissionInstance
    {
        $submission = FormSubmission::query()
            ->where('form', $form->handle())
            ->where('id', $id)
            ->first();

        if (! $submission instanceof SubmissionInstance) {
            throw new ToolException(sprintf(
                "submission '%s' not found in form '%s' — use submissions_list to see its submissions",
                $id,
                $form->handle(),
            ));
        }

        return $submission;
    }

    /**
     * @return array{id: string, date: string, data: array<string, mixed>}
     */
    protected function submissionPayload(SubmissionInstance $submission): array
    {
        return [
            'id' => (string) $submission->id(),
            'date' => $submission->date()->setTimezone(config('app.timezone'))->toIso8601String(),
            'data' => $submission->data()->all(),
        ];
    }
}
