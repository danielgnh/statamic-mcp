<?php

use Danielgnh\StatamicMcp\Setup\EditResult;
use Danielgnh\StatamicMcp\Setup\EnvWriter;

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
