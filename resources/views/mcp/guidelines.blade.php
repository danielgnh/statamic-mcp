@extends('statamic::layout')
@section('title', Statamic::crumb(__('Guidelines'), __('MCP')))

@section('content')
    {{--
        The CP compiles this page as a Vue template at runtime, after the
        browser has parsed it as HTML: hence kebab-case props, JSON in
        single-quoted attributes, and an explicit closing tag.
    --}}
    <div class="max-w-5xl 3xl:max-w-6xl mx-auto" data-max-width-wrapper>
        @if ($leftoverSet && $agentsReadLeftoverSet)
            <ui-alert variant="warning" class="mb-6" text="{{ __('Agents still read the guidelines in the :handle global set from version 0.6.0. Run php please mcp:guidelines to move them here and delete the set.', ['handle' => $leftoverSet]) }}"></ui-alert>
        @elseif ($leftoverSet)
            <ui-alert variant="warning" class="mb-6" text="{{ __('The :handle global set from version 0.6.0 is still under Globals, but agents read this page now. Delete the set once nothing in it is missing.', ['handle' => $leftoverSet]) }}"></ui-alert>
        @endif

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
