<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\SubmissionsDelete;
use Laravel\Mcp\Request;
use Statamic\Facades\Form;

it('deletes a submission when deletes are enabled', function () {
    config(['statamic.mcp.deletes' => true]);

    Fixtures::site();
    Fixtures::form();

    $doomed = Fixtures::submission('contact', ['name' => 'Spam'], '2026-09-20 09:00:00');
    $kept = Fixtures::submission('contact', ['name' => 'Ada'], '2026-09-24 15:30:00');

    Server::actingAs(Fixtures::makeUser('delete contact form submissions'))
        ->tool(SubmissionsDelete::class, ['form' => 'contact', 'id' => $doomed])
        ->assertOk()
        ->assertSee(sprintf('"deleted":true,"form":"contact","id":"%s","date":"2026-09-20T09:00:00+00:00"', $doomed))
        ->assertSee('cannot be undone')
        // No cp_url on deletes: the CP page for a deleted submission would 404.
        ->assertDontSee('cp_url');

    expect(Form::find('contact')->submissions()->map->id()->all())->toBe([$kept]);
});

it('is not registered when deletes are disabled', function () {
    // config default: statamic.mcp.deletes = false
    Fixtures::site();
    Fixtures::form();

    $id = Fixtures::submission('contact', ['name' => 'Ada'], '2026-09-20 09:00:00');

    Server::actingAs(Fixtures::makeSuper())
        ->tool(SubmissionsDelete::class, ['form' => 'contact', 'id' => $id])
        ->assertHasErrors(['Tool [submissions_delete] not found']);

    expect(Form::find('contact')->submissions())->toHaveCount(1);
});

it('denies deleting with the view permission alone', function () {
    config(['statamic.mcp.deletes' => true]);

    Fixtures::site();
    Fixtures::form();

    $id = Fixtures::submission('contact', ['name' => 'Ada'], '2026-09-20 09:00:00');

    $user = Fixtures::makeUser('view contact form submissions');

    Server::actingAs($user)
        ->tool(SubmissionsDelete::class, ['form' => 'contact', 'id' => $id])
        ->assertHasErrors(["requires 'delete contact form submissions' — grant it to a role of {$user->email()} in the Control Panel"]);

    expect(Form::find('contact')->submissions())->toHaveCount(1);
});

it("lets 'configure forms' delete, as Statamic's submission policy does", function () {
    config(['statamic.mcp.deletes' => true]);

    Fixtures::site();
    Fixtures::form();

    $id = Fixtures::submission('contact', ['name' => 'Ada'], '2026-09-20 09:00:00');

    Server::actingAs(Fixtures::makeUser('configure forms'))
        ->tool(SubmissionsDelete::class, ['form' => 'contact', 'id' => $id])
        ->assertOk()
        ->assertSee('"deleted":true');

    expect(Form::find('contact')->submissions())->toHaveCount(0);
});

it('re-checks the deletes gate inside the handler, not just at registration', function () {
    Fixtures::site();
    Fixtures::form();

    $id = Fixtures::submission('contact', ['name' => 'Ada'], '2026-09-20 09:00:00');

    // config default: statamic.mcp.deletes = false

    // Call handle() directly, bypassing tools/list — exactly what a client
    // with a stale tool cache does.
    $response = (new SubmissionsDelete)->handle(new Request(['form' => 'contact', 'id' => $id]));

    expect($response->isError())->toBeTrue()
        ->and((string) $response->content())
        ->toContain('delete tools are disabled on this server (statamic.mcp.deletes)');

    expect(Form::find('contact')->submissions())->toHaveCount(1);
});
