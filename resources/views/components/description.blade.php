{{-- Blade port of ui/Description.vue. --}}
@props(['text' => null])

<div {{ $attributes->class(['ui-description']) }} data-ui-description>
    {{ $slot->isEmpty() ? $text : $slot }}
</div>
