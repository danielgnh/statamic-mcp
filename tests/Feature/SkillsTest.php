<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tests\Support\Skills\SkillsServer;
use Danielgnh\StatamicMcp\Tests\UsesSkillsServer;
use Danielgnh\StatamicMcp\Tokens\TokenRepository;
use Illuminate\Testing\TestResponse;
use Statamic\Contracts\Auth\User;

uses(UsesSkillsServer::class);

function skillsPost(string $method, array $params, ?User $user): TestResponse
{
    return test()->withHeaders(array_filter([
        'Authorization' => $user instanceof User ? 'Bearer '.app(TokenRepository::class)->issue($user, 'skills')->token : null,
        'Accept' => 'application/json, text/event-stream',
    ]))->postJson('/mcp/statamic', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => $method,
        'params' => $params === [] ? (object) [] : $params,
    ]);
}

/**
 * @return list<string>
 */
function skillUris(string $method, string $key, User $user): array
{
    return collect(skillsPost($method, [], $user)->assertOk()->json("result.{$key}"))
        ->pluck('uri')
        ->filter(fn (string $uri) => str_starts_with($uri, 'skill://'))
        ->values()
        ->all();
}

it("serves the skills of the site's server class, folders and classes alike", function () {
    $user = Fixtures::makeUser('edit pricing entries');

    expect(skillUris('skills/list', 'skills', $user))->toBe([
        'skill://landing-page/SKILL.md',
        'skill://pricing-playbook/SKILL.md',
        'skill://seo-audit/SKILL.md',
    ]);

    $read = skillsPost('resources/read', ['uri' => 'skill://landing-page/examples/hero-first.yaml'], $user)->assertOk();

    expect($read->json('result.contents.0.text'))->toContain('Example heading');
});

it('keeps a skill whose shouldRegister() refuses the user out of every skill and resource method', function () {
    $user = Fixtures::makeUser();
    $uri = 'skill://pricing-playbook/SKILL.md';

    expect(skillUris('skills/list', 'skills', $user))->not->toContain($uri)
        ->and(skillUris('resources/list', 'resources', $user))->toContain('skill://landing-page/SKILL.md')->not->toContain($uri)
        ->and(skillsPost('skills/get', ['uri' => $uri], $user)->json('error.code'))->toBe(-32602)
        ->and(skillsPost('resources/read', ['uri' => $uri], $user)->json('error.code'))->toBe(-32602)
        ->and(skillsPost('skills/get', ['uri' => $uri], Fixtures::makeUser('edit pricing entries'))->json('result.skill.uri'))->toBe($uri);
});

it('refuses every skill method without a token', function (string $method, array $params) {
    skillsPost($method, $params, null)->assertUnauthorized();
})->with('skill methods');

it('refuses every skill method to a user without Access MCP', function (string $method, array $params) {
    skillsPost($method, $params, Fixtures::makeBareUser('edit pricing entries'))->assertForbidden();
})->with('skill methods');

dataset('skill methods', [
    'skills/list' => ['skills/list', []],
    'skills/get' => ['skills/get', ['uri' => 'skill://landing-page/SKILL.md']],
    'resources/list' => ['resources/list', []],
    'resources/read' => ['resources/read', ['uri' => 'skill://landing-page/SKILL.md']],
]);

it("serves the site's skill when an add-on registers one with the same name, because the site's list comes first", function () {
    Server::registerAddonSkills(SkillsServer::FIXTURES.'/seo-audit');
    $user = Fixtures::makeUser();

    $read = skillsPost('resources/read', ['uri' => 'skill://seo-audit/SKILL.md'], $user)->assertOk();

    expect($read->json('result.contents.0.text'))->toContain('From the site.')
        ->and(skillUris('skills/list', 'skills', $user))->toBe(['skill://landing-page/SKILL.md', 'skill://seo-audit/SKILL.md']);
});

it('serves an add-on skill to the users that a site skill of the same name refuses, because a hidden skill takes no name', function () {
    Server::registerAddonSkills(SkillsServer::FIXTURES.'/shadow/pricing-playbook');
    $uri = 'skill://pricing-playbook/SKILL.md';

    $refused = skillsPost('resources/read', ['uri' => $uri], Fixtures::makeUser())->assertOk();
    $allowed = skillsPost('resources/read', ['uri' => $uri], Fixtures::makeUser('edit pricing entries'))->assertOk();

    expect($refused->json('result.contents.0.text'))->toContain('From the add-on.')
        ->and($allowed->json('result.contents.0.text'))->toContain('Change the plan entry first');
});

it('serves the same skills with read_only on, since no skill writes', function () {
    config(['statamic.mcp.read_only' => true]);

    expect(skillUris('skills/list', 'skills', Fixtures::makeUser()))->toBe(['skill://landing-page/SKILL.md', 'skill://seo-audit/SKILL.md']);
});
