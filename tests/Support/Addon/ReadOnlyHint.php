<?php

declare(strict_types=1);

namespace Danielgnh\StatamicMcp\Tests\Support\Addon;

use Attribute;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Attribute(Attribute::TARGET_CLASS)]
class ReadOnlyHint extends IsReadOnly {}
