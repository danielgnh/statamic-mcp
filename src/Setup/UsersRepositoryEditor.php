<?php

namespace Danielgnh\StatamicMcp\Setup;

/**
 * Flips 'repository' => '...' to 'eloquent' in config/statamic/users.php.
 * Anchor-based: only the first quoted 'repository' assignment is touched;
 * anything else (env() calls, missing key) bails to the manual snippet.
 */
class UsersRepositoryEditor
{
    public function apply(string $path): EditResult
    {
        if (! is_file($path) || ! is_writable($path)) {
            return EditResult::Bailed;
        }

        $contents = file_get_contents($path);

        if (! preg_match("/'repository'\s*=>\s*'([^']+)'/", $contents, $matches)) {
            return EditResult::Bailed;
        }

        if ($matches[1] === 'eloquent') {
            return EditResult::Skipped;
        }

        file_put_contents($path, preg_replace(
            "/'repository'\s*=>\s*'[^']+'/",
            "'repository' => 'eloquent'",
            $contents,
            1
        ));

        return EditResult::Applied;
    }

    public function snippet(): string
    {
        return "'repository' => 'eloquent',";
    }
}
