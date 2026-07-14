<?php

declare(strict_types=1);

use Danielgnh\StatamicMcp\Support\AvatarGradient;

it('generates a deterministic gradient for a seed', function () {
    expect(AvatarGradient::for('operator@site.test'))
        ->toBe(AvatarGradient::for('operator@site.test'))
        ->toContain('radial-gradient(circle at ')
        ->toMatch('/hsl\(\d+, \d+%, \d+%\)/');
});

it('generates different gradients for different seeds', function () {
    expect(AvatarGradient::for('Claude'))->not->toBe(AvatarGradient::for('ChatGPT'));
});

it('falls back to an anonymous gradient for an empty seed', function () {
    expect(AvatarGradient::for(''))->toBe(AvatarGradient::for('anonymous'));
});
