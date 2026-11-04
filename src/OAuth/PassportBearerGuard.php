<?php

namespace Danielgnh\StatamicMcp\OAuth;

use Illuminate\Http\Request;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResourceServer;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Facades\User;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;

class PassportBearerGuard
{
    public const GUARD = 'statamic-mcp';

    public const DRIVER = 'statamic-mcp-passport';

    public const SCOPES_ATTRIBUTE = 'statamic_mcp_oauth_scopes';

    protected const FAILED_ATTRIBUTE = 'statamic_mcp_oauth_failed';

    public function __invoke(Request $request): ?UserContract
    {
        if (blank($request->bearerToken())) {
            return null;
        }

        if ($request->attributes->get(self::FAILED_ATTRIBUTE)) {
            return null;
        }

        $psr = (new PsrHttpFactory)->createRequest($request);

        try {
            $psr = app(ResourceServer::class)->validateAuthenticatedRequest($psr);
        } catch (OAuthServerException $e) {
            $request->attributes->set(self::FAILED_ATTRIBUTE, true);

            report($e);

            return null;
        }

        $client = app(ClientRepository::class)->findActive($psr->getAttribute('oauth_client_id'));

        if (! $client || ! self::acceptsClient($client)) {
            return null;
        }

        $request->attributes->set(
            self::SCOPES_ATTRIBUTE,
            array_map(strval(...), (array) $psr->getAttribute('oauth_scopes', [])),
        );

        $id = $psr->getAttribute('oauth_user_id');

        if (blank($id) || ($id === $psr->getAttribute('oauth_client_id') && $client->hasGrantType('client_credentials'))) {
            return null;
        }

        return User::find((string) $id);
    }

    public static function acceptsClient(Client $client): bool
    {
        $provider = $client->getAttribute('provider');

        if (blank($provider)) {
            $provider = $client->hasGrantType('password') || $client->hasGrantType('personal_access')
                ? config('auth.guards.api.provider')
                : config('auth.guards.'.config('passport.guard', 'web').'.provider');
        }

        return blank($provider)
            || $provider === config('auth.guards.'.config('statamic.users.guards.cp', 'web').'.provider');
    }
}
