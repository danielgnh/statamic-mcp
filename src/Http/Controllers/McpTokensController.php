<?php

namespace Danielgnh\StatamicMcp\Http\Controllers;

use Danielgnh\StatamicMcp\Tokens\TokenRepository;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Statamic\Facades\User;
use Statamic\Http\Controllers\CP\CpController;

/**
 * Route-level authorization is Statamic's 'can:access mcp_tokens utility'
 * middleware (applied by the utility registration). store() always issues for
 * the CURRENT user — issuing for someone else stays a console operation.
 */
class McpTokensController extends CpController
{
    public function store(Request $request, TokenRepository $tokens): RedirectResponse
    {
        // OAuth mode hides the whole token UI, and nothing would accept a token
        // issued here — so the route refuses too rather than minting a dud.
        abort_if(config('statamic.mcp.auth') === 'oauth', 403);

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:100'],
            'expiry' => ['required', 'in:never,30,90,365'],
        ]);

        $days = $validated['expiry'] === 'never' ? null : (int) $validated['expiry'];

        $user = User::current();

        abort_if($user === null, 401);

        try {
            $plain = $tokens->issue($user, $validated['name'] ?? null, $days);
        } catch (LockTimeoutException) {
            return redirect()->to(cp_route('utilities.mcp-tokens'))
                ->with('error', __('The token store is busy — please try again.'))
                ->withInput();
        }

        return redirect()->to(cp_route('utilities.mcp-tokens'))->with('statamic-mcp.plain_token', [
            'token' => $plain->token,
            'tokenId' => $plain->tokenId,
            'name' => $plain->name,
            'expiresAt' => $plain->expiresAt?->toFormattedDateString(),
        ]);
    }

    public function destroy(TokenRepository $tokens, string $tokenId): RedirectResponse
    {
        $record = $tokens->find($tokenId);

        abort_if($record === null, 404);

        $user = User::current();

        abort_if($user === null, 401);

        // Owner-or-super, enforced server-side — the view hiding other users'
        // rows is cosmetic, this is the actual gate. 'user' is coalesced
        // because tokens.yaml is hand-editable and a pruned key must fail
        // closed (403), not warn.
        // @phpstan-ignore nullCoalesce.offset (hand-edited tokens.yaml can lack 'user'; docblock widening deferred to v1.1)
        abort_unless($user->isSuper() || ($record['user'] ?? null) === (string) $user->id(), 403);

        try {
            $tokens->revoke($tokenId);
        } catch (LockTimeoutException) {
            return redirect()->to(cp_route('utilities.mcp-tokens'))
                ->with('error', __('The token store is busy — please try again.'));
        }

        return redirect()->to(cp_route('utilities.mcp-tokens'))->with('success', __('Token revoked.'));
    }
}
