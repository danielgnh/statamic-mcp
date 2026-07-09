<?php

namespace Danielgnh\StatamicMcp\Tokens;

use Illuminate\Support\Carbon;

final readonly class PlainToken
{
    public function __construct(
        public string $tokenId,
        public string $token, // full "mcp_{tokenId}_{secret}" — display exactly once, never persisted
        public string $userId,
        public ?string $name,
        public ?Carbon $expiresAt,
    ) {}
}
