<?php

declare(strict_types=1);

namespace Danielgnh\StatamicMcp\Tests\Support\Skills;

use Danielgnh\StatamicMcp\Support\ActingUser;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Skill;

class PricingPlaybook extends Skill
{
    public function path(): string
    {
        return dirname(__DIR__, 2).'/__fixtures__/skills/pricing-playbook';
    }

    public function shouldRegister(Request $request): bool
    {
        return ActingUser::resolve($request->user())?->hasPermission('edit pricing entries') ?? false;
    }
}
