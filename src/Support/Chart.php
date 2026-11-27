<?php

namespace VinVui\Stats\Support;

use Illuminate\Support\Collection;

/**
 * Courbe en SVG calculée côté serveur : aucune bibliothèque JavaScript,
 * rendu identique quel que soit le framework de l'admin.
 */
class Chart
{
    public const WIDTH = 1000;
    public const HEIGHT = 260;
    private const PAD_TOP = 16;
    private const PAD_BOTTOM = 28;

    public function __construct(private Collection $points, private string $metric = 'visitors')
    {
    }

    /** Valeur haute de l'axe : 4 graduations entières et « rondes » (1, 2, 5, 10, 20…). */
    public function max(): int
    {
        return $this->step() * 4;
    }

    private function step(): int
    {
        $raw = max(1, (int) $this->points->max($this->metric)) / 4;
        $magnitude = 10 ** max(0, (int) floor(log10($raw)));
        foreach ([1, 2, 5, 10] as $nice) {
            if ($nice * $magnitude >= $raw) {
                return (int) max(1, $nice * $magnitude);
            }
        }

        return (int) (10 * $magnitude);
    }

    /** Coordonnées [x, y] de chaque point. */
    public function coordinates(): array
    {
        $count = max(1, $this->points->count() - 1);
        $max = $this->max();
        $usable = self::HEIGHT - self::PAD_TOP - self::PAD_BOTTOM;

        return $this->points->values()->map(fn ($p, $i) => [
            round($i * self::WIDTH / $count, 1),
            round(self::PAD_TOP + $usable - ($p[$this->metric] / $max) * $usable, 1),
        ])->all();
    }

    public function line(): string
    {
        return collect($this->coordinates())->map(fn ($c, $i) => ($i ? 'L' : 'M').$c[0].' '.$c[1])->implode(' ');
    }

    public function area(): string
    {
        $coords = $this->coordinates();
        if (! $coords) {
            return '';
        }
        $base = self::HEIGHT - self::PAD_BOTTOM;

        return $this->line().' L'.end($coords)[0].' '.$base.' L'.$coords[0][0].' '.$base.' Z';
    }

    /** Lignes horizontales de repère : [y, valeur]. */
    public function gridLines(int $steps = 4): array
    {
        $max = $this->max();
        $usable = self::HEIGHT - self::PAD_TOP - self::PAD_BOTTOM;

        return collect(range(0, $steps))->map(fn ($i) => [
            round(self::PAD_TOP + $usable - $usable * $i / $steps, 1),
            (int) round($max * $i / $steps),
        ])->all();
    }

    public function baseline(): int
    {
        return self::HEIGHT - self::PAD_BOTTOM;
    }
}
