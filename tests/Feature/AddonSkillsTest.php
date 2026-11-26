<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tests\Support\Skills\PricingPlaybook;
use Danielgnh\StatamicMcp\Tests\Support\Skills\SkillsServer;
use Danielgnh\StatamicMcp\Tests\Support\Skills\UnbuildableSkill;
use Danielgnh\StatamicMcp\Tests\Support\Skills\UnregistrableSkill;
use Danielgnh\StatamicMcp\Tokens\TokenRepository;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;

function addonSkillsPost(string $method, array $params = [], string ...$permissions): TestResponse
{
    return test()->withHeaders([
        'Authorization' => 'Bearer '.app(TokenRepository::class)->issue(Fixtures::makeUser(...$permissions), 'skills')->token,
        'Accept' => 'application/json, text/event-stream',
    ])->postJson('/mcp/statamic', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => $method,
        'params' => $params === [] ? (object) [] : $params,
    ]);
}

it('registers add-on skills once each, in the order the add-ons booted', function () {
    Server::registerAddonSkills('/addon-a/seo-audit', PricingPlaybook::class);
    Server::registerAddonSkills('/addon-b/redirects', '/addon-a/seo-audit');

    expect(Server::registeredAddonSkills())->toBe(['/addon-a/seo-audit', PricingPlaybook::class, '/addon-b/redirects']);
});

it('serves the same tools whether or not add-ons registered skills', function () {
    $before = addonSkillsPost('tools/list')->assertOk()->json('result.tools');

    Server::registerAddonSkills(SkillsServer::FIXTURES.'/seo-audit', '/nowhere/missing');

    expect(addonSkillsPost('tools/list')->assertOk()->json('result.tools'))->toBe($before);
});

it('serves no skill method on a site without skills', function () {

    expect(addonSkillsPost('skills/list')->json('error.code'))->toBe(-32601);
});

it('serves an add-on skill folder and an add-on skill class on a site without a server class of its own', function () {
    Server::registerAddonSkills(SkillsServer::FIXTURES.'/seo-audit', PricingPlaybook::class);

    $uris = collect(addonSkillsPost('skills/list', [], 'edit pricing entries')->assertOk()->json('result.skills'))->pluck('uri');
    $read = addonSkillsPost('resources/read', ['uri' => 'skill://seo-audit/SKILL.md'])->assertOk();

    expect($uris->all())->toBe(['skill://seo-audit/SKILL.md', 'skill://pricing-playbook/SKILL.md'])
        ->and($read->json('result.contents.0.text'))->toContain('From the add-on.')
        ->and(collect(addonSkillsPost('skills/list')->json('result.skills'))->pluck('uri')->all())->toBe(['skill://seo-audit/SKILL.md']);
});

it('skips a broken add-on skill, reports it, and keeps the other skills and every tool working', function () {
    Exceptions::fake();

    $broken = storage_path('framework/testing/skills/broken');
    File::ensureDirectoryExists($broken);
    File::put($broken.'/SKILL.md', "# No frontmatter\n");

    try {
        Server::registerAddonSkills($broken, '/nowhere/missing', 'relative/never-there', SkillsServer::FIXTURES.'/seo-audit');

        $uris = collect(addonSkillsPost('skills/list')->assertOk()->json('result.skills'))->pluck('uri');
        $overview = addonSkillsPost('tools/call', ['name' => 'statamic_overview', 'arguments' => (object) []])->assertOk();

        expect($uris->all())->toBe(['skill://seo-audit/SKILL.md'])
            ->and(addonSkillsPost('tools/list')->assertOk()->json('result.tools'))->not->toBeEmpty()
            ->and($overview->json('result.isError'))->not->toBeTrue();

        Exceptions::assertReported(fn (InvalidArgumentException $e) => str_contains($e->getMessage(), 'must start with a YAML frontmatter block'));
        Exceptions::assertReported(fn (InvalidArgumentException $e) => str_contains($e->getMessage(), '[/nowhere/missing] does not exist'));
    } finally {
        File::deleteDirectory(dirname($broken));
    }
});

it('serves the first of two add-on skills with one name', function () {
    Exceptions::fake();
    Server::registerAddonSkills(SkillsServer::FIXTURES.'/seo-audit', SkillsServer::FIXTURES.'/shadow/seo-audit');

    $read = addonSkillsPost('resources/read', ['uri' => 'skill://seo-audit/SKILL.md'])->assertOk();

    expect($read->json('result.contents.0.text'))->toContain('From the add-on.');
});

it('keeps every tool working when an add-on skill breaks the skill methods', function (string $skill) {
    Exceptions::fake();
    Server::registerAddonSkills($skill);

    expect(addonSkillsPost('tools/list')->assertOk()->json('result.tools'))->not->toBeEmpty()
        ->and(addonSkillsPost('tools/call', ['name' => 'statamic_overview', 'arguments' => (object) []])->assertOk()->json('result.isError'))->not->toBeTrue()
        ->and(addonSkillsPost('skills/list')->json('result'))->toBeNull();
})->with([
    'a shouldRegister() that throws' => [UnregistrableSkill::class],
    'a class that cannot be built' => [UnbuildableSkill::class],
]);
