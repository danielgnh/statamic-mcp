<?php

declare(strict_types=1);

namespace Danielgnh\StatamicMcp\Tests\Support\Addon;

use Laravel\Mcp\Server\Attributes\Name;

#[Name('addon_inherited_read')]
class AddonInheritedReadTool extends AddonReadTool {}
