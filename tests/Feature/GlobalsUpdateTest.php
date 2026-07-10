<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\GlobalsUpdate;
use Illuminate\Support\Facades\Event;
use Statamic\Contracts\Globals\GlobalRepository;
use Statamic\Events\GlobalVariablesSaving;
use Statamic\Facades\Blink;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Stache;

it('shallow-merges data into the default site localization', function () {
    Fixtures::site();
    Fixtures::settings();

    $user = Fixtures::makeUser('edit settings globals');

    Server::actingAs($user)
        ->tool(GlobalsUpdate::class, [
            'handle' => 'settings',
            'data' => ['footer_text' => 'Hi'],
        ])
        ->assertOk()
        ->assertSee('"site_name":"Acme"')
        ->assertSee('"footer_text":"Hi"')
        ->assertSee('updated — live');

    // Rehydrate from disk: the Stache array cache aliases in-request
    // instances — only a cleared cache proves the write persisted.
    Stache::clear();

    expect(GlobalSet::findByHandle('settings')->in('en')->data()->all())
        ->toEqual(['site_name' => 'Acme', 'footer_text' => 'Hi']);
});

it('creates a missing site localization transparently on first write', function () {
    Fixtures::multisite();
    Fixtures::settings();

    GlobalSet::findByHandle('settings')->sites(['en', 'de'])->save();

    $user = Fixtures::makeUser('edit settings globals', 'access de site');

    Server::actingAs($user)
        ->tool(GlobalsUpdate::class, [
            'handle' => 'settings',
            'data' => ['site_name' => 'Acme DE'],
            'site' => 'de',
        ])
        ->assertOk()
        ->assertSee('"site":"de"');

    Stache::clear();

    expect(GlobalSet::findByHandle('settings')->in('de')->data()->all())
        ->toEqual(['site_name' => 'Acme DE']);
});

it('stores an explicit null to clear a variable', function () {
    Fixtures::site();
    Fixtures::settings();

    GlobalSet::findByHandle('settings')->in('en')
        ->data(['site_name' => 'Acme', 'footer_text' => 'Old'])->save();

    Server::actingAs(Fixtures::makeUser('edit settings globals'))
        ->tool(GlobalsUpdate::class, [
            'handle' => 'settings',
            'data' => ['footer_text' => null],
        ])
        ->assertOk();

    Stache::clear();

    expect(GlobalSet::findByHandle('settings')->in('en')->data()->get('footer_text'))
        ->toBeNull();
});

it('rejects unknown variable keys with a did-you-mean hint', function () {
    Fixtures::site();
    Fixtures::settings();

    $user = Fixtures::makeUser('edit settings globals');

    Server::actingAs($user)
        ->tool(GlobalsUpdate::class, [
            'handle' => 'settings',
            'data' => ['site_nam' => 'X'],
        ])
        ->assertHasErrors()
        ->assertSee("did you mean 'site_name' instead of 'site_nam'?");
});

it('accepts free-form keys on a set without a blueprint', function () {
    Fixtures::site();

    // No 'globals.footer' blueprint: Statamic's fallback blueprint is
    // generated from CURRENT values, so it must never reject new keys.
    $footer = GlobalSet::make('footer')->title('Footer');
    $footer->save();
    $footer->in('en')->data(['tagline' => 'Bye'])->save();

    Server::actingAs(Fixtures::makeUser('edit footer globals'))
        ->tool(GlobalsUpdate::class, [
            'handle' => 'footer',
            'data' => ['copyright' => '2026 Acme'],
        ])
        ->assertOk()
        ->assertSee('"copyright":"2026 Acme"');

    Stache::clear();

    expect(GlobalSet::findByHandle('footer')->in('en')->data()->all())
        ->toEqual(['tagline' => 'Bye', 'copyright' => '2026 Acme']);
});

it('rejects reserved keys even on a set without a blueprint', function () {
    Fixtures::site();

    $footer = GlobalSet::make('footer')->title('Footer');
    $footer->save();

    // The global-variables Stache store silently strips an 'origin' data key
    // on rehydration (vendor GlobalVariablesStore) — a reserved-key write
    // would be silent data loss on the next read.
    Server::actingAs(Fixtures::makeUser('edit footer globals'))
        ->tool(GlobalsUpdate::class, [
            'handle' => 'footer',
            'data' => ['origin' => 'en'],
        ])
        ->assertHasErrors(['field origin is reserved — never writable via data']);
});

it('is a no-op when the merged result equals current data', function () {
    Fixtures::site();
    Fixtures::settings();

    $user = Fixtures::makeUser('edit settings globals');

    Server::actingAs($user)
        ->tool(GlobalsUpdate::class, [
            'handle' => 'settings',
            'data' => ['site_name' => 'Acme'],
        ])
        ->assertOk()
        ->assertSee('no-op — merged data equals current data; nothing saved');
});

it('does not mistake a null-clear of an empty-string value for a no-op', function () {
    Fixtures::site();
    Fixtures::settings();

    GlobalSet::findByHandle('settings')->in('en')
        ->data(['site_name' => 'Acme', 'footer_text' => ''])->save();

    // Loose comparison would juggle null == '' into a false no-op,
    // silently dropping the write (the ComparesPatchData strict rule).
    Server::actingAs(Fixtures::makeUser('edit settings globals'))
        ->tool(GlobalsUpdate::class, [
            'handle' => 'settings',
            'data' => ['footer_text' => null],
        ])
        ->assertOk()
        ->assertSee('updated — live');

    Stache::clear();

    expect(GlobalSet::findByHandle('settings')->in('en')->data()->get('footer_text'))
        ->toBeNull();
});

it('rejects preview objects round-tripped from globals_get', function () {
    Fixtures::site();
    Fixtures::settings();

    Server::actingAs(Fixtures::makeUser('edit settings globals'))
        ->tool(GlobalsUpdate::class, [
            'handle' => 'settings',
            'data' => ['footer_text' => ['__preview' => 'A long…', 'truncated' => true, 'note' => 'NOT writable']],
        ])
        ->assertHasErrors()
        ->assertSee('is a truncated preview object');
});

it('reports a listener-cancelled save instead of claiming success', function () {
    Fixtures::site();
    Fixtures::settings();

    // Approval-workflow addons cancel saves by returning false from
    // GlobalVariablesSaving; Variables::save() then returns false.
    Event::listen(GlobalVariablesSaving::class, fn () => false);

    Server::actingAs(Fixtures::makeUser('edit settings globals'))
        ->tool(GlobalsUpdate::class, [
            'handle' => 'settings',
            'data' => ['site_name' => 'Nope'],
        ])
        ->assertHasErrors(['the save was cancelled by a listener — the global variables were not updated']);

    // Rehydrate from disk: the Stache array cache aliases in-request
    // instances, and GlobalSet::localizations() ALSO Blink-caches the
    // mutated Variables (busted only by a successful save) — clear both.
    Stache::clear();
    Blink::flush();

    expect(GlobalSet::findByHandle('settings')->in('en')->data()->get('site_name'))
        ->toBe('Acme');
});

it('reports not-found instead of erroring when an exposed set cannot be fetched', function () {
    Fixtures::site();
    Fixtures::settings();

    // Stache index/item drift: the exposure check sees the handle in
    // GlobalSet::all() while findByHandle() comes back null (e.g. a deploy
    // deleted the set under a warm cache). A proxied partial stubs only the
    // fetch — everything else forwards to the real repository.
    $real = app(GlobalRepository::class);
    $mock = Mockery::mock($real);
    $mock->shouldReceive('findByHandle')->with('settings')->andReturnNull();
    GlobalSet::swap($mock);

    Server::actingAs(Fixtures::makeSuper())
        ->tool(GlobalsUpdate::class, [
            'handle' => 'settings',
            'data' => ['site_name' => 'X'],
        ])
        ->assertHasErrors(["global 'settings' not found — available: settings"]);
});

it('denies updating without the edit permission, naming it', function () {
    Fixtures::site();
    Fixtures::settings();

    $user = Fixtures::makeUser(); // 'access mcp' only

    Server::actingAs($user)
        ->tool(GlobalsUpdate::class, [
            'handle' => 'settings',
            'data' => ['site_name' => 'X'],
        ])
        ->assertHasErrors(["requires 'edit settings globals' — grant it to a role of {$user->email()} in the Control Panel"]);
});

it('denies a localized write without access to that site', function () {
    Fixtures::multisite();
    Fixtures::settings();

    GlobalSet::findByHandle('settings')->sites(['en', 'de'])->save();

    $user = Fixtures::makeUser('edit settings globals'); // no de access

    Server::actingAs($user)
        ->tool(GlobalsUpdate::class, [
            'handle' => 'settings',
            'data' => ['site_name' => 'Acme DE'],
            'site' => 'de',
        ])
        ->assertHasErrors(["requires 'access de site' — grant it to a role of {$user->email()} in the Control Panel"]);
});

it('is hidden when the server is read-only', function () {
    Fixtures::site();
    Fixtures::settings();

    config(['statamic.mcp.read_only' => true]);

    // Either the registration gate (shouldRegister) or the in-handler
    // re-check rejects the call — both are errors, which is all that matters.
    Server::actingAs(Fixtures::makeSuper())
        ->tool(GlobalsUpdate::class, [
            'handle' => 'settings',
            'data' => ['site_name' => 'X'],
        ])
        ->assertHasErrors();

    expect(GlobalSet::findByHandle('settings')->in('en')->data()->get('site_name'))
        ->toBe('Acme');
});
