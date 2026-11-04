<?php

declare(strict_types=1);

namespace Danielgnh\StatamicMcp\Tokens;

use Illuminate\Support\Carbon;

final readonly class PlainToken
{
    public function __construct(
        public string $tokenId,
        public string $token,
        public string $userId,
        public ?string $name,
        public ?Carbon $expiresAt,
    ) {}
}
