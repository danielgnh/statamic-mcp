<?php

declare(strict_types=1);

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\BlueprintsGet;
use Danielgnh\StatamicMcp\Tools\StatamicOverview;
use Illuminate\Support\Facades\File;

function guideline(string $file, string $markdown): void
{
    $path = config('statamic.mcp.guidelines_path').'/'.$file;

    File::ensureDirectoryExists(dirname($path));
    File::put($path, $markdown);
}

it('returns the site guidelines from statamic_overview', function () {
    Fixtures::site();

    guideline('site.md', "# Voice\n\nFriendly, never salesy.\n");

    Server::actingAs(Fixtures::makeSuper())
        ->tool(StatamicOverview::class, [])
        ->assertOk()
        ->assertSee('"guidelines":"# Voice\n\nFriendly, never salesy."');
});

it('leaves guidelines out of statamic_overview when the site has none', function () {
    Fixtures::site();

    Server::actingAs(Fixtures::makeSuper())
        ->tool(StatamicOverview::class, [])
        ->assertOk()
        ->assertDontSee('"guidelines"');
});

it('returns the collection and blueprint guidelines from blueprints_get, collection first', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    guideline('collections/blog.md', 'Every post ends with a question.');
    guideline('collections/blog/article.md', 'Articles open with the hero image.');

    Server::actingAs(Fixtures::makeUser('view blog entries'))
        ->tool(BlueprintsGet::class, ['type' => 'collection', 'handle' => 'blog'])
        ->assertOk()
        ->assertSee('"available_blueprints":["article"],"guidelines":"Every post ends with a question.\n\nArticles open with the hero image.","fields"');
});

it('returns taxonomy guidelines from blueprints_get', function () {
    Fixtures::site();
    Fixtures::tags();

    guideline('taxonomies/tags.md', 'Tags are lowercase nouns.');

    Server::actingAs(Fixtures::makeUser('view tags terms'))
        ->tool(BlueprintsGet::class, ['type' => 'taxonomy', 'handle' => 'tags'])
        ->assertOk()
        ->assertSee('"guidelines":"Tags are lowercase nouns."');
});

it('never sends html comments, so an untouched stub reads as no guidelines', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    guideline('site.md', "<!--\nNotes for the developer.\n-->\n");
    guideline('collections/blog.md', "<!-- TODO: ask marketing -->\nShort paragraphs.");

    Server::actingAs(Fixtures::makeSuper())
        ->tool(StatamicOverview::class, [])
        ->assertOk()
        ->assertDontSee('"guidelines"');

    Server::actingAs(Fixtures::makeSuper())
        ->tool(BlueprintsGet::class, ['type' => 'collection', 'handle' => 'blog'])
        ->assertOk()
        ->assertSee('"guidelines":"Short paragraphs."')
        ->assertDontSee('ask marketing');
});

it('keeps guidelines behind the same permission as the blueprint', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    guideline('collections/blog.md', 'Secret editorial plan.');

    Server::actingAs(Fixtures::makeUser())
        ->tool(BlueprintsGet::class, ['type' => 'collection', 'handle' => 'blog'])
        ->assertHasErrors()
        ->assertDontSee('Secret editorial plan.');
});
