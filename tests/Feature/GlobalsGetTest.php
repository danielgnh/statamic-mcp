<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\GlobalsGet;
use Statamic\Contracts\Globals\GlobalRepository;
use Statamic\Facades\Blueprint;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Site;

it('returns variables for one exposed set', function () {
    Fixtures::site();
    Fixtures::settings();

    $user = Fixtures::makeUser('edit settings globals');

    Server::actingAs($user)
        ->tool(GlobalsGet::class, ['handle' => 'settings'])
        ->assertOk()
        ->assertSee('"handle":"settings"')
        ->assertSee('"site_name":"Acme"')
        ->assertSee('"cp_edit_url"');
});

it('lists every readable set when handle is omitted, silently omitting denied sets', function () {
    Fixtures::site();
    Fixtures::settings();

    Blueprint::makeFromFields(['tagline' => ['type' => 'text']])
        ->setHandle('footer')->setNamespace('globals')->save();
    $footer = GlobalSet::make('footer')->title('Footer');
    $footer->save();
    $footer->makeLocalization(Site::default()->handle())->data(['tagline' => 'Bye'])->save();

    $user = Fixtures::makeUser('edit settings globals'); // no footer permission

    Server::actingAs($user)
        ->tool(GlobalsGet::class, [])
        ->assertOk()
        ->assertSee('"handle":"settings"')
        ->assertDontSee('"handle":"footer"');
});

it('treats an unexposed set as missing, listing only exposed handles', function () {
    Fixtures::site();
    Fixtures::settings();

    $secrets = GlobalSet::make('secrets')->title('Secrets');
    $secrets->save();

    config(['statamic.mcp.resources.globals' => ['settings']]);

    Server::actingAs(Fixtures::makeSuper())
        ->tool(GlobalsGet::class, ['handle' => 'secrets'])
        ->assertHasErrors(["global 'secrets' not found — available: settings"]);
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
        ->tool(GlobalsGet::class, ['handle' => 'settings'])
        ->assertHasErrors(["global 'settings' not found — available: settings"]);
});

it('silently omits a set from the listing when it cannot be fetched', function () {
    Fixtures::site();
    Fixtures::settings();

    Blueprint::makeFromFields(['tagline' => ['type' => 'text']])
        ->setHandle('footer')->setNamespace('globals')->save();
    $footer = GlobalSet::make('footer')->title('Footer');
    $footer->save();
    $footer->makeLocalization(Site::default()->handle())->data(['tagline' => 'Bye'])->save();

    // Same index/item drift as above, listing path: footer survives the
    // exposure and permission filters but its fetch comes back null, while
    // other handles forward to the real repository.
    $real = app(GlobalRepository::class);
    $mock = Mockery::mock($real);
    $mock->shouldReceive('findByHandle')->andReturnUsing(
        fn (string $handle) => $handle === 'footer' ? null : $real->findByHandle($handle),
    );
    GlobalSet::swap($mock);

    Server::actingAs(Fixtures::makeSuper())
        ->tool(GlobalsGet::class, [])
        ->assertOk()
        ->assertSee('"handle":"settings"')
        ->assertDontSee('"handle":"footer"');
});

it('reports a truly missing handle with the identical error shape', function () {
    Fixtures::site();
    Fixtures::settings();

    Server::actingAs(Fixtures::makeSuper())
        ->tool(GlobalsGet::class, ['handle' => 'nope'])
        ->assertHasErrors(["global 'nope' not found — available: settings"]);
});

it('reads a site localization', function () {
    Fixtures::multisite();
    Fixtures::settings();

    $set = GlobalSet::findByHandle('settings');
    $set->sites(['en', 'de'])->save();
    $set->makeLocalization('de')->data(['site_name' => 'Acme DE'])->save();

    $user = Fixtures::makeUser('edit settings globals', 'access de site');

    Server::actingAs($user)
        ->tool(GlobalsGet::class, ['handle' => 'settings', 'site' => 'de'])
        ->assertOk()
        ->assertSee('"site":"de"')
        ->assertSee('"site_name":"Acme DE"');
});

it('annotates values inherited from an origin localization', function () {
    Fixtures::multisite();
    Fixtures::settings();

    // 'de' originates from 'en' (set-level origin config): the de
    // localization falls back to en for everything it does not override.
    $set = GlobalSet::findByHandle('settings');
    $set->sites(['en' => null, 'de' => 'en'])->save();
    $set->in('de')->data(['footer_text' => 'Impressum'])->save();

    $user = Fixtures::makeUser('edit settings globals', 'access de site');

    Server::actingAs($user)
        ->tool(GlobalsGet::class, ['handle' => 'settings', 'site' => 'de'])
        ->assertOk()
        ->assertSee('"data":{"footer_text":"Impressum"}')
        ->assertSee('"origin_site":"en"')
        ->assertSee('"inherited":{"site_name":"Acme"}');
});

it('denies reading a set without the edit permission, naming it', function () {
    Fixtures::site();
    Fixtures::settings();

    $user = Fixtures::makeUser(); // 'access mcp' only

    // v6 has no 'view {handle} globals' permission — the CP gates viewing
    // on edit, so the named remedy is the edit permission.
    Server::actingAs($user)
        ->tool(GlobalsGet::class, ['handle' => 'settings'])
        ->assertHasErrors(["requires 'edit settings globals' — grant it to a role of {$user->email()} in the Control Panel"]);
});

it('omits sets not configured for the requested site from the listing', function () {
    Fixtures::multisite();
    Fixtures::settings();

    GlobalSet::findByHandle('settings')->sites(['en', 'de'])->save();

    // en-only set: must not appear in the de listing.
    Blueprint::makeFromFields(['tagline' => ['type' => 'text']])
        ->setHandle('footer')->setNamespace('globals')->save();
    $footer = GlobalSet::make('footer')->title('Footer')->sites(['en']);
    $footer->save();
    $footer->in('en')->data(['tagline' => 'Bye'])->save();

    Server::actingAs(Fixtures::makeSuper())
        ->tool(GlobalsGet::class, ['site' => 'de'])
        ->assertOk()
        ->assertSee('"handle":"settings"')
        ->assertDontSee('"handle":"footer"');
});
