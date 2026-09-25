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

/**
 * Statamic's FormPolicy and FormSubmissionPolicy grant every ability to super
 * users and to 'configure forms' before they look at the per-form
 * permissions, and the role editor hides those under that umbrella.
 * hasPermission() is a plain contains check, so the checks here mirror the
 * policies instead of going through ensurePermission() alone.
 */
trait ResolvesForms
{
    /**
     * Narrowed to the concrete class, as the globals tools do: the facade
     * declares find() non-nullable though the repository returns null for a
     * missing file, and the real API (store, showUrl) lives on the class.
     * The Eloquent driver's form extends it.
     */
    protected function findExposedForm(string $handle): FormInstance
    {
        // Missing and exists-but-unexposed are indistinguishable by design;
        // the error lists only exposed handles.
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

    /**
     * Scoped to the form: ids are timestamps, and Form::submission() looks
     * an id up across every form.
     */
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
     * The id is the submission's timestamp, which is all Statamic stores
     * about when it came in; the date is shown in app.timezone as the
     * Control Panel's listing shows it.
     *
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
