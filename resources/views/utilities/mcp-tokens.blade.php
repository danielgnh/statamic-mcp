<div class="max-w-3xl space-y-6">

    @if (session('error'))
        <div class="rounded border border-red-500 bg-red-100 p-3 text-red-800">{{ session('error') }}</div>
    @endif

    @if (session('success'))
        <div class="rounded border border-green-500 bg-green-100 p-3 text-green-800">{{ session('success') }}</div>
    @endif

    @if ($lacksAccessMcp)
        <div class="rounded border border-yellow-500 bg-yellow-100 p-3 text-yellow-800">
            {{ __('Your account does not have the "Access MCP" permission — tokens you issue will authenticate but every request will be denied until an administrator grants it to one of your roles.') }}
        </div>
    @endif

    @if ($oauthMode)
        <div class="rounded border border-blue-500 bg-blue-100 p-3 text-blue-800">
            {{ __('This site is in OAuth mode — bearer tokens are not accepted until the auth mode is switched back to token. You can still manage tokens here.') }}
        </div>
    @endif

    @if ($insecureUrl)
        <div class="rounded border border-yellow-500 bg-yellow-100 p-3 text-yellow-800">
            {{ __('The MCP endpoint is not HTTPS — bearer tokens travel unencrypted. Set APP_URL to your real https:// site URL.') }}
        </div>
    @endif

    @if ($plainToken)
        <div class="rounded border border-green-600 bg-green-50 p-4 space-y-3">
            <h2 class="font-bold">{{ __('Token created — this is the ONLY time it will be displayed. Copy it now.') }}</h2>
            <input type="text" readonly value="{{ $plainToken['token'] }}" class="input-text w-full font-mono" onclick="this.select()">
            <p class="text-sm">{{ __('Expires') }}: {{ $plainToken['expiresAt'] ?? __('never') }}</p>
            <h3 class="font-bold text-sm">{{ __('Claude Code') }}</h3>
            <pre class="overflow-x-auto rounded bg-gray-900 p-2 text-xs text-white">claude mcp add --transport http statamic {{ $endpoint }} --header "Authorization: Bearer {{ $plainToken['token'] }}"</pre>
            <h3 class="font-bold text-sm">{{ __('Cursor (.cursor/mcp.json)') }}</h3>
            <pre class="overflow-x-auto rounded bg-gray-900 p-2 text-xs text-white">{{ json_encode(['mcpServers' => ['statamic' => ['url' => $endpoint, 'headers' => ['Authorization' => 'Bearer '.$plainToken['token']]]]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
        </div>
    @endif

    <div class="card p-4 space-y-3">
        <h2 class="font-bold">{{ __('Issue a new token') }}</h2>
        <form method="POST" action="{{ cp_route('utilities.mcp-tokens.store') }}" class="flex items-end gap-2">
            @csrf
            <div class="flex-1">
                <label class="block text-sm font-medium" for="mcp-token-name">{{ __('Name (optional)') }}</label>
                <input type="text" name="name" id="mcp-token-name" maxlength="100" placeholder="{{ __('e.g. claude-code laptop') }}" class="input-text w-full" value="{{ old('name') }}">
                @error('name') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium" for="mcp-token-expiry">{{ __('Expires') }}</label>
                <select name="expiry" id="mcp-token-expiry" class="select-input">
                    <option value="never">{{ __('Never') }}</option>
                    <option value="30">{{ __('30 days') }}</option>
                    <option value="90">{{ __('90 days') }}</option>
                    <option value="365">{{ __('365 days') }}</option>
                </select>
                @error('expiry') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <button type="submit" class="btn-primary">{{ __('Create token') }}</button>
        </form>
    </div>

    <div class="card p-4">
        <h2 class="font-bold mb-2">{{ $isSuper ? __('All tokens') : __('Your tokens') }}</h2>
        @if ($tokens->isEmpty())
            <p class="text-sm text-gray-600">{{ __('No tokens yet.') }}</p>
        @else
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left">
                        <th class="pb-1">{{ __('Name') }}</th>
                        @if ($isSuper)<th class="pb-1">{{ __('User') }}</th>@endif
                        <th class="pb-1">{{ __('Created') }}</th>
                        <th class="pb-1">{{ __('Expires') }}</th>
                        <th class="pb-1">{{ __('Status') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($tokens as $token)
                        <tr>
                            <td class="py-1">{{ $token['name'] ?? __('(unnamed)') }} <span class="text-gray-500 font-mono text-xs">{{ $token['id'] }}</span></td>
                            @if ($isSuper)<td class="py-1">{{ $token['email'] }}</td>@endif
                            <td class="py-1">{{ $token['created_at']->toFormattedDateString() }}</td>
                            <td class="py-1">{{ $token['expires_at']?->toFormattedDateString() ?? __('never') }}</td>
                            <td class="py-1">{{ $token['expired'] ? __('Expired') : __('Active') }}</td>
                            <td class="py-1 text-right">
                                <form method="POST" action="{{ cp_route('utilities.mcp-tokens.destroy', $token['id']) }}" onsubmit="return confirm('{{ __('Revoke this token?') }}')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-red-600">{{ __('Revoke') }}</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="card p-4 space-y-2">
        <h2 class="font-bold">{{ __('Connecting a client') }}</h2>
        <p class="text-sm">{{ __('MCP endpoint') }}: <code>{{ $endpoint }}</code></p>
        <p class="text-sm">{{ __('Works with Claude Code, Cursor, and any MCP client that can send a static Authorization header. Individual claude.ai and Claude Desktop connectors need OAuth mode instead — see the README client-compatibility matrix.') }}</p>
        <pre class="overflow-x-auto rounded bg-gray-900 p-2 text-xs text-white">claude mcp add --transport http statamic {{ $endpoint }} --header "Authorization: Bearer &lt;token&gt;"</pre>
    </div>

</div>
