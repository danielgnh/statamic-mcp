{{--
    The CP renders this view through its dynamic-html-renderer, which compiles
    the output as a Vue template at runtime. That is why <ui-*> components work
    here without any build step — and why every user-sourced string must sit
    inside a v-pre span: curly braces survive Blade's HTML escaping and would
    otherwise execute as Vue expressions in the viewer's session.

    Success/error session flashes surface as CP toasts automatically
    (HandleInertiaRequests), so this view renders no flash banners.

    The utilities/Show wrapper only sets the document title — the visible page
    header and the width wrapper are each page's own job (core's Email/Cache
    utilities do the same), hence ui-header and the max-w wrapper here.
    Vertical rhythm comes from ui-panel's built-in bottom margin.
--}}
<div class="max-w-5xl 3xl:max-w-6xl mx-auto" data-max-width-wrapper>

    <ui-header title="{{ __('MCP Tokens') }}" icon="key">
        <template #actions>
            <ui-modal title="{{ __('How to connect') }}" icon="info">
                <template #trigger>
                    <ui-button icon="info" text="{{ __('How to connect') }}"></ui-button>
                </template>

                <ui-field label="{{ __('MCP endpoint') }}">
                    <ui-input read-only copyable model-value="{{ $endpoint }}"></ui-input>
                </ui-field>

                <ui-description text="{{ __('Works with Claude Code, Cursor, and any MCP client that can send a static Authorization header. Individual claude.ai and Claude Desktop connectors need OAuth mode instead — see the README client-compatibility matrix.') }}"></ui-description>

                <ui-subheading text="{{ __('Claude Code') }}"></ui-subheading>
                <pre v-pre class="overflow-x-auto rounded-lg bg-gray-900 p-3 text-xs text-gray-300">claude mcp add --transport http statamic {{ $endpoint }} --header "Authorization: Bearer &lt;token&gt;"</pre>

                <ui-subheading text="{{ __('Cursor (.cursor/mcp.json)') }}"></ui-subheading>
                <pre v-pre class="overflow-x-auto rounded-lg bg-gray-900 p-3 text-xs text-gray-300">{{ json_encode(['mcpServers' => ['statamic' => ['url' => $endpoint, 'headers' => ['Authorization' => 'Bearer <token>']]]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
            </ui-modal>

            {{-- A failed native POST redirects back with errors and the modal
                 closed — the static `open` attribute reopens it on mount so
                 the validation messages are actually seen. --}}
            <ui-modal title="{{ __('Issue a new token') }}" icon="key" @if ($errors->hasAny(['name', 'expiry'])) open @endif>
                <template #trigger>
                    <ui-button variant="primary" icon="plus" text="{{ __('Create token') }}"></ui-button>
                </template>

                <form method="POST" action="{{ cp_route('utilities.mcp-tokens.store') }}" class="space-y-4">
                    @csrf
                    <ui-field label="{{ __('Name (optional)') }}" @if ($errors->has('name')) error="{{ $errors->first('name') }}" @endif>
                        <ui-input name="name" maxlength="100" placeholder="{{ __('e.g. claude-code laptop') }}" model-value="{{ old('name') }}"></ui-input>
                    </ui-field>
                    <ui-field label="{{ __('Expires') }}" @if ($errors->has('expiry')) error="{{ $errors->first('expiry') }}" @endif>
                        {{-- ui-select is v-model-only and cannot join a native form post, so this stays a native select dressed in the ui-input classes. --}}
                        <select name="expiry" class="block h-10 w-full rounded-lg border border-gray-300 bg-white px-3 text-base text-gray-925 shadow-ui-sm antialiased dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                            <option value="never" @selected(old('expiry') === 'never')>{{ __('Never') }}</option>
                            <option value="30" @selected(old('expiry') === '30')>{{ __('30 days') }}</option>
                            <option value="90" @selected(old('expiry') === '90')>{{ __('90 days') }}</option>
                            <option value="365" @selected(old('expiry') === '365')>{{ __('365 days') }}</option>
                        </select>
                    </ui-field>
                    <div class="flex justify-end">
                        <ui-button type="submit" variant="primary" text="{{ __('Create token') }}"></ui-button>
                    </div>
                </form>
            </ui-modal>
        </template>
    </ui-header>

    @if ($lacksAccessMcp || $oauthMode || $insecureUrl)
        <div class="space-y-4 mb-8">
            @if ($lacksAccessMcp)
                <ui-alert variant="warning" text="{{ __('Your account does not have the "Access MCP" permission — tokens you issue will authenticate but every request will be denied until an administrator grants it to one of your roles.') }}"></ui-alert>
            @endif

            @if ($oauthMode)
                <ui-alert text="{{ __('This site is in OAuth mode — bearer tokens are not accepted until the auth mode is switched back to token. You can still manage tokens here.') }}"></ui-alert>
            @endif

            @if ($insecureUrl)
                <ui-alert variant="warning" text="{{ __('The MCP endpoint is not HTTPS — bearer tokens travel unencrypted. Set APP_URL to your real https:// site URL.') }}"></ui-alert>
            @endif
        </div>
    @endif

    @if ($plainToken)
        <ui-panel heading="{{ __('Token created') }}" subheading="{{ __('This is the ONLY time it will be displayed. Copy it now.') }}">
            <ui-card class="space-y-4">
                <ui-input read-only copyable model-value="{{ $plainToken['token'] }}"></ui-input>
                <ui-description text="{{ __('Expires') }}: {{ $plainToken['expiresAt'] ?? __('never') }}"></ui-description>

                <div class="space-y-2">
                    <ui-subheading text="{{ __('Claude Code') }}"></ui-subheading>
                    <pre v-pre class="overflow-x-auto rounded-lg bg-gray-900 p-3 text-xs text-gray-300">claude mcp add --transport http statamic {{ $endpoint }} --header "Authorization: Bearer {{ $plainToken['token'] }}"</pre>
                </div>

                <div class="space-y-2">
                    <ui-subheading text="{{ __('Cursor (.cursor/mcp.json)') }}"></ui-subheading>
                    <pre v-pre class="overflow-x-auto rounded-lg bg-gray-900 p-3 text-xs text-gray-300">{{ json_encode(['mcpServers' => ['statamic' => ['url' => $endpoint, 'headers' => ['Authorization' => 'Bearer '.$plainToken['token']]]]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                </div>
            </ui-card>
        </ui-panel>
    @endif

    <ui-panel heading="{{ $isSuper ? __('All tokens') : __('Your tokens') }}">
        @if ($tokens->isEmpty())
            <ui-card>
                <ui-empty-state-item icon="key" heading="{{ __('No tokens yet') }}" description="{{ __('Use the Create token button to issue your first token and connect an MCP client.') }}"></ui-empty-state-item>
            </ui-card>
        @else
            <ui-card inset class="overflow-x-auto">
                <table class="data-table data-table--contained" data-table>
                    <thead>
                        <tr>
                            <th>{{ __('Name') }}</th>
                            @if ($isSuper)<th>{{ __('User') }}</th>@endif
                            <th>{{ __('Created') }}</th>
                            <th>{{ __('Expires') }}</th>
                            <th>{{ __('Status') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($tokens as $token)
                            <tr>
                                <td>
                                    <div class="flex items-center gap-2">
                                        <span v-pre>{{ $token['name'] ?? __('(unnamed)') }}</span>
                                        <ui-badge size="sm" class="font-mono"><span v-pre>{{ $token['id'] }}</span></ui-badge>
                                    </div>
                                </td>
                                @if ($isSuper)<td><span v-pre>{{ $token['email'] }}</span></td>@endif
                                <td>{{ $token['created_at']->toFormattedDateString() }}</td>
                                <td>{{ $token['expires_at']?->toFormattedDateString() ?? __('never') }}</td>
                                <td>
                                    @if ($token['expired'])
                                        <ui-badge color="red" pill>{{ __('Expired') }}</ui-badge>
                                    @else
                                        <ui-badge color="green" pill>{{ __('Active') }}</ui-badge>
                                    @endif
                                </td>
                                <td class="text-right">
                                    <form method="POST" action="{{ cp_route('utilities.mcp-tokens.destroy', $token['id']) }}" onsubmit="return confirm({{ \Illuminate\Support\Js::from(__('Revoke this token?')) }})">
                                        @csrf
                                        @method('DELETE')
                                        <ui-button type="submit" size="sm" variant="danger" text="{{ __('Revoke') }}"></ui-button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </ui-card>
        @endif
    </ui-panel>

</div>
