{{--
    Shared by the token-mode list and the leftover-token list OAuth mode keeps
    around. Same rows, same revoke action — only the panel around it differs.
    User-sourced strings render inside v-pre spans (see the parent view).
--}}
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
