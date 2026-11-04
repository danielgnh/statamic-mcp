<?php

use Danielgnh\StatamicMcp\Setup\EditResult;
use Danielgnh\StatamicMcp\Setup\EnvWriter;
use Dotenv\Dotenv;

beforeEach(function () {
    $this->path = tempnam(sys_get_temp_dir(), 'mcp-env-');
});

afterEach(function () {
    @unlink($this->path);
});

it('appends the key when absent', function () {
    file_put_contents($this->path, "APP_NAME=Statamic\n");

    $result = (new EnvWriter)->apply($this->path, 'STATAMIC_MCP_AUTH', 'oauth');

    expect($result)->toBe(EditResult::Applied)
        ->and(file_get_contents($this->path))->toBe("APP_NAME=Statamic\nSTATAMIC_MCP_AUTH=oauth\n");
});

it('replaces an existing value in place without touching other lines', function () {
    file_put_contents($this->path, "STATAMIC_MCP_AUTH=token\nAPP_NAME=Statamic\n");

    $result = (new EnvWriter)->apply($this->path, 'STATAMIC_MCP_AUTH', 'oauth');

    expect($result)->toBe(EditResult::Applied)
        ->and(file_get_contents($this->path))->toBe("STATAMIC_MCP_AUTH=oauth\nAPP_NAME=Statamic\n");
});

it('does not mistake a suffixed key for the key', function () {
    file_put_contents($this->path, "NOT_STATAMIC_MCP_AUTH=x\n");

    (new EnvWriter)->apply($this->path, 'STATAMIC_MCP_AUTH', 'oauth');

    expect(file_get_contents($this->path))->toBe("NOT_STATAMIC_MCP_AUTH=x\nSTATAMIC_MCP_AUTH=oauth\n");
});

it('skips when the value is already set', function () {
    file_put_contents($this->path, "STATAMIC_MCP_AUTH=oauth\n");

    expect((new EnvWriter)->apply($this->path, 'STATAMIC_MCP_AUTH', 'oauth'))->toBe(EditResult::Skipped)
        ->and(file_get_contents($this->path))->toBe("STATAMIC_MCP_AUTH=oauth\n");
});

it('treats regex metacharacters in the value as literal text', function () {
    file_put_contents($this->path, "APP_KEY=old\n");

    (new EnvWriter)->apply($this->path, 'APP_KEY', 'pa$1s\2s');

    expect(file_get_contents($this->path))->toBe('APP_KEY=pa$1s\2s'."\n");
});

it('bails when the file does not exist', function () {
    expect((new EnvWriter)->apply('/nonexistent/.env', 'K', 'v'))->toBe(EditResult::Bailed);
});

it('rewrites every assignment of a key that appears twice, because the last one wins', function () {
    file_put_contents($this->path, "STATAMIC_MCP_AUTH=token\nAPP_NAME=Statamic\nSTATAMIC_MCP_AUTH=token\n");

    $result = (new EnvWriter)->apply($this->path, 'STATAMIC_MCP_AUTH', 'oauth');

    expect($result)->toBe(EditResult::Applied)
        ->and(file_get_contents($this->path))->toBe("STATAMIC_MCP_AUTH=oauth\nAPP_NAME=Statamic\nSTATAMIC_MCP_AUTH=oauth\n");
});

it('rewrites a later assignment when only the first already has the value', function () {
    file_put_contents($this->path, "STATAMIC_MCP_AUTH=oauth\nSTATAMIC_MCP_AUTH=token\n");

    $result = (new EnvWriter)->apply($this->path, 'STATAMIC_MCP_AUTH', 'oauth');

    expect($result)->toBe(EditResult::Applied)
        ->and(file_get_contents($this->path))->toBe("STATAMIC_MCP_AUTH=oauth\nSTATAMIC_MCP_AUTH=oauth\n");
});

it('skips when every assignment already has the value', function () {
    file_put_contents($this->path, "STATAMIC_MCP_AUTH=oauth\nSTATAMIC_MCP_AUTH=oauth\n");

    expect((new EnvWriter)->apply($this->path, 'STATAMIC_MCP_AUTH', 'oauth'))->toBe(EditResult::Skipped);
});

it('rewrites an exported assignment too, because dotenv reads it as the same key', function () {
    file_put_contents($this->path, "STATAMIC_MCP_AUTH=oauth\nexport STATAMIC_MCP_AUTH=token\n");

    $result = (new EnvWriter)->apply($this->path, 'STATAMIC_MCP_AUTH', 'oauth');

    expect($result)->toBe(EditResult::Applied)
        ->and(file_get_contents($this->path))->toBe("STATAMIC_MCP_AUTH=oauth\nexport STATAMIC_MCP_AUTH=oauth\n");
});

it('rewrites every spelling of the key dotenv reads', function (string $line) {
    file_put_contents($this->path, "STATAMIC_MCP_AUTH=oauth\n{$line}\n");

    expect((new EnvWriter)->apply($this->path, 'STATAMIC_MCP_AUTH', 'oauth'))->toBe(EditResult::Applied)
        ->and(Dotenv::parse(file_get_contents($this->path))['STATAMIC_MCP_AUTH'])->toBe('oauth');
})->with([
    'spaces around =' => 'STATAMIC_MCP_AUTH = token',
    'indented' => '  STATAMIC_MCP_AUTH=token',
    'exported' => 'export STATAMIC_MCP_AUTH=token',
]);
