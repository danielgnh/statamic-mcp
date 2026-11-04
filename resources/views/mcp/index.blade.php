@extends('statamic::layout')
@section('title', __('MCP'))

@section('content')
    <div class="bg-architectural-lines">
        <header class="py-8 pt-16 text-center">
            <h1 class="text-[25px] font-medium antialiased flex justify-center items-center gap-2 sm:gap-3">
                <ui-icon name="ai-sparks" class="size-5 text-gray-500"></ui-icon>
                {{ __('MCP') }}
            </h1>
        </header>

        <ui-empty-state-menu heading="{{ __('Configure MCP') }}" subheading="{{ __('Choose what you want to set up.') }}">
            <ui-empty-state-item
                href="{{ cp_route('mcp.connections.index') }}"
                icon="key"
                heading="{{ __('Connections') }}"
                description="{{ __('Connect AI clients and manage their access.') }}"
            ></ui-empty-state-item>
            <ui-empty-state-item
                href="{{ cp_route('mcp.guidelines.edit') }}"
                icon="ai-chat-spark"
                heading="{{ __('Guidelines') }}"
                description="{{ __('Tell agents how to write for this site, from its voice to how entries are put together.') }}"
            ></ui-empty-state-item>
        </ui-empty-state-menu>
    </div>
@endsection
