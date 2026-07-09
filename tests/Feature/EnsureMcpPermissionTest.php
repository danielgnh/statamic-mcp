<?php

use Danielgnh\StatamicMcp\Middleware\EnsureMcpPermission;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Statamic\Facades\Role;
use Statamic\Facades\User;

function ensureMcpPermission(?Authenticatable $user)
{
    $request = Request::create('/mcp/statamic', 'POST');
    $request->setUserResolver(fn () => $user);

    return (new EnsureMcpPermission)->handle($request, fn () => response()->json(['ok' => true]));
}

it('rejects requests with no authenticated user', function () {
    $response = ensureMcpPermission(null);

    expect($response->getStatusCode())->toBe(403)
        ->and($response->getData(true)['error'])
        ->toBe("requires 'access mcp' — grant it to a role of the connected user in the Control Panel");
});

it('rejects a generic Authenticatable that is not a Statamic user', function () {
    // Locks in the Stache UserRepository's fail-closed fromUser semantics:
    // a non-Statamic Authenticatable resolves to null, never to a permission grant.
    $generic = new GenericUser(['id' => 1, 'email' => 'generic@site.test']);

    $response = ensureMcpPermission($generic);

    expect($response->getStatusCode())->toBe(403)
        ->and($response->getData(true)['error'])
        ->toBe("requires 'access mcp' — grant it to a role of the connected user in the Control Panel");
});

it('rejects users whose roles lack access mcp', function () {
    Fixtures::site();

    Role::make('editor_without_mcp')->title('Editor')->addPermission('view blog entries')->save();

    $user = tap(User::make()->email('nomcp@site.test')->assignRole('editor_without_mcp'))->save();

    $response = ensureMcpPermission($user);

    expect($response->getStatusCode())->toBe(403)
        ->and($response->getData(true)['error'])
        ->toBe("requires 'access mcp' — grant it to a role of nomcp@site.test in the Control Panel");
});

it('passes users granted access mcp through to the server', function () {
    Fixtures::site();

    $user = Fixtures::makeUser(); // role with 'access mcp' only

    $response = ensureMcpPermission($user);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe(['ok' => true]);
});

it('passes super users without an explicit grant', function () {
    Fixtures::site();

    $response = ensureMcpPermission(Fixtures::makeSuper());

    expect($response->getStatusCode())->toBe(200);
});
