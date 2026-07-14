<?php

namespace Danielgnh\StatamicMcp\Support;

/**
 * PHP port of the deterministic mesh-gradient fallback in Statamic's
 * ui/Avatar.vue, so server-rendered avatars on the OAuth consent screen get
 * the same look as control panel avatars without any JavaScript.
 */
class AvatarGradient
{
    public static function for(string $seed): string
    {
        $hash = abs(self::hash($seed !== '' ? $seed : 'anonymous'));
        $baseHue = $hash % 360;

        $hues = match ($hash % 3) {
            0 => [$baseHue, ($baseHue + 180) % 360],
            1 => [$baseHue, ($baseHue + 120) % 360, ($baseHue + 240) % 360],
            2 => [$baseHue, ($baseHue + 30) % 360, ($baseHue + 330) % 360, ($baseHue + 60) % 360],
        };

        $colors = array_map(
            fn (int $hue) => sprintf('hsl(%d, %d%%, %d%%)', $hue, 70 + ($hue % 25), 35 + (($hue * 2) % 15)),
            $hues,
        );

        $gradients = collect($hues)
            ->map(function (int $hue, int $i) use ($colors) {
                $x = 20 + ($hue % 60);
                $y = 20 + (($hue * 2) % 60);

                return "radial-gradient(circle at {$x}% {$y}%, {$colors[$i]} 0%, transparent 80%)";
            })
            ->implode(', ');

        return "{$gradients}, {$colors[0]}";
    }

    /**
     * The same accumulator Avatar.vue uses, wrapped to 32 bits like
     * JavaScript's bitwise operators.
     */
    private static function hash(string $seed): int
    {
        $hash = 0;

        foreach (mb_str_split($seed) as $char) {
            $hash = self::toInt32(mb_ord($char) + (($hash << 5) - $hash));
        }

        return $hash;
    }

    private static function toInt32(int $value): int
    {
        $value &= 0xFFFFFFFF;

        return $value >= 0x80000000 ? $value - 0x100000000 : $value;
    }
}
