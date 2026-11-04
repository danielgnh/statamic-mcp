<?php

namespace Danielgnh\StatamicMcp\Http\Controllers;

use Danielgnh\StatamicMcp\OAuth\ConnectionRepository;
use Illuminate\Http\RedirectResponse;
use Statamic\Facades\User;
use Statamic\Http\Controllers\CP\CpController;

class McpConnectionsController extends CpController
{
    public function destroy(ConnectionRepository $connections, string $clientId, string $userId): RedirectResponse
    {
        $user = User::current();

        abort_if($user === null, 401);

        abort_unless($user->isSuper() || $userId === (string) $user->id(), 403);

        abort_unless($connections->disconnect($userId, $clientId), 404);

        return redirect()->to(cp_route('mcp.connections.index'))->with('success', __('Connection disconnected.'));
    }
}
