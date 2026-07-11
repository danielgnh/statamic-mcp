<?php

namespace Danielgnh\StatamicMcp\Tests\Support;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Statamic\Contracts\Assets\AssetContainer as AssetContainerContract;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Role;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\User;

class Fixtures
{
    public static function site(): void
    {
        Site::setSites([
            'en' => ['name' => 'English', 'url' => '/', 'locale' => 'en_US'],
        ]);
    }

    public static function multisite(bool $withThirdSite = false): void
    {
        $sites = [
            'en' => ['name' => 'English', 'url' => '/', 'locale' => 'en_US'],
            'de' => ['name' => 'German', 'url' => '/de/', 'locale' => 'de_DE'],
        ];

        if ($withThirdSite) {
            $sites['at'] = ['name' => 'Austrian', 'url' => '/at/', 'locale' => 'de_AT'];
        }

        Site::setSites($sites);

        // Enables 'access {site} site' permissions — but only if multisite() runs before anything
        // builds the permission tree (Permission::all(), CP requests, authorization checks):
        // Statamic's Permissions::boot() is memoized, so later registrations won't appear.
        config(['statamic.system.multisite' => true]);
    }

    // Call tags() before blog(): the article blueprint's 'topic' field targets the tags taxonomy.
    public static function blog(): void
    {
        tap(
            Collection::make('blog')
                ->title('Blog')
                ->sites(Site::all()->map->handle()->values()->all())
                ->routes('/blog/{slug}')
        )->save();

        Blueprint::makeFromFields([
            'title' => ['type' => 'text', 'validate' => 'required'],
            'content' => ['type' => 'bard'],
            'hero_image' => ['type' => 'text'],
            'topic' => ['type' => 'terms', 'taxonomies' => ['tags'], 'max_items' => 1],
        ])->setHandle('article')->setNamespace('collections.blog')->save();
    }

    // The CP's blueprint builder lets editors mark slug required — Statamic's
    // own injected slug field is only max:200, so this needs its own fixture.
    public static function pages(): void
    {
        tap(
            Collection::make('pages')
                ->title('Pages')
                ->sites(Site::all()->map->handle()->values()->all())
                ->routes('/{slug}')
        )->save();

        Blueprint::makeFromFields([
            'title' => ['type' => 'text', 'validate' => 'required'],
            'slug' => ['type' => 'slug', 'validate' => 'required|max:200'],
        ])->setHandle('page')->setNamespace('collections.pages')->save();
    }

    public static function tags(): void
    {
        tap(Taxonomy::make('tags')->title('Tags'))->save();

        Blueprint::makeFromFields([
            'title' => ['type' => 'text', 'validate' => 'required'],
        ])->setHandle('tag')->setNamespace('taxonomies.tags')->save();
    }

    public static function settings(): void
    {
        Blueprint::makeFromFields([
            'site_name' => ['type' => 'text'],
            'footer_text' => ['type' => 'text'],
        ])->setHandle('settings')->setNamespace('globals')->save();

        $set = GlobalSet::make('settings')->title('Settings');
        $set->save();

        $set->makeLocalization(Site::default()->handle())
            ->data(['site_name' => 'Acme'])
            ->save();
    }

    /**
     * A user with 'access mcp' plus the given Statamic permissions,
     * via a dedicated throwaway role (spec: restricted agent = restricted role).
     */
    public static function makeUser(string ...$permissions): UserContract
    {
        $handle = 'role_'.Str::lower(Str::random(8));

        $role = Role::make($handle)->title('Test Role')->addPermission('access mcp');

        foreach ($permissions as $permission) {
            $role->addPermission($permission);
        }

        $role->save();

        return tap(
            User::make()->email(Str::lower(Str::random(8)).'@site.test')->assignRole($handle)
        )->save();
    }

    /**
     * A user WITHOUT 'access mcp' — only the given permissions, via a
     * dedicated throwaway role. For testing warnings/denials that makeUser's
     * always-included 'access mcp' would mask.
     */
    public static function makeBareUser(string ...$permissions): UserContract
    {
        $handle = 'role_'.Str::lower(Str::random(8));

        $role = Role::make($handle)->title('Bare Test Role');

        foreach ($permissions as $permission) {
            $role->addPermission($permission);
        }

        $role->save();

        return tap(
            User::make()->email(Str::lower(Str::random(8)).'@site.test')->assignRole($handle)
        )->save();
    }

    public static function makeSuper(): UserContract
    {
        return tap(
            User::make()->email(Str::lower(Str::random(8)).'@site.test')->makeSuper()
        )->save();
    }

    /**
     * A container on a fake disk (with url so $asset->url() works) plus an
     * asset blueprint with an 'alt' field, mirroring a default install.
     */
    public static function assetContainer(string $handle = 'images'): AssetContainerContract
    {
        Storage::fake($handle, ['url' => "/assets/{$handle}"]);

        $container = tap(AssetContainer::make($handle)->disk($handle)->title(Str::title($handle)))->save();

        Blueprint::makeFromFields([
            'alt' => ['type' => 'text'],
        ])->setHandle($handle)->setNamespace('assets')->save();

        return $container;
    }

    /**
     * A real 1x1 transparent PNG (68 bytes) — valid image bytes without
     * requiring GD in the test suite.
     */
    public static function tinyPng(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
    }
}
