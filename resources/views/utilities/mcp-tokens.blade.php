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

    The page shows exactly one auth mode: whichever `statamic.mcp.auth` names.
    The other mode's UI is not dimmed or explained — it is simply absent. The
    one exception is tokens left behind when a site switches to OAuth: those
    stay listed so they can be revoked, and disappear once they are.
--}}
<div class="max-w-5xl 3xl:max-w-6xl mx-auto" data-max-width-wrapper>

    <ui-header title="{{ __('MCP Access') }}" icon="key">
        <template #actions>
            <ui-modal title="{{ __('How to connect') }}" icon="info">
                <template #trigger>
                    <ui-button icon="info" text="{{ __('How to connect') }}"></ui-button>
                </template>

                <ui-field label="{{ __('MCP endpoint') }}">
                    <ui-input read-only copyable model-value="{{ $endpoint }}"></ui-input>
                </ui-field>

                @if ($oauthMode)
                    <ui-description text="{{ __('Clients register themselves and each person signs in with their own Statamic account — there is nothing to paste but the endpoint.') }}"></ui-description>

                    <ui-subheading text="{{ __('claude.ai, Claude Desktop, ChatGPT') }}"></ui-subheading>
                    <ui-description text="{{ __('Add a custom connector, paste the endpoint, and approve access when the sign-in window opens.') }}"></ui-description>

                    <ui-subheading text="{{ __('Claude Code') }}"></ui-subheading>
                    <pre v-pre class="overflow-x-auto rounded-lg bg-gray-900 p-3 text-xs text-gray-300">claude mcp add --transport http statamic {{ $endpoint }}</pre>
                    <ui-description text="{{ __('Then run /mcp and choose Authenticate.') }}"></ui-description>
                @else
                    <ui-description text="{{ __('Works with Claude Code, Cursor, and any MCP client that can send a static Authorization header. Individual claude.ai and Claude Desktop connectors need OAuth mode instead — see the README client-compatibility matrix.') }}"></ui-description>

                    <ui-subheading text="{{ __('Claude Code') }}"></ui-subheading>
                    <pre v-pre class="overflow-x-auto rounded-lg bg-gray-900 p-3 text-xs text-gray-300">claude mcp add --transport http statamic {{ $endpoint }} --header "Authorization: Bearer &lt;token&gt;"</pre>

                    <ui-subheading text="{{ __('Cursor (.cursor/mcp.json)') }}"></ui-subheading>
                    <pre v-pre class="overflow-x-auto rounded-lg bg-gray-900 p-3 text-xs text-gray-300">{{ json_encode(['mcpServers' => ['statamic' => ['url' => $endpoint, 'headers' => ['Authorization' => 'Bearer <token>']]]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                @endif
            </ui-modal>

            @unless ($oauthMode)
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
            @endunless
        </template>
    </ui-header>

    @if ($lacksAccessMcp || $insecureUrl)
        <div class="space-y-4 mb-8">
            @if ($lacksAccessMcp)
                @if ($oauthMode)
                    <ui-alert variant="warning" text="{{ __('Your account does not have the "Access MCP" permission — you can complete the sign-in flow, but every request will be denied until an administrator grants it to one of your roles.') }}"></ui-alert>
                @else
                    <ui-alert variant="warning" text="{{ __('Your account does not have the "Access MCP" permission — tokens you issue will authenticate but every request will be denied until an administrator grants it to one of your roles.') }}"></ui-alert>
                @endif
            @endif

            @if ($insecureUrl)
                <ui-alert variant="warning" text="{{ __('The MCP endpoint is not HTTPS — credentials travel unencrypted. Set APP_URL to your real https:// site URL.') }}"></ui-alert>
            @endif
        </div>
    @endif

    @if ($oauthMode)

        <ui-panel heading="{{ $isSuper ? __('All connections') : __('Your connections') }}">
            @unless ($oauthReady)
                <ui-card>
                    <ui-alert variant="warning" text="{{ __('OAuth mode is enabled but not fully configured — run php please mcp:doctor for the exact remedy.') }}"></ui-alert>
                </ui-card>
            @elseif ($connections->isEmpty())
                <ui-card>
                    {{-- ui-empty-state-item is a menu item (an <li> that renders a hoverable button) meant for ui-empty-state-menu, so a plain centered block is used instead. --}}
                    <div class="flex flex-col items-center gap-2 py-10 text-center">
                        <ui-icon name="link" class="size-6 text-gray-400"></ui-icon>
                        <ui-heading size="lg" :level="3" text="{{ __('No connections yet') }}"></ui-heading>
                        <ui-description class="max-w-md" text="{{ __('Connections appear here when a connector (claude.ai, ChatGPT) adds this site and a user completes the consent flow.') }}"></ui-description>
                    </div>
                </ui-card>
            @else
                <ui-card inset class="overflow-x-auto">
                    <table class="data-table data-table--contained" data-table>
                        <thead>
                            <tr>
                                <th>{{ __('Client') }}</th>
                                @if ($isSuper)<th>{{ __('User') }}</th>@endif
                                <th>{{ __('Connected') }}</th>
                                <th>{{ __('Last refreshed') }}</th>
                                <th>{{ __('Status') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($connections as $connection)
                                <tr>
                                    <td><span v-pre>{{ $connection['client_name'] }}</span></td>
                                    @if ($isSuper)<td><span v-pre>{{ $connection['email'] }}</span></td>@endif
                                    <td>{{ $connection['connected_at']->toFormattedDateString() }}</td>
                                    <td>{{ $connection['last_refreshed_at']->diffForHumans() }}</td>
                                    <td>
                                        @if ($connection['active'])
                                            <ui-badge color="green" pill>{{ __('Active') }}</ui-badge>
                                        @else
                                            <ui-badge color="red" pill>{{ __('Expired') }}</ui-badge>
                                        @endif
                                    </td>
                                    <td class="text-right">
                                        <form method="POST" action="{{ cp_route('utilities.mcp-tokens.connections.destroy', [$connection['client_id'], $connection['user_id']]) }}" onsubmit="return confirm({{ \Illuminate\Support\Js::from(__('Disconnect this client? It will have to re-authorize before it can reconnect.')) }})">
                                            @csrf
                                            @method('DELETE')
                                            <ui-button type="submit" size="sm" variant="danger" text="{{ __('Disconnect') }}"></ui-button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </ui-card>
            @endif
        </ui-panel>

        {{-- Leftovers from a site that ran in token mode. Nothing accepts them
             now, but they must stay revokable — so the panel exists only while
             they do, and no new ones can be issued from here. --}}
        @if ($tokens->isNotEmpty())
            <ui-panel heading="{{ $isSuper ? __('Leftover tokens') : __('Your leftover tokens') }}" subheading="{{ __('Issued before OAuth mode was turned on. They are no longer accepted — revoke any you do not plan to come back to.') }}">
                @include('statamic-mcp::utilities.partials.token-table')
            </ui-panel>
        @endif

    @else

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
                    <div class="flex flex-col items-center gap-2 py-10 text-center">
                        <ui-icon name="key" class="size-6 text-gray-400"></ui-icon>
                        <ui-heading size="lg" :level="3" text="{{ __('No tokens yet') }}"></ui-heading>
                        <ui-description class="max-w-md" text="{{ __('Use the Create token button to issue your first token and connect an MCP client.') }}"></ui-description>
                    </div>
                </ui-card>
            @else
                @include('statamic-mcp::utilities.partials.token-table')
            @endif
        </ui-panel>

    @endif

</div>
