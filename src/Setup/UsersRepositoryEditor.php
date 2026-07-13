<?php

namespace Danielgnh\StatamicMcp\Setup;

/**
 * Flips 'repository' => '...' in config/statamic/users.php — to 'eloquent'
 * during setup, back to the original when the installer reverts a failed
 * import. Anchor-based: only the first quoted 'repository' assignment at the
 * start of a line is touched — commented-out lines are ignored; anything else
 * (env() calls, missing key) bails to the manual snippet.
 */
class UsersRepositoryEditor
{
    public function apply(string $path, string $repository = 'eloquent'): EditResult
    {
        if (! is_file($path) || ! is_writable($path)) {
            return EditResult::Bailed;
        }

        $contents = file_get_contents($path);

        if (! preg_match("/^\s*'repository'\s*=>\s*'([^']+)'/m", $contents, $matches)) {
            return EditResult::Bailed;
        }

        if ($matches[1] === $repository) {
            return EditResult::Skipped;
        }

        file_put_contents($path, preg_replace_callback(
            "/^(\s*)'repository'\s*=>\s*'[^']+'/m",
            fn (array $m) => $m[1]."'repository' => '{$repository}'",
            $contents,
            1
        ));

        return EditResult::Applied;
    }

    public function snippet(string $repository = 'eloquent'): string
    {
        return "'repository' => '{$repository}',";
    }
}
