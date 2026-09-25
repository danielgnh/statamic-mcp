<?php

declare(strict_types=1);

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\SubmissionsList;

it('lists submissions newest first with their full data', function () {
    Fixtures::site();
    Fixtures::form();

    $older = Fixtures::submission('contact', ['name' => 'Ada', 'email' => 'ada@example.com', 'message' => 'Hello', 'newsletter' => true], '2026-09-20 09:00:00');
    $newer = Fixtures::submission('contact', ['name' => 'Grace', 'email' => 'grace@example.com', 'message' => 'Question about pricing', 'newsletter' => false], '2026-09-24 15:30:00');

    Server::actingAs(Fixtures::makeUser('view contact form submissions'))
        ->tool(SubmissionsList::class, ['form' => 'contact'])
        ->assertOk()
        ->assertSee('"form":"contact","total":2,"page":1,"per_page":25,"next_page":null')
        ->assertSee(sprintf(
            '"submissions":[{"id":"%s","date":"2026-09-24T15:30:00+00:00","data":{"name":"Grace","email":"grace@example.com","message":"Question about pricing","newsletter":false}},{"id":"%s","date":"2026-09-20T09:00:00+00:00","data":{"name":"Ada","email":"ada@example.com","message":"Hello","newsletter":true}}]',
            $newer,
            $older,
        ))
        ->assertSee('"cp_url":"http://localhost/cp/forms/contact"');
});

it('returns an empty page for a form without submissions', function () {
    Fixtures::site();
    Fixtures::form();

    Server::actingAs(Fixtures::makeUser('view contact form submissions'))
        ->tool(SubmissionsList::class, ['form' => 'contact'])
        ->assertOk()
        ->assertSee('"total":0')
        ->assertSee('"submissions":[]');
});

it('searches the date and the text fields, as the Control Panel does', function () {
    Fixtures::site();
    Fixtures::form();

    $ada = Fixtures::submission('contact', ['name' => 'Ada', 'message' => 'Hello', 'newsletter' => true], '2026-09-20 09:00:00');
    $grace = Fixtures::submission('contact', ['name' => 'Grace', 'message' => 'Question about pricing', 'newsletter' => false], '2026-09-24 15:30:00');

    $user = Fixtures::makeUser('view contact form submissions');

    Server::actingAs($user)
        ->tool(SubmissionsList::class, ['form' => 'contact', 'search' => 'pricing'])
        ->assertOk()
        ->assertSee('"total":1')
        ->assertSee(sprintf('"id":"%s"', $grace))
        ->assertDontSee(sprintf('"id":"%s"', $ada));

    Server::actingAs($user)
        ->tool(SubmissionsList::class, ['form' => 'contact', 'search' => '2026-09-20'])
        ->assertOk()
        ->assertSee('"total":1')
        ->assertSee(sprintf('"id":"%s"', $ada));

    // A toggle is not a text field: its value never matches.
    Server::actingAs($user)
        ->tool(SubmissionsList::class, ['form' => 'contact', 'search' => 'true'])
        ->assertOk()
        ->assertSee('"total":0');
});

it('bounds the date with since (inclusive) and before (exclusive)', function () {
    Fixtures::site();
    Fixtures::form();

    $first = Fixtures::submission('contact', ['name' => 'Ada'], '2026-09-20 09:00:00');
    $second = Fixtures::submission('contact', ['name' => 'Grace'], '2026-09-22 12:00:00');
    $third = Fixtures::submission('contact', ['name' => 'Linus'], '2026-09-24 15:30:00');

    $user = Fixtures::makeUser('view contact form submissions');

    Server::actingAs($user)
        ->tool(SubmissionsList::class, ['form' => 'contact', 'since' => '2026-09-22'])
        ->assertOk()
        ->assertSee('"total":2')
        ->assertSee(sprintf('"id":"%s"', $third))
        ->assertSee(sprintf('"id":"%s"', $second))
        ->assertDontSee(sprintf('"id":"%s"', $first));

    // A date alone means midnight, so the 22nd's submission is not before it.
    Server::actingAs($user)
        ->tool(SubmissionsList::class, ['form' => 'contact', 'before' => '2026-09-22'])
        ->assertOk()
        ->assertSee('"total":1')
        ->assertSee(sprintf('"id":"%s"', $first));

    // since is inclusive to the second.
    Server::actingAs($user)
        ->tool(SubmissionsList::class, ['form' => 'contact', 'since' => '2026-09-22T12:00:00+00:00', 'before' => '2026-09-24'])
        ->assertOk()
        ->assertSee('"total":1')
        ->assertSee(sprintf('"id":"%s"', $second));
});

it('reads a time without an offset in the app timezone', function () {
    Fixtures::site();
    Fixtures::form();

    config(['app.timezone' => 'Europe/Berlin']);

    // 09:00 UTC is 11:00 in Berlin.
    $id = Fixtures::submission('contact', ['name' => 'Ada'], '2026-09-20 09:00:00');

    $user = Fixtures::makeUser('view contact form submissions');

    Server::actingAs($user)
        ->tool(SubmissionsList::class, ['form' => 'contact', 'since' => '2026-09-20T11:00:00'])
        ->assertOk()
        ->assertSee('"total":1')
        ->assertSee(sprintf('"id":"%s","date":"2026-09-20T11:00:00+02:00"', $id));

    Server::actingAs($user)
        ->tool(SubmissionsList::class, ['form' => 'contact', 'since' => '2026-09-20T11:00:01'])
        ->assertOk()
        ->assertSee('"total":0');
});

it('paginates deterministically', function () {
    Fixtures::site();
    Fixtures::form();

    $older = Fixtures::submission('contact', ['name' => 'Ada'], '2026-09-20 09:00:00');
    $newer = Fixtures::submission('contact', ['name' => 'Grace'], '2026-09-24 15:30:00');

    $user = Fixtures::makeUser('view contact form submissions');

    Server::actingAs($user)
        ->tool(SubmissionsList::class, ['form' => 'contact', 'limit' => 1])
        ->assertOk()
        ->assertSee('"total":2,"page":1,"per_page":1,"next_page":2')
        ->assertSee(sprintf('"id":"%s"', $newer))
        ->assertDontSee(sprintf('"id":"%s"', $older));

    Server::actingAs($user)
        ->tool(SubmissionsList::class, ['form' => 'contact', 'limit' => 1, 'page' => 2])
        ->assertOk()
        ->assertSee('"total":2,"page":2,"per_page":1,"next_page":null')
        ->assertSee(sprintf('"id":"%s"', $older));
});

it('rejects a date it cannot parse', function () {
    Fixtures::site();
    Fixtures::form();

    Server::actingAs(Fixtures::makeUser('view contact form submissions'))
        ->tool(SubmissionsList::class, ['form' => 'contact', 'since' => 'not-a-date'])
        ->assertHasErrors(["could not parse since 'not-a-date' — use e.g. 2026-09-20 or 2026-09-20T09:00:00+02:00"]);
});

it("requires 'view {form} form submissions'", function () {
    Fixtures::site();
    Fixtures::form();

    $user = Fixtures::makeUser(); // 'access mcp' only

    Server::actingAs($user)
        ->tool(SubmissionsList::class, ['form' => 'contact'])
        ->assertHasErrors(["requires 'view contact form submissions' — grant it to a role of {$user->email()} in the Control Panel"]);
});

it("lets 'configure forms' read every form, as Statamic's form policy does", function () {
    Fixtures::site();
    Fixtures::form();
    Fixtures::submission('contact', ['name' => 'Ada'], '2026-09-20 09:00:00');

    Server::actingAs(Fixtures::makeUser('configure forms'))
        ->tool(SubmissionsList::class, ['form' => 'contact'])
        ->assertOk()
        ->assertSee('"total":1');
});

it('treats unexposed and missing forms identically, listing only exposed handles', function () {
    Fixtures::site();
    Fixtures::form('contact');
    Fixtures::form('newsletter');

    config(['statamic.mcp.resources.forms' => ['contact']]);

    $super = Fixtures::makeSuper();

    Server::actingAs($super)
        ->tool(SubmissionsList::class, ['form' => 'newsletter'])
        ->assertHasErrors(["form 'newsletter' not found — available: contact"]);

    Server::actingAs($super)
        ->tool(SubmissionsList::class, ['form' => 'ghost'])
        ->assertHasErrors(["form 'ghost' not found — available: contact"]);
});
