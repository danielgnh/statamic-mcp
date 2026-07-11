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
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:100'],
            'expiry' => ['required', 'in:never,30,90,365'],
        ]);

        $days = $validated['expiry'] === 'never' ? null : (int) $validated['expiry'];

        try {
            $plain = $tokens->issue(User::current(), $validated['name'] ?? null, $days);
        } catch (LockTimeoutException) {
            return redirect(cp_route('utilities.mcp-tokens'))
                ->with('error', __('The token store is busy — please try again.'));
        }

        return redirect(cp_route('utilities.mcp-tokens'))->with('statamic-mcp.plain_token', [
            'token' => $plain->token,
            'tokenId' => $plain->tokenId,
            'name' => $plain->name,
            'expiresAt' => $plain->expiresAt?->toFormattedDateString(),
        ]);
    }
}
