<?php

namespace Danielgnh\StatamicMcp\Http\Controllers;

use Danielgnh\StatamicMcp\Tokens\TokenRepository;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Statamic\Facades\User;
use Statamic\Http\Controllers\CP\CpController;

class McpTokensController extends CpController
{
    private const int MAX_TOKENS_PER_USER = 100;

    public function store(Request $request, TokenRepository $tokens): RedirectResponse
    {
        abort_if(config('statamic.mcp.auth') === 'oauth', 403);

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:100'],
            'expiry' => ['required', 'in:never,30,90,365'],
        ]);

        $days = $validated['expiry'] === 'never' ? null : (int) $validated['expiry'];

        $user = User::current();

        abort_if($user === null, 401);

        if (collect($tokens->all())->where('user', (string) $user->id())->count() >= self::MAX_TOKENS_PER_USER) {
            return redirect()->to(cp_route('mcp.connections.index'))
                ->with('error', __('You hold :max tokens, the most one user can have — revoke one first.', ['max' => self::MAX_TOKENS_PER_USER]))
                ->withInput();
        }

        try {
            $plain = $tokens->issue($user, $validated['name'] ?? null, $days);
        } catch (LockTimeoutException) {
            return redirect()->to(cp_route('mcp.connections.index'))
                ->with('error', __('The token store is busy — please try again.'))
                ->withInput();
        }

        return redirect()->to(cp_route('mcp.connections.index'))->with('statamic-mcp.plain_token', [
            'token' => $plain->token,
            'tokenId' => $plain->tokenId,
            'name' => $plain->name,
            'expiresAt' => $plain->expiresAt?->isoFormat('ll'),
        ]);
    }

    public function destroy(TokenRepository $tokens, string $tokenId): RedirectResponse
    {
        $record = $tokens->find($tokenId);

        abort_if($record === null, 404);

        $user = User::current();

        abort_if($user === null, 401);

        // @phpstan-ignore nullCoalesce.offset
        abort_unless($user->isSuper() || ($record['user'] ?? null) === (string) $user->id(), 403);

        try {
            $tokens->revoke($tokenId);
        } catch (LockTimeoutException) {
            return redirect()->to(cp_route('mcp.connections.index'))
                ->with('error', __('The token store is busy — please try again.'));
        }

        return redirect()->to(cp_route('mcp.connections.index'))->with('success', __('Token revoked.'));
    }
}
