<?php

declare(strict_types=1);

namespace Danielgnh\StatamicMcp\Setup;

enum EditResult
{
    case Applied;
    case Skipped;
    case Bailed;
}
