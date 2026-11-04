<?php

namespace Danielgnh\StatamicMcp\Setup;

class EnvWriter
{
    public function apply(string $path, string $key, string $value): EditResult
    {
        if (! is_file($path) || ! is_writable($path)) {
            return EditResult::Bailed;
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            return EditResult::Bailed;
        }

        $pattern = '/^([ \t]*(?:export[ \t]+)?'.preg_quote($key, '/').'[ \t]*=[ \t]*)(.*)$/m';

        if (preg_match_all($pattern, $contents, $matches)) {
            if (array_unique(array_map(trim(...), $matches[2])) === [$value]) {
                return EditResult::Skipped;
            }

            file_put_contents($path, preg_replace_callback($pattern, fn (array $assignment) => $assignment[1].$value, $contents));

            return EditResult::Applied;
        }

        file_put_contents($path, rtrim($contents, "\n")."\n".$key.'='.$value."\n");

        return EditResult::Applied;
    }

    public function snippet(string $key, string $value): string
    {
        return $key.'='.$value;
    }
}
