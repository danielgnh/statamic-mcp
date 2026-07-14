{{-- Blade port of ui/Avatar.vue: image when available, otherwise initials on a deterministic mesh gradient. --}}
@props(['name' => null, 'seed' => null, 'src' => null, 'size' => 'base'])

@php
    $names = collect(explode(' ', trim((string) $name)))->filter()->values();
    $initials = match (true) {
        $names->isEmpty() => '?',
        $names->count() === 1 => \Statamic\Support\Str::upper(mb_substr($names->first(), 0, 1)),
        default => \Statamic\Support\Str::upper(mb_substr($names->first(), 0, 1).mb_substr($names->last(), 0, 1)),
    };
@endphp

@if ($src)
    <img
        src="{{ $src }}"
        alt="{{ $name }}"
        {{ $attributes->class(['ui-avatar', 'ui-avatar--lg' => $size === 'lg']) }}
    />
@else
    <div
        aria-label="{{ $name }}"
        style="background: {{ \Danielgnh\StatamicMcp\Support\AvatarGradient::for((string) ($seed ?? $name)) }}"
        {{ $attributes->class(['ui-avatar', 'ui-avatar--initials', 'ui-avatar--lg' => $size === 'lg']) }}
    >{{ $initials }}</div>
@endif
