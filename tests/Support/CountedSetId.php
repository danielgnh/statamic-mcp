<?php

declare(strict_types=1);

namespace Danielgnh\StatamicMcp\Tests\Support;

use Stringable;

/**
 * A stored set id that counts how often it is read, so a test can measure
 * the work a write does per set without timing it.
 */
final class CountedSetId implements Stringable
{
    public static int $reads = 0;

    public function __construct(private readonly string $id) {}

    /**
     * The same sets with counted ids at $key, a dot path for Bard nodes.
     *
     * @param  list<array<string, mixed>>  $sets
     * @return list<array<string, mixed>>
     */
    public static function in(array $sets, string $key = 'id'): array
    {
        return array_map(fn (array $set) => data_set($set, $key, new self((string) data_get($set, $key))), $sets);
    }

    public function __toString(): string
    {
        self::$reads++;

        return $this->id;
    }
}
