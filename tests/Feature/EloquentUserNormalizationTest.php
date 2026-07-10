<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\FakeEloquentUser;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\EntriesList;
use Statamic\Facades\Entry;

// Under Passport, $request->user() is an Eloquent model, not a Statamic user;
// Tool::user() normalizes via User::fromUser(). This suite runs the file
// (Stache) user repository, whose fromUser() is instanceof-based and returns
// null for anything that is not already a Statamic user — so the honest
// assertions here are: (1) an Eloquent authenticatable the repository cannot
// normalize fails closed with a clean tool error, never a 500; (2)+(3) an
// authenticatable Statamic CAN normalize, arriving via the oauth 'api' guard,
// drives the full tool pipeline including real permission enforcement. Actual
// Eloquent-model wrapping (User::make()->model($user)) lives in the eloquent
// users repository — the very oauth prerequisite this environment lacks.

it('fails closed with a clean error when the auth user cannot be normalized', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $statamicUser = Fixtures::makeUser('view blog entries');

    // Same id as a real Statamic user: the file repository still refuses it —
    // fromUser() matches on instanceof, not on the auth identifier.
    $eloquent = new FakeEloquentUser(['id' => $statamicUser->id()]);

    Server::actingAs($eloquent)
        ->tool(EntriesList::class, ['collection' => 'blog'])
        ->assertHasErrors(['no authenticated user — the MCP server requires token or OAuth authentication; see the README']);
});

it('runs tools for a normalizable user authenticated on the api guard', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    Entry::make()
        ->collection('blog')
        ->slug('hello-world')
        ->data(['title' => 'Hello World'])
        ->published(true)
        ->save();

    // The guard AuthenticateOAuth delegates to; session driver keeps it
    // resolvable without Passport (setUser never touches the provider).
    config(['auth.guards.api' => ['driver' => 'session', 'provider' => 'users']]);

    Server::actingAs(Fixtures::makeUser('view blog entries'), 'api')
        ->tool(EntriesList::class, ['collection' => 'blog'])
        ->assertOk()
        ->assertSee('hello-world');
});

it("enforces the normalized user's real permissions on the api guard", function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    config(['auth.guards.api' => ['driver' => 'session', 'provider' => 'users']]);

    $user = Fixtures::makeUser(); // 'access mcp' only — no view permission

    Server::actingAs($user, 'api')
        ->tool(EntriesList::class, ['collection' => 'blog'])
        ->assertHasErrors(["requires 'view blog entries' — grant it to a role of {$user->email()} in the Control Panel"]);
});
