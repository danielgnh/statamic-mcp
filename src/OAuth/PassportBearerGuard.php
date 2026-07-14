<?php

namespace Danielgnh\StatamicMcp\OAuth;

use Illuminate\Http\Request;
use Laravel\Passport\ClientRepository;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResourceServer;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Facades\User;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;

/**
 * The request resolver behind the addon's OAuth guard: validate the bearer
 * with Passport's own ResourceServer (signature, expiry, revocation — exactly
 * what Passport's TokenGuard runs), then resolve the token's user through the
 * STATAMIC repository instead of Eloquent.
 *
 * This one substitution is what frees OAuth mode from the "database users
 * only" constraint: Passport's stock guard needs an Eloquent model carrying
 * HasApiTokens (it ends on $user->withAccessToken()), while every other leg of
 * the flow — consent, auth-code exchange, refresh — only ever touches the user
 * id string. File users, or Eloquent users, both resolve here.
 *
 * Token scopes are stashed on the request (SCOPES_ATTRIBUTE) for the
 * middleware's mcp:use check, since without HasApiTokens there is no
 * $user->tokenCan().
 */
class PassportBearerGuard
{
    /** The guard name the addon defines and pins in OAuth mode. */
    public const GUARD = 'statamic-mcp';

    /** The auth driver name backing that guard. */
    public const DRIVER = 'statamic-mcp-passport';

    /** Request attribute carrying the validated token's scopes. */
    public const SCOPES_ATTRIBUTE = 'statamic_mcp_oauth_scopes';

    /** Request attribute marking a bearer that already failed validation. */
    protected const FAILED_ATTRIBUTE = 'statamic_mcp_oauth_failed';

    public function __invoke(Request $request): ?UserContract
    {
        if (blank($request->bearerToken())) {
            return null;
        }

        // Re-entry guard: report() below runs the exception handler, whose
        // context gathering may call Auth::id() and re-enter this resolver on
        // the same request — revalidating the same bad bearer forever.
        // (Passport's TokenGuard dodges the same recursion by blanking the
        // Authorization header.)
        if ($request->attributes->get(self::FAILED_ATTRIBUTE)) {
            return null;
        }

        $psr = (new PsrHttpFactory)->createRequest($request);

        try {
            $psr = app(ResourceServer::class)->validateAuthenticatedRequest($psr);
        } catch (OAuthServerException $e) {
            $request->attributes->set(self::FAILED_ATTRIBUTE, true);

            report($e); // parity with Passport's TokenGuard: 401 for the client, details in the log

            return null;
        }

        // Parity with Passport's TokenGuard: a revoked client kills its tokens.
        if (! app(ClientRepository::class)->findActive($psr->getAttribute('oauth_client_id'))) {
            return null;
        }

        $request->attributes->set(
            self::SCOPES_ATTRIBUTE,
            array_map(strval(...), (array) $psr->getAttribute('oauth_scopes', [])),
        );

        $id = $psr->getAttribute('oauth_user_id');

        return filled($id) ? User::find((string) $id) : null;
    }
}
