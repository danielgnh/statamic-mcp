<?php

use Danielgnh\StatamicMcp\Support\AgentGuidelines;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;

beforeEach(function () {
    config(['statamic.editions.pro' => true]);

    Fixtures::site();
    Fixtures::blog();
});

/**
 * @return array<string, mixed>
 */
function guidelinesForm(string $site, string $row): array
{
    return [
        'site' => $site,
        'resources' => [
            ['id' => 'row-one', 'type' => 'resource', 'enabled' => true, 'collections' => ['blog'], 'taxonomies' => [], 'guidelines' => $row],
        ],
    ];
}

it('keeps the guidelines page to super admins', function () {
    $user = Fixtures::makeUser('access cp');

    // The CP turns HTML 403s into redirects; JSON requests get the raw 403.
    $this->actingAs($user)
        ->getJson(cp_route('mcp.guidelines.edit'))
        ->assertForbidden();

    $this->actingAs($user)
        ->patchJson(cp_route('mcp.guidelines.update'), guidelinesForm('Plain.', 'Hero first.'))
        ->assertForbidden();

    expect(app(AgentGuidelines::class)->values())->toBe([]);
});

it('shows super admins the publish form with the stored guidelines', function () {
    app(AgentGuidelines::class)->save(['site' => 'Friendly, never salesy.']);

    $this->actingAs(Fixtures::makeSuper())
        ->get(cp_route('mcp.guidelines.edit'))
        ->assertOk()
        ->assertSee('<ui-publish-form', false)
        ->assertSee('Friendly, never salesy.', false)
        ->assertSee(cp_route('mcp.guidelines.update'), false);
});

it('saves the form into the addon settings agents read', function () {
    $this->actingAs(Fixtures::makeSuper())
        ->patchJson(cp_route('mcp.guidelines.update'), guidelinesForm('Plain and warm.', 'Every post ends with a question.'))
        ->assertOk()
        ->assertExactJson(['saved' => true]);

    $guidelines = app(AgentGuidelines::class);

    expect($guidelines->site())->toBe('Plain and warm.')
        ->and($guidelines->for('collections', 'blog'))->toBe('Every post ends with a question.');
});

it('rejects text Statamic would run as template code, on the field that holds it', function () {
    $this->actingAs(Fixtures::makeSuper())
        ->patchJson(cp_route('mcp.guidelines.update'), guidelinesForm('Headings read like {{ title }}.', 'Close with <x-cta> every time.'))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['site', 'resources.0.guidelines']);

    $this->actingAs(Fixtures::makeSuper())
        ->patchJson(cp_route('mcp.guidelines.update'), guidelinesForm('Wrap it in <s:partial src="cta">.', 'Use @props sparingly.'))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['site', 'resources.0.guidelines']);

    expect(app(AgentGuidelines::class)->values())->toBe([]);
});
