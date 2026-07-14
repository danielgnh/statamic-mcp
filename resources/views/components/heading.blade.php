{{-- Blade port of ui/Heading.vue. Sizes: base, lg, xl, 2xl. --}}
@props(['level' => null, 'size' => 'base', 'text' => null])

@php $tag = $level ? "h{$level}" : 'div'; @endphp

<{{ $tag }} {{ $attributes->class(['ui-heading', "ui-heading--{$size}"]) }} data-ui-heading>
    {{ $slot->isEmpty() ? $text : $slot }}
</{{ $tag }}>
