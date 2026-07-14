{{--
    Blade port of ui/AuthCard.vue: double-walled card with an optional
    centered header. The Vue version takes an icon name; this one takes a
    `logo` slot instead so the consent screen can put an avatar there.
--}}
@props(['title' => null, 'description' => null])

<div {{ $attributes->class(['ui-auth-card']) }}>
    <div class="ui-auth-card__inner">
        @if (isset($logo) || $title || $description)
            <header class="ui-auth-card__header">
                @isset($logo)
                    <div class="ui-auth-card__logo">{{ $logo }}</div>
                @endisset
                @if ($title)
                    <x-statamic-mcp::heading :level="1" size="xl" :text="$title" />
                @endif
                @if ($description)
                    <x-statamic-mcp::description :text="$description" />
                @endif
            </header>
        @endif
        {{ $slot }}
    </div>
</div>
