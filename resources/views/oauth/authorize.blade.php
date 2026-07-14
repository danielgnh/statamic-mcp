{{--
    Default OAuth consent screen for MCP connectors.

    Passport passes: $client, $user, $scopes (Laravel\Passport\Scope[]),
    $authToken, $request, and optionally $appearance. $user may be any
    authenticatable — Statamic-user methods are feature-detected.
--}}
@php
    $colorMode = method_exists($user, 'preferredColorMode') ? $user->preferredColorMode() : null;
    $colorMode = in_array($colorMode, ['light', 'dark'], true) ? $colorMode : null;

    $userName = $user->name ?? $user->email ?? 'User';
    $userIdentity = $user->email ?? $user->name ?? $user->getAuthIdentifier();
    $userAvatar = method_exists($user, 'avatar') ? $user->avatar() : null;
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="color-scheme" content="light dark">
    <title>Authorize {{ $client->name }} — {{ config('app.name', 'MCP') }}</title>
    <script>
        (function () {
            let mode = @js($colorMode) ?? localStorage.getItem('statamic.color_mode') ?? 'auto';
            if (mode === 'auto' && window.matchMedia('(prefers-color-scheme: dark)').matches) mode = 'dark';
            if (mode === 'dark') document.documentElement.classList.add('dark');
        })();
    </script>
    <style>
        :root {
            {{ \Statamic\CP\Color::cssVariables() }}

            &.dark {
                {{ \Statamic\CP\Color::cssVariables(dark: true) }}
            }

            /* Derived tokens, verbatim from the UI kit's ui.css */
            --primary: var(--theme-color-primary);
            --primary-border: color-mix(in oklch, var(--primary) 100%, black 20%);
            --primary-hover: color-mix(in oklch, var(--primary) 100%, black 30%);
            --shadow-ui-sm: 0px 2px 3px -2px rgba(0, 0, 0, 0.15);
            --shadow-ui-md: 0px 2px 4px -2px rgba(0, 0, 0, 0.37);
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 1.5rem;
            background: var(--theme-color-body-bg);
            font-family: Inter, ui-sans-serif, system-ui, sans-serif;
            font-size: 0.875rem;
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
        }

        main { width: 100%; max-width: 24rem; }

        /* ui/AuthCard.vue */
        .ui-auth-card {
            background: #fff;
            border: 1px solid var(--theme-color-gray-200);
            border-radius: 1rem;
            padding: 0.5rem;
            backdrop-filter: blur(2px);
            box-shadow: 0 8px 5px -6px rgba(0, 0, 0, 0.12), 0 3px 8px 0 rgba(0, 0, 0, 0.02), 0 30px 22px -22px rgba(39, 39, 42, 0.35);
        }
        .dark .ui-auth-card {
            background: var(--theme-color-gray-925);
            border-color: transparent;
            box-shadow: 0 8px 5px -6px rgba(0, 0, 0, 0.3), 0 3px 8px 0 rgba(0, 0, 0, 0.15), 0 30px 22px -22px rgba(0, 0, 0, 0.4);
        }
        .ui-auth-card__inner {
            position: relative;
            background: #fff;
            border: 1px solid var(--theme-color-gray-300);
            border-radius: 0.75rem;
            padding: 1rem;
            box-shadow: 0 1px 16px -2px rgba(63, 63, 71, 0.2);
        }
        .dark .ui-auth-card__inner {
            background: var(--theme-color-gray-850);
            border-color: var(--theme-color-gray-700);
        }
        .ui-auth-card__inner > * + * { margin-block-start: 0.75rem; }
        .ui-auth-card__header {
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            margin-bottom: 2rem;
            padding: 0.75rem 0;
        }
        /* ui/Card/Card.vue, as AuthCard uses it around its icon */
        .ui-auth-card__logo {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0.5rem;
            margin-bottom: 1rem;
            background: #fff;
            border-radius: 0.75rem;
            box-shadow: 0 0 0 1px var(--theme-color-gray-200), var(--shadow-ui-md);
        }
        .dark .ui-auth-card__logo {
            background: var(--theme-color-gray-850);
            box-shadow: inset 0 1px 0 0 color-mix(in oklch, var(--theme-color-gray-700) 80%, transparent), var(--shadow-ui-md);
        }
        .ui-auth-card__header .ui-description { text-align: center; margin-top: 0.375rem; }

        /* ui/Heading.vue */
        .ui-heading {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin: 0;
            font-weight: 500;
        }
        .ui-heading--xl { font-size: 1.125rem; color: var(--theme-color-gray-900); }
        .dark .ui-heading--xl { color: #fff; }

        /* ui/Description.vue */
        .ui-description {
            font-size: 0.875rem;
            font-weight: 400;
            color: color-mix(in oklch, var(--theme-color-gray-600) 90%, transparent);
        }
        .dark .ui-description { color: var(--theme-color-gray-400); }

        /* ui/Avatar.vue */
        .ui-avatar {
            flex: none;
            width: 1.75rem;
            height: 1.75rem;
            border-radius: 0.75rem;
            object-fit: cover;
        }
        .ui-avatar--initials {
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 0.7rem;
            font-weight: 500;
        }
        .ui-avatar--lg { width: 2.5rem; height: 2.5rem; font-size: 0.875rem; }

        /* ui/Button.vue, base size */
        .ui-button {
            position: relative;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            height: 2.5rem;
            padding: 0 1rem;
            gap: 0.5rem;
            border-radius: 0.5rem;
            font: inherit;
            font-weight: 500;
            white-space: nowrap;
            text-decoration: none;
            cursor: pointer;
            -webkit-font-smoothing: antialiased;
        }
        .ui-button:focus-visible { outline: 2px solid var(--theme-color-focus-outline); outline-offset: 2px; }
        .ui-button--default {
            background-color: var(--theme-color-gray-50);
            background-image: linear-gradient(to bottom, #fff, var(--theme-color-gray-50));
            border: 1px solid var(--theme-color-gray-300);
            color: var(--theme-color-gray-900);
            box-shadow: var(--shadow-ui-sm);
        }
        .ui-button--default:hover { background-image: linear-gradient(to bottom, #fff, var(--theme-color-gray-100)); }
        .dark .ui-button--default {
            background-color: var(--theme-color-gray-900);
            background-image: linear-gradient(to bottom, var(--theme-color-gray-850), var(--theme-color-gray-900));
            border-color: color-mix(in oklch, var(--theme-color-gray-700) 80%, transparent);
            color: var(--theme-color-gray-300);
            box-shadow: var(--shadow-ui-md);
        }
        .dark .ui-button--default:hover { background-image: linear-gradient(to bottom, var(--theme-color-gray-850), var(--theme-color-gray-850)); }
        .ui-button--primary {
            background-color: var(--primary);
            background-image: linear-gradient(to bottom, color-mix(in oklch, var(--primary) 90%, transparent), var(--primary));
            border: 1px solid var(--primary-border);
            color: #fff;
            box-shadow: inset 0 1px rgb(255 255 255 / 0.25), var(--shadow-ui-md);
        }
        .ui-button--primary:hover { background-color: var(--primary-hover); }

        /* Scope list, styled like a flat ui/Card list */
        .ui-scopes {
            list-style: none;
            margin: 0;
            padding: 0;
            border: 1px solid var(--theme-color-gray-200);
            border-radius: 0.75rem;
        }
        .dark .ui-scopes { border-color: color-mix(in oklch, var(--theme-color-gray-700) 80%, transparent); }
        .ui-scopes li {
            display: flex;
            align-items: flex-start;
            gap: 0.625rem;
            padding: 0.625rem 0.75rem;
            color: var(--theme-color-gray-700);
        }
        .dark .ui-scopes li { color: var(--theme-color-gray-300); }
        .ui-scopes li + li { border-top: 1px solid var(--theme-color-gray-200); }
        .dark .ui-scopes li + li { border-top-color: color-mix(in oklch, var(--theme-color-gray-700) 80%, transparent); }
        .ui-scopes svg { flex: none; width: 1rem; height: 1rem; margin-top: 0.1875rem; color: var(--theme-color-success); }

        .ui-profile {
            display: flex;
            align-items: center;
            gap: 0.625rem;
            min-width: 0;
        }
        .ui-profile div { min-width: 0; }
        .ui-profile__label {
            display: block;
            font-size: 0.7rem;
            color: var(--theme-color-gray-500);
        }
        .dark .ui-profile__label { color: var(--theme-color-gray-400); }
        .ui-profile__identity {
            display: block;
            font-weight: 500;
            color: var(--theme-color-gray-900);
            overflow-wrap: break-word;
        }
        .dark .ui-profile__identity { color: var(--theme-color-gray-100); }

        .ui-actions { display: flex; gap: 0.75rem; padding-top: 0.25rem; }
        .ui-actions form { flex: 1; margin: 0; }
        .ui-actions .ui-button { width: 100%; }
    </style>
</head>
<body>
    <main>
        <x-statamic-mcp::auth-card
            :title="$client->name"
            description="wants access to your {{ config('app.name', 'Statamic') }} account."
        >
            <x-slot:logo>
                <x-statamic-mcp::avatar :name="$client->name" size="lg" />
            </x-slot:logo>

            @if (count($scopes) > 0)
                <ul class="ui-scopes">
                    @foreach ($scopes as $scope)
                        <li>
                            {!! Statamic::svg('icons/checkmark') !!}
                            <span>{{ $scope->description ?: $scope->id }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif

            <div class="ui-profile">
                <x-statamic-mcp::avatar :name="$userName" :seed="$userIdentity" :src="$userAvatar" />
                <div>
                    <span class="ui-profile__label">Signed in as</span>
                    <span class="ui-profile__identity">{{ $userIdentity }}</span>
                </div>
            </div>

            <div class="ui-actions">
                <form method="POST" action="{{ route('passport.authorizations.deny') }}">
                    @csrf
                    @method('DELETE')
                    <input type="hidden" name="state" value="{{ $request->state ?? '' }}">
                    <input type="hidden" name="client_id" value="{{ $client->id }}">
                    <input type="hidden" name="auth_token" value="{{ $authToken }}">
                    <x-statamic-mcp::button type="submit" text="Cancel" />
                </form>

                <form method="POST" action="{{ route('passport.authorizations.approve') }}">
                    @csrf
                    <input type="hidden" name="state" value="{{ $request->state ?? '' }}">
                    <input type="hidden" name="client_id" value="{{ $client->id }}">
                    <input type="hidden" name="auth_token" value="{{ $authToken }}">
                    <x-statamic-mcp::button type="submit" variant="primary" text="Authorize" />
                </form>
            </div>
        </x-statamic-mcp::auth-card>
    </main>
</body>
</html>
