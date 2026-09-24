<?php

declare(strict_types=1);

namespace Danielgnh\StatamicMcp\Tests\Support;

use Danielgnh\StatamicMcp\Tools\EntriesList;
use Laravel\Mcp\Server\Attributes\Description;

/**
 * A host-app replacement for a built-in tool: the name is inherited from the
 * parent, only the description changes.
 */
#[Description('Overridden by the host app.')]
class DescribedEntriesList extends EntriesList {}
