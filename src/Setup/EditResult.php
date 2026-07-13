<?php

declare(strict_types=1);

namespace Danielgnh\StatamicMcp\Setup;

enum EditResult
{
    case Applied;   // the file was changed
    case Skipped;   // the desired state was already present
    case Bailed;    // the file didn't match the expected shape — the caller prints the manual snippet
}
