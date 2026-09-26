<?php

namespace Danielgnh\StatamicMcp\Tests\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Statamic\Contracts\Assets\AssetContainer as AssetContainerContract;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Form;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Nav;
use Statamic\Facades\Role;
use Statamic\Facades\Site;
use Statamic\Facades\Stache;
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

        // Localizable, as the CP's multisite blueprints are: a localization
        // can only hold its own value for a field marked so.
        Blueprint::makeFromFields([
            'title' => ['type' => 'text', 'validate' => 'required', 'localizable' => true],
            'content' => ['type' => 'bard', 'localizable' => true],
            'hero_image' => ['type' => 'text', 'localizable' => true],
            'topic' => ['type' => 'terms', 'taxonomies' => ['tags'], 'max_items' => 1, 'localizable' => true],
        ])->setHandle('article')->setNamespace('collections.blog')->save();
    }

    // A dated collection that schedules: the CP creates dated collections with
    // future dates private, while a collection made in code defaults to public.
    public static function news(string $future = 'private', string $past = 'public'): void
    {
        tap(
            Collection::make('news')
                ->title('News')
                ->dated(true)
                ->futureDateBehavior($future)
                ->pastDateBehavior($past)
                ->sites(Site::all()->map->handle()->values()->all())
                ->routes('/news/{slug}')
        )->save();

        Blueprint::makeFromFields([
            'title' => ['type' => 'text', 'validate' => 'required'],
            'date' => ['type' => 'date', 'time_enabled' => true],
        ])->setHandle('story')->setNamespace('collections.news')->save();
    }

    // Slugs turned off: Statamic injects no slug field into the blueprint,
    // and names each entry's file by its id.
    public static function faqs(): void
    {
        tap(
            Collection::make('faqs')
                ->title('FAQs')
                ->requiresSlugs(false)
                ->sites(Site::all()->map->handle()->values()->all())
        )->save();

        Blueprint::makeFromFields([
            'title' => ['type' => 'text', 'validate' => 'required', 'localizable' => true],
        ])->setHandle('faq')->setNamespace('collections.faqs')->save();
    }

    // An entry of the faqs collection: call faqs() first. Returns its id.
    public static function faq(string $title, string $site = 'en'): string
    {
        return tap(Entry::make()->collection('faqs')->locale($site)->data(['title' => $title]))->save()->id();
    }

    // Revisions need Statamic Pro; the collection must already exist.
    public static function revisions(string $collection = 'blog'): void
    {
        config([
            'statamic.editions.pro' => true,
            'statamic.revisions.enabled' => true,
        ]);

        Collection::findByHandle($collection)->revisionsEnabled(true)->save();
    }

    // An author field turns on Statamic's author rules: entries by anyone
    // else need the "other authors" permissions.
    public static function authors(string $collection = 'blog', ?int $maxItems = 1): void
    {
        $handle = Collection::findByHandle($collection)->entryBlueprint()->handle();

        Blueprint::find("collections.{$collection}.{$handle}")
            ->ensureField('author', array_filter(['type' => 'users', 'max_items' => $maxItems]))
            ->save();
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
            'title' => ['type' => 'text', 'validate' => 'required', 'localizable' => true],
            'slug' => ['type' => 'slug', 'validate' => 'required|max:200', 'localizable' => true],
        ])->setHandle('page')->setNamespace('collections.pages')->save();
    }

    // An entry of the pages collection: call pages() first. Returns its id.
    public static function page(string $slug, string $title, string $site = 'en', bool $published = true): string
    {
        return tap(
            Entry::make()->collection('pages')->locale($site)->slug($slug)->data(['title' => $title])->published($published)
        )->save()->id();
    }

    // The id of the pages entry with this slug, for entries a tool created.
    public static function pageId(string $slug, string $site = 'en'): string
    {
        return Entry::query()->where('collection', 'pages')->where('site', $site)->where('slug', $slug)->first()->id();
    }

    // The pages tree as stored. tree() would append the entries missing from
    // the stored tree, so this reads fileData() after rehydrating from disk.
    public static function storedPagesTree(string $site = 'en'): array
    {
        Stache::clear();

        return Collection::findByHandle('pages')->structure()->in($site)->fileData()['tree'];
    }

    // Gives an existing collection a tree, with URLs that follow its nesting:
    // call pages() first. max_depth 1 makes it a flat, orderable list.
    public static function structure(string $collection = 'pages', ?int $maxDepth = null, bool $root = false): void
    {
        Collection::findByHandle($collection)
            ->structureContents(['root' => $root, 'max_depth' => $maxDepth])
            ->routes('{parent_uri}/{slug}')
            ->save();
    }

    // Call assetContainer('images') first: the single-file fields point at it.
    public static function landing(): void
    {
        tap(
            Collection::make('landing')
                ->title('Landing')
                ->sites(Site::all()->map->handle()->values()->all())
                ->routes('/landing/{slug}')
        )->save();

        Blueprint::makeFromFields([
            'title' => ['type' => 'text', 'validate' => 'required'],
            'hero' => ['type' => 'assets', 'container' => 'images', 'max_files' => 1],
            'starts' => ['type' => 'date'],
            'page_builder' => ['type' => 'replicator', 'sets' => [
                'website' => ['display' => 'Website', 'sets' => [
                    'section_hero' => ['display' => 'Section - Hero', 'fields' => [
                        ['handle' => 'heading', 'field' => ['type' => 'text', 'validate' => 'required']],
                        ['handle' => 'image', 'field' => ['type' => 'assets', 'container' => 'images', 'max_files' => 1]],
                    ]],
                    'section_text_block' => ['display' => 'Section - Text Block', 'fields' => [
                        ['handle' => 'text', 'field' => ['type' => 'textarea']],
                    ]],
                ]],
            ]],
            'body' => ['type' => 'bard', 'sets' => [
                'main' => ['display' => 'Main', 'sets' => [
                    'callout' => ['display' => 'Callout', 'fields' => [
                        ['handle' => 'text', 'field' => ['type' => 'text']],
                    ]],
                ]],
            ]],
            'facts' => ['type' => 'grid', 'fields' => [
                ['handle' => 'label', 'field' => ['type' => 'text']],
            ]],
            'seo' => ['type' => 'group', 'fields' => [
                ['handle' => 'meta_title', 'field' => ['type' => 'text']],
            ]],
        ])->setHandle('landing_page')->setNamespace('collections.landing')->save();
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
     * The global set 0.6.0 kept guidelines in, with its blueprint.
     *
     * @param  array<string, array<string, mixed>>  $localizations  values keyed by site handle
     */
    public static function legacyGuidelinesSet(array $localizations, string $handle = 'guidelines'): void
    {
        Blueprint::make($handle)->setNamespace('globals')->setContents(['tabs' => ['main' => ['sections' => [['fields' => [
            ['handle' => 'site', 'field' => ['type' => 'markdown']],
            ['handle' => 'resources', 'field' => ['type' => 'replicator', 'sets' => ['main' => ['sets' => ['resource' => ['fields' => [
                ['handle' => 'collections', 'field' => ['type' => 'collections']],
                ['handle' => 'guidelines', 'field' => ['type' => 'markdown']],
            ]]]]]]],
        ]]]]]])->save();

        $set = GlobalSet::make($handle)->title('Guidelines')->sites(array_fill_keys(array_keys($localizations), null));
        $set->save();

        foreach ($localizations as $site => $data) {
            $set->makeLocalization($site)->data($data)->save();
        }
    }

    // Links entries of the pages collection: call pages() first. Every site
    // gets an empty tree, the way the CP creates one.
    public static function nav(string $handle = 'main', ?int $maxDepth = null, bool $root = false): void
    {
        $nav = Nav::make($handle)
            ->title(Str::headline($handle))
            ->collections(['pages'])
            ->maxDepth($maxDepth)
            ->expectsRoot($root);

        $nav->save();

        foreach (Site::all()->keys() as $site) {
            $nav->makeTree($site)->save();
        }

        Blueprint::makeFromFields([
            'icon' => ['type' => 'text', 'validate' => 'max:30'],
            'new_tab' => ['type' => 'toggle'],
        ])->setHandle($handle)->setNamespace('navigation')->save();
    }

    /**
     * A form with the blueprint a default install's contact form has.
     * store: false makes it email and keep nothing, as the CP's toggle does.
     */
    public static function form(string $handle = 'contact', bool $store = true): void
    {
        $form = Form::make($handle)->title(Str::headline($handle));

        $form->store($store);

        $form->save();

        Blueprint::makeFromFields([
            'name' => ['type' => 'text', 'validate' => 'required'],
            'email' => ['type' => 'text', 'input_type' => 'email'],
            'message' => ['type' => 'textarea'],
            'newsletter' => ['type' => 'toggle'],
        ])->setHandle($handle)->setNamespace('forms')->save();
    }

    /**
     * A stored submission. Its id is the timestamp Statamic derives the
     * date from, so two submissions need different seconds. Returns the id.
     *
     * @param  array<string, mixed>  $data
     */
    public static function submission(string $form, array $data, string $date = '2026-09-25 10:00:00'): string
    {
        $submission = Form::find($form)->makeSubmission()
            ->id((string) Carbon::parse($date, 'UTC')->timestamp)
            ->data($data);

        $submission->save();

        return (string) $submission->id();
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
     * A real 1x1 transparent PNG (70 bytes) — valid image bytes without
     * requiring GD in the test suite.
     */
    public static function tinyPng(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
    }
}
