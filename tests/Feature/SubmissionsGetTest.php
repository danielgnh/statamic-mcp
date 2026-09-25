<?php

declare(strict_types=1);

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\SubmissionsGet;

it('returns one submission with its data and CP link', function () {
    Fixtures::site();
    Fixtures::form();

    $id = Fixtures::submission('contact', ['name' => 'Ada', 'email' => 'ada@example.com', 'message' => 'Hello', 'newsletter' => true], '2026-09-20 09:00:00');

    Server::actingAs(Fixtures::makeUser('view contact form submissions'))
        ->tool(SubmissionsGet::class, ['form' => 'contact', 'id' => $id])
        ->assertOk()
        ->assertSee(sprintf(
            '"form":"contact","id":"%s","date":"2026-09-20T09:00:00+00:00","data":{"name":"Ada","email":"ada@example.com","message":"Hello","newsletter":true},"cp_url":"http://localhost/cp/forms/contact/submissions/%s"',
            $id,
            $id,
        ));
});

it('looks the id up within the form only', function () {
    Fixtures::site();
    Fixtures::form('contact');
    Fixtures::form('newsletter');

    $id = Fixtures::submission('newsletter', ['name' => 'Ada'], '2026-09-20 09:00:00');

    Server::actingAs(Fixtures::makeSuper())
        ->tool(SubmissionsGet::class, ['form' => 'contact', 'id' => $id])
        ->assertHasErrors(["submission '{$id}' not found in form 'contact' — use submissions_list to see its submissions"]);
});

it("requires 'view {form} form submissions'", function () {
    Fixtures::site();
    Fixtures::form();

    $id = Fixtures::submission('contact', ['name' => 'Ada'], '2026-09-20 09:00:00');

    $user = Fixtures::makeUser('view newsletter form submissions'); // another form

    Server::actingAs($user)
        ->tool(SubmissionsGet::class, ['form' => 'contact', 'id' => $id])
        ->assertHasErrors(["requires 'view contact form submissions' — grant it to a role of {$user->email()} in the Control Panel"]);
});

it('treats an unexposed form as missing', function () {
    Fixtures::site();
    Fixtures::form('contact');
    Fixtures::form('newsletter');

    config(['statamic.mcp.resources.forms' => ['contact']]);

    $id = Fixtures::submission('newsletter', ['name' => 'Ada'], '2026-09-20 09:00:00');

    Server::actingAs(Fixtures::makeSuper())
        ->tool(SubmissionsGet::class, ['form' => 'newsletter', 'id' => $id])
        ->assertHasErrors(["form 'newsletter' not found — available: contact"]);
});
