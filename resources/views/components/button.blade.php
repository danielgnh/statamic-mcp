{{-- Blade port of ui/Button.vue (base size). Variants: default, primary. --}}
@props(['variant' => 'default', 'type' => 'button', 'text' => null])

<button
    {{ $attributes->merge(['type' => $type])->class(['ui-button', "ui-button--{$variant}"]) }}
>{{ $slot->isEmpty() ? $text : $slot }}</button>
