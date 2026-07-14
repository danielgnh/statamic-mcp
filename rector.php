<?php

use Rector\CodeQuality\Rector\If_\ExplicitBoolCompareRector;
use Rector\CodeQuality\Rector\If_\ObjectExplicitBoolCompareRector;
use Rector\Config\RectorConfig;
use RectorLaravel\Rector\FuncCall\AppToResolveRector;
use RectorLaravel\Rector\StaticCall\CarbonToDateFacadeRector;
use RectorLaravel\Set\LaravelSetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/src',
        __DIR__.'/tests',
        __DIR__.'/config',
    ])
    ->withSkip(array_filter([
        __DIR__.'/tests/__fixtures__',
        ExplicitBoolCompareRector::class,
        // Rule ships with rector 2.5.5+ and skip entries must exist — inline
        // it once the newer rector clears the local soak-time window.
        class_exists(ObjectExplicitBoolCompareRector::class) ? ObjectExplicitBoolCompareRector::class : null,
        AppToResolveRector::class,
        CarbonToDateFacadeRector::class,
    ]))
    ->withImportNames(importShortClasses: false, removeUnusedImports: true)
    ->withPhpSets(php83: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        earlyReturn: true,
    )
    ->withSets([
        LaravelSetList::LARAVEL_CODE_QUALITY,
    ]);
