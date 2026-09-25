@extends('statamic::layout')
@section('title', Statamic::crumb(__('Guidelines'), __('MCP')))

@section('content')
    {{--
        The CP compiles this page as a Vue template at runtime, after the
        browser has parsed it as HTML: hence kebab-case props, JSON in
        single-quoted attributes, and an explicit closing tag.
    --}}
    <div class="max-w-5xl 3xl:max-w-6xl mx-auto" data-max-width-wrapper>
        <ui-publish-form
            title="{{ __('Guidelines') }}"
            icon="ai-chat-spark"
            :blueprint='@json($blueprint)'
            :initial-values='@json($values)'
            :initial-meta='@json($meta)'
            submit-url="{{ cp_route('mcp.guidelines.update') }}"
            as-config
        ></ui-publish-form>
    </div>
@endsection
