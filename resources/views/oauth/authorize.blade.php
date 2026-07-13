{{--
    Default OAuth consent screen for MCP connectors.

    Deliberately self-contained — inline styles only, no @vite, no external
    fonts or asset dependencies — so it renders on any Statamic site the moment
    OAuth mode is switched on. laravel/mcp's own consent view depends on a
    compiled Tailwind/Vite bundle and 500s on sites without one; this never
    does. Its only job is "never 500, always overridable" — not to impose a
    look. Override it by publishing `statamic-mcp-views` and editing the copy,
    or by calling Passport::authorizationView(...) in your AppServiceProvider.

    Passport passes: $client, $user, $scopes (Laravel\Passport\Scope[]),
    $authToken, $request, and optionally $appearance.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Authorize {{ $client->name }} — {{ config('app.name', 'MCP') }}</title>
    <style>
        :root {
            color-scheme: light dark;
            --bg: #f4f4f5;
            --card: #ffffff;
            --border: #e4e4e7;
            --text: #18181b;
            --muted: #71717a;
            --panel: #fafafa;
            --accent: #4f46e5;
            --accent-text: #ffffff;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #09090b;
                --card: #18181b;
                --border: #27272a;
                --text: #fafafa;
                --muted: #a1a1aa;
                --panel: #232326;
                --accent: #6366f1;
                --accent-text: #ffffff;
            }
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            background: var(--bg);
            color: var(--text);
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            line-height: 1.5;
        }
        .card {
            width: 100%;
            max-width: 26rem;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 0.75rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
            overflow: hidden;
        }
        .body { padding: 1.75rem; }
        h1 {
            margin: 0 0 0.5rem;
            font-size: 1.25rem;
            font-weight: 600;
            text-align: center;
        }
        .lead {
            margin: 0 0 1.5rem;
            color: var(--muted);
            font-size: 0.875rem;
            text-align: center;
        }
        .panel {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 0.5rem;
            padding: 0.875rem 1rem;
            margin-bottom: 1.25rem;
        }
        .panel .label {
            font-size: 0.75rem;
            color: var(--muted);
            margin: 0 0 0.25rem;
        }
        .panel .value {
            font-size: 0.9375rem;
            font-weight: 500;
            margin: 0;
            word-break: break-word;
        }
        .scopes { list-style: none; margin: 0 0 1.25rem; padding: 0; }
        .scopes li {
            display: flex;
            align-items: flex-start;
            gap: 0.5rem;
            font-size: 0.875rem;
            color: var(--muted);
            padding: 0.25rem 0;
        }
        .scopes li::before {
            content: "";
            flex: none;
            width: 0.5rem;
            height: 0.5rem;
            margin-top: 0.4rem;
            border-radius: 9999px;
            background: var(--accent);
        }
        .actions { display: flex; gap: 0.75rem; }
        .actions form { flex: 1; margin: 0; }
        button {
            width: 100%;
            padding: 0.625rem 1rem;
            font-size: 0.875rem;
            font-weight: 500;
            font-family: inherit;
            border-radius: 0.5rem;
            border: 1px solid var(--border);
            cursor: pointer;
        }
        button.deny { background: var(--card); color: var(--text); }
        button.approve { background: var(--accent); color: var(--accent-text); border-color: transparent; }
        button:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
    </style>
</head>
<body>
    <main class="card">
        <div class="body">
            <h1>Authorize {{ $client->name }}</h1>
            <p class="lead">This application is requesting access to your account.</p>

            <div class="panel">
                <p class="label">Signed in as</p>
                <p class="value">{{ $user->email ?? $user->name ?? $user->getAuthIdentifier() }}</p>
            </div>

            @if (count($scopes) > 0)
                <ul class="scopes">
                    @foreach ($scopes as $scope)
                        <li>{{ $scope->description ?: $scope->id }}</li>
                    @endforeach
                </ul>
            @endif

            <div class="actions">
                <form method="POST" action="{{ route('passport.authorizations.deny') }}">
                    @csrf
                    @method('DELETE')
                    <input type="hidden" name="state" value="{{ $request->state ?? '' }}">
                    <input type="hidden" name="client_id" value="{{ $client->id }}">
                    <input type="hidden" name="auth_token" value="{{ $authToken }}">
                    <button type="submit" class="deny">Cancel</button>
                </form>

                <form method="POST" action="{{ route('passport.authorizations.approve') }}">
                    @csrf
                    <input type="hidden" name="state" value="{{ $request->state ?? '' }}">
                    <input type="hidden" name="client_id" value="{{ $client->id }}">
                    <input type="hidden" name="auth_token" value="{{ $authToken }}">
                    <button type="submit" class="approve">Authorize</button>
                </form>
            </div>
        </div>
    </main>
</body>
</html>
