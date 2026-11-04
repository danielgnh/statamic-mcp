<?php

use Danielgnh\StatamicMcp\ServiceProvider;
use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider as LaravelServiceProvider;
use Statamic\Statamic;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

/**
 * Every string the addon hands to __(): its own calls, plus the nav and
 * permission labels Statamic translates on its behalf. "MCP" is a name and
 * reads the same in every language.
 *
 * @return list<string>
 */
function addonTranslationKeys(): array
{
    $files = Finder::create()->files()->name('*.php')->in([__DIR__.'/../../src', __DIR__.'/../../resources/views']);

    return collect($files)
        ->flatMap(function (SplFileInfo $file) {
            $code = $file->getContents();

            return collect([
                '/__\(\s*\'((?:[^\'\\\\]|\\\\.)*)\'/',
                '/__\(\s*"((?:[^"\\\\]|\\\\.)*)"/',
                '/Nav::item\(\'([^\']+)\'\)/',
                '/->tools\(\'([^\']+)\'\)/',
                '/->label\(\'([^\']+)\'\)/',
            ])->flatMap(function (string $pattern) use ($code) {
                preg_match_all($pattern, $code, $matches);

                return array_map(stripslashes(...), $matches[1]);
            });
        })
        ->unique()
        ->reject(fn (string $key) => $key === 'MCP')
        ->sort()
        ->values()
        ->all();
}

/**
 * @return array<string, string>
 */
function addonTranslations(string $locale): array
{
    return File::json(__DIR__."/../../lang/{$locale}.json");
}

/**
 * @return array<string, string>
 */
function statamicTranslations(string $locale): array
{
    $path = dirname((string) (new ReflectionClass(Statamic::class))->getFileName(), 2)."/lang/{$locale}.json";

    return File::exists($path) ? File::json($path) : [];
}

dataset('shipped languages', fn () => array_map(fn (string $file) => basename($file, '.json'), glob(__DIR__.'/../../lang/*.json')));

it('translates every string of the control panel into :dataset', function (string $locale) {
    $translated = array_keys(addonTranslations($locale) + statamicTranslations($locale));

    expect(array_values(array_diff(addonTranslationKeys(), $translated)))->toBe([]);
})->with('shipped languages');

it('never shadows a translation Statamic ships, in :dataset', function (string $locale) {
    expect(array_keys(array_intersect_key(addonTranslations($locale), statamicTranslations($locale))))->toBe([]);
})->with('shipped languages');

it('ships no translation the control panel no longer uses, in :dataset', function (string $locale) {
    expect(array_values(array_diff(array_keys(addonTranslations($locale)), addonTranslationKeys())))->toBe([]);
})->with('shipped languages');

it('loads its translations into the application translator', function () {
    app()->setLocale('de');

    expect(__('Connections'))->toBe('Verbindungen');
});

it('leaves a language without a file in English', function () {
    app()->setLocale('ja');

    expect(__('Connections'))->toBe('Connections');
});

it('publishes no lang directory, since an app overrides JSON translations from its own lang folder', function () {
    expect(LaravelServiceProvider::pathsToPublish(ServiceProvider::class, 'statamic-mcp-views'))->not->toBe([])
        ->and(LaravelServiceProvider::pathsToPublish(ServiceProvider::class, 'statamic-mcp-translations'))->toBe([]);
});
