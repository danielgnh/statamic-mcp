<?php

use Danielgnh\StatamicMcp\Tests\Support\DescribedEntriesList;
use Danielgnh\StatamicMcp\Tests\Support\EchoUserTool;
use Danielgnh\StatamicMcp\ToolRegistry;
use Danielgnh\StatamicMcp\Tools\EntriesGet;
use Danielgnh\StatamicMcp\Tools\EntriesList;

it('adds tools once, in order', function () {
    $registry = (new ToolRegistry([EntriesList::class]))
        ->add(EchoUserTool::class, EntriesList::class, EchoUserTool::class);

    expect($registry->all())->toBe([EntriesList::class, EchoUserTool::class]);
});

it('replaces a tool in place', function () {
    $registry = (new ToolRegistry([EntriesList::class, EntriesGet::class]))
        ->replace(EntriesList::class, DescribedEntriesList::class);

    expect($registry->all())->toBe([DescribedEntriesList::class, EntriesGet::class]);
});

it('refuses to replace a tool that is not registered', function () {
    expect(fn () => (new ToolRegistry([EntriesGet::class]))->replace(EntriesList::class, DescribedEntriesList::class))
        ->toThrow(InvalidArgumentException::class, 'Cannot replace');
});

it('removes tools and ignores ones that are not registered', function () {
    $registry = (new ToolRegistry([EntriesList::class, EntriesGet::class]))
        ->remove(EntriesGet::class, EchoUserTool::class);

    expect($registry->all())->toBe([EntriesList::class]);
});

it('rejects classes that are not tools', function () {
    expect(fn () => (new ToolRegistry)->add(stdClass::class))
        ->toThrow(InvalidArgumentException::class, 'is not a Laravel\Mcp\Server\Tool subclass');
});
