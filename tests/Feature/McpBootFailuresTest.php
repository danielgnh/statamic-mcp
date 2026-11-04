<?php

use Danielgnh\StatamicMcp\ServiceProvider;
use Danielgnh\StatamicMcp\Tests\AutoloadsServerWithMissingParent;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Statamic\Facades\CP\Nav;

uses(AutoloadsServerWithMissingParent::class);

beforeEach(function () {
    Exceptions::fake();
    Nav::shouldReceive('extend');
});

function routesAt(string $uri): int
{
    return collect(Route::getRoutes()->getRoutes())->filter(fn ($route) => $route->uri() === $uri)->count();
}

it('keeps the host site up and reports the failure once when the config cannot mount the route', function (array $config) {
    config(['statamic.mcp.route' => 'mcp/probe', ...$config]);

    app()->getProvider(ServiceProvider::class)->bootAddon();

    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(fn (RuntimeException $e) => str_contains($e->getMessage(), 'php please mcp:doctor') && $e->getPrevious() instanceof Throwable);
})->with([
    'route is null' => [['statamic.mcp.route' => null]],
    'route is a list' => [['statamic.mcp.route' => ['mcp']]],
    'route is empty' => [['statamic.mcp.route' => '']],
    'route is a number' => [['statamic.mcp.route' => 123]],
    'server is not a laravel/mcp server' => [['statamic.mcp.server' => stdClass::class]],
    'server class cannot load' => [['statamic.mcp.server' => 'ServerWithMissingParent']],
    'middleware holds a list' => [['statamic.mcp.middleware' => [['throttle:60,1']]]],
    'middleware holds a closure' => [['statamic.mcp.middleware' => [fn () => null]]],
    'middleware holds a number' => [['statamic.mcp.middleware' => [123]]],
    'middleware holds null' => [['statamic.mcp.middleware' => [null]]],
    'middleware is a number' => [['statamic.mcp.middleware' => 123]],
]);

it('registers no route at all when the config cannot mount it, because a route without its middleware has no authentication', function (array $config) {
    config(['statamic.mcp.route' => 'mcp/probe', ...$config]);

    app()->getProvider(ServiceProvider::class)->bootAddon();

    expect(routesAt('mcp/probe'))->toBe(0);
})->with([
    'server is not a laravel/mcp server' => [['statamic.mcp.server' => stdClass::class]],
    'middleware holds a list' => [['statamic.mcp.middleware' => [['throttle:60,1']]]],
    'middleware holds a closure' => [['statamic.mcp.middleware' => [fn () => null]]],
    'middleware holds a number' => [['statamic.mcp.middleware' => [123]]],
]);

it('still mounts the route for a list of middleware names', function () {
    config(['statamic.mcp.route' => 'mcp/probe', 'statamic.mcp.middleware' => ['throttle:60,1']]);

    app()->getProvider(ServiceProvider::class)->bootAddon();

    Exceptions::assertNothingReported();

    expect(routesAt('mcp/probe'))->toBeGreaterThan(0);
});

it('lets a bug in route registration surface, because turning MCP off would hide it', function () {
    $provider = new class(app()) extends ServiceProvider
    {
        protected function registerMcpRoutes(): void
        {
            throw new LogicException('a bug, not bad config');
        }
    };

    expect(fn () => $provider->bootAddon())->toThrow(LogicException::class, 'a bug, not bad config');
});
