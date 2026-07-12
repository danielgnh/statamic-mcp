<?php

use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tokens\TokenRepository;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    File::delete(storage_path('statamic/mcp/tokens.yaml'));
});

it('issues a first token via the token path', function () {
    $user = Fixtures::makeUser();

    $this->artisan('statamic:mcp:setup')
        ->expectsChoice(
            'How will AI clients connect to this site?',
            'token',
            [
                'token' => 'Token — Claude Code, Cursor, MCP Inspector',
                'oauth' => 'OAuth — claude.ai, Claude Desktop, ChatGPT connectors',
            ]
        )
        ->expectsQuestion('Which Statamic user should the first token act as?', $user->email())
        ->assertExitCode(0);

    expect(app(TokenRepository::class)->all())->toHaveCount(1);
});

it('fails cleanly when the email matches no user', function () {
    $this->artisan('statamic:mcp:setup')
        ->expectsChoice(
            'How will AI clients connect to this site?',
            'token',
            [
                'token' => 'Token — Claude Code, Cursor, MCP Inspector',
                'oauth' => 'OAuth — claude.ai, Claude Desktop, ChatGPT connectors',
            ]
        )
        ->expectsQuestion('Which Statamic user should the first token act as?', 'ghost@nowhere.test')
        ->assertExitCode(1);

    expect(app(TokenRepository::class)->all())->toHaveCount(0);
});
