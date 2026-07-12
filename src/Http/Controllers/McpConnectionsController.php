<?php

namespace Danielgnh\StatamicMcp\Http\Controllers;

use Danielgnh\StatamicMcp\OAuth\ConnectionRepository;
use Illuminate\Http\RedirectResponse;
use Statamic\Facades\User;
use Statamic\Http\Controllers\CP\CpController;

/**
 * Route-level authorization is Statamic's 'can:access mcp_tokens utility'
 * middleware, same as McpTokensController. destroy() is the only action —
 * connections are created solely by the OAuth consent flow itself.
 */
class McpConnectionsController extends CpController
{
    public function destroy(ConnectionRepository $connections, string $clientId, string $userId): RedirectResponse
    {
        $user = User::current();

        // Ownership comes from the URL and is checked before existence, so a
        // non-super probing other users' pairs learns nothing (403 either way).
        abort_unless($user->isSuper() || $userId === (string) $user->id(), 403);

        abort_unless($connections->disconnect($userId, $clientId), 404);

        return redirect(cp_route('utilities.mcp-tokens'))->with('success', __('Connection disconnected.'));
    }
}
