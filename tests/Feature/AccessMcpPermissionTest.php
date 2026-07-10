<?php

use Statamic\Facades\Permission;

it('registers the access mcp permission in the mcp group', function () {
    // Registration is lazy: Permission::extend() only queues a callback.
    // boot() runs core permissions + all extensions — the CP triggers this
    // the same way before rendering the roles UI (verified 6.x source).
    Permission::boot();

    $permission = Permission::get('access mcp');

    expect($permission)->not->toBeNull()
        ->and($permission->label())->toBe('Access MCP');
});
