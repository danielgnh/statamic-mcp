<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\EntriesCreate;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-01 12:00:00', 'UTC'));
});

it('reads a time without an offset in app.timezone and honors an explicit offset', function () {
    Fixtures::site();
    Fixtures::news();

    config(['app.timezone' => 'Europe/Berlin']);

    $user = Fixtures::makeUser('create news entries');

    Server::actingAs($user)
        ->tool(EntriesCreate::class, ['collection' => 'news', 'data' => ['title' => 'Naive'], 'date' => '2026-10-06 09:00'])
        ->assertOk()
        ->assertSee('"date":"2026-10-06T07:00:00+00:00"');

    Server::actingAs($user)
        ->tool(EntriesCreate::class, ['collection' => 'news', 'data' => ['title' => 'Offset'], 'date' => '2026-10-06T09:00:00-04:00'])
        ->assertOk()
        ->assertSee('"date":"2026-10-06T13:00:00+00:00"');
});
