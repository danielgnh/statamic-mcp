<?php

use Danielgnh\StatamicMcp\OAuth\PassportBearerGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Exceptions;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResourceServer;

it('validates a failing bearer once per request, because reporting the failure can run the guard again', function () {
    Exceptions::fake();

    $this->mock(ResourceServer::class)
        ->shouldReceive('validateAuthenticatedRequest')
        ->once()
        ->andThrow(OAuthServerException::accessDenied('bad bearer'));

    $guard = new PassportBearerGuard;
    $request = Request::create('/mcp/statamic', 'POST', server: ['HTTP_AUTHORIZATION' => 'Bearer bad']);

    expect($guard($request))->toBeNull()
        ->and($guard($request))->toBeNull();

    Exceptions::assertReportedCount(1);
});
