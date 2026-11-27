<?php

namespace VinVui\Stats;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lecture des statistiques pour le tableau de bord.
 * Combine les résumés par jour et les visites brutes des jours pas encore regroupés.
 */
class Stats
{
    public const PERIODS = [
        '7j' => ['label' => '7 jours', 'days' => 7, 'unit' => 'day'],
        '30j' => ['label' => '30 jours', 'days' => 30, 'unit' => 'day'],
        '12m' => ['label' => '12 mois', 'days' => 365, 'unit' => 'month'],
        'tout' => ['label' => 'Depuis le début', 'days' => null, 'unit' => 'month'],
    ];

    public const DIRECT = 'Accès direct';

    public function __construct(public readonly string $period = '30j')
    {
    }

    public static function fromRequest(?string $period): self
    {
        return new self(array_key_exists((string) $period, self::PERIODS) ? $period : '30j');
    }

    public function range(): array
    {
        $today = CarbonImmutable::today();
        $conf = self::PERIODS[$this->period];

        if ($this->period === 'tout') {
            $first = DB::table('stats_daily')->min('day') ?? DB::table('stats_visits')->min('day');
            $start = $first ? CarbonImmutable::parse($first)->startOfMonth() : $today->subMonths(11)->startOfMonth();

            return [min($start, $today->startOfMonth()), $today];
        }

        $start = $conf['unit'] === 'month'
            ? $today->subMonths(11)->startOfMonth()
            : $today->subDays($conf['days'] - 1);

        return [$start, $today];
    }

    /** Totaux de la période et de la période précédente (pour l'évolution). */
    public function totals(): array
    {
        [$start, $end] = $this->range();
        $days = $start->diffInDays($end) + 1;
        $current = $this->sumDaily($start, $end);
        // Pas de « période précédente » pour l'historique complet
        $previous = $this->period === 'tout' ? ['visitors' => 0, 'pageviews' => 0] : $this->sumDaily($start->subDays($days), $start->subDay());

        $variation = fn ($now, $before) => $before > 0 ? (int) round(($now - $before) * 100 / $before) : null;

        return [
            'visitors' => $current['visitors'],
            'pageviews' => $current['pageviews'],
            'per_visitor' => $current['visitors'] > 0 ? round($current['pageviews'] / $current['visitors'], 1) : 0,
            'visitors_change' => $variation($current['visitors'], $previous['visitors']),
            'pageviews_change' => $variation($current['pageviews'], $previous['pageviews']),
        ];
    }

    /** Série pour la courbe : un point par jour, ou par mois sur 12 mois. */
    public function series(): Collection
    {
        [$start, $end] = $this->range();
        $byDay = $this->dailyRows($start, $end)->keyBy('day');
        $monthly = self::PERIODS[$this->period]['unit'] === 'month';
        $points = collect();

        for ($d = $start; $d->lte($end); $d = $monthly ? $d->addMonth() : $d->addDay()) {
            if ($monthly) {
                $rows = $byDay->filter(fn ($r, $day) => str_starts_with($day, $d->format('Y-m')));
                $points->push(['date' => $d, 'visitors' => $rows->sum('visitors'), 'pageviews' => $rows->sum('pageviews')]);
            } else {
                $row = $byDay->get($d->toDateString());
                $points->push(['date' => $d, 'visitors' => (int) ($row['visitors'] ?? 0), 'pageviews' => (int) ($row['pageviews'] ?? 0)]);
            }
        }

        return $points;
    }

    public function topPages(int $limit = 8): Collection
    {
        return $this->breakdown('stats_daily_pages', 'path', 'path', $limit, 'pageviews');
    }

    public function topSources(int $limit = 8): Collection
    {
        return $this->breakdown('stats_daily_sources', 'source', 'source', $limit, 'visitors');
    }

    public function devices(): Collection
    {
        return $this->breakdown('stats_daily_devices', 'device', 'device', 3, 'visitors');
    }

    /** Premier jour disposant de données, pour indiquer d'où part l'historique. */
    public function since(): ?string
    {
        $first = DB::table('stats_daily')->min('day') ?? DB::table('stats_visits')->min('day');

        return $first ? CarbonImmutable::parse($first)->locale(config('stats.locale', 'fr'))->translatedFormat('j F Y') : null;
    }

    // --- Interne

    private function sumDaily(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = $this->dailyRows($start, $end);

        return ['visitors' => (int) $rows->sum('visitors'), 'pageviews' => (int) $rows->sum('pageviews')];
    }

    /** Résumés par jour, complétés par les visites brutes des jours pas encore regroupés. */
    private function dailyRows(CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        $aggregated = DB::table('stats_daily')
            ->whereBetween('day', [$start->toDateString(), $end->toDateString()])
            ->get(['day', 'visitors', 'pageviews'])
            ->map(fn ($r) => ['day' => (string) $r->day, 'visitors' => (int) $r->visitors, 'pageviews' => (int) $r->pageviews]);

        $done = $aggregated->pluck('day')->all();

        $raw = DB::table('stats_visits')
            ->whereBetween('day', [$start->toDateString(), $end->toDateString()])
            ->whereNotIn('day', $done)
            ->groupBy('day')
            ->get([DB::raw('day'), DB::raw('COUNT(DISTINCT visitor) as visitors'), DB::raw('COUNT(*) as pageviews')])
            ->map(fn ($r) => ['day' => (string) $r->day, 'visitors' => (int) $r->visitors, 'pageviews' => (int) $r->pageviews]);

        return $aggregated->concat($raw)->values();
    }

    private function breakdown(string $table, string $column, string $rawColumn, int $limit, string $metric): Collection
    {
        [$start, $end] = $this->range();
        $from = $start->toDateString();
        $to = $end->toDateString();
        $done = DB::table('stats_daily')->whereBetween('day', [$from, $to])->pluck('day')->map(fn ($d) => (string) $d)->all();

        $aggregated = DB::table($table)->whereBetween('day', [$from, $to])
            ->groupBy($column)
            ->get([DB::raw("$column as name"), DB::raw("SUM($metric) as total")]);

        $rawMetric = $metric === 'pageviews' ? 'COUNT(*)' : 'COUNT(DISTINCT visitor, day)';
        // Visite sans provenance = accès direct (lien tapé, favori, application…)
        $rawName = $rawColumn === 'source' ? "COALESCE(source, '".self::DIRECT."')" : $rawColumn;
        $raw = DB::table('stats_visits')->whereBetween('day', [$from, $to])->whereNotIn('day', $done)
            ->when($rawColumn === 'source', fn ($q) => $q->where(fn ($w) => $w->whereNull('source')->orWhere('source', '<>', '')))
            ->groupBy(DB::raw($rawName))
            ->get([DB::raw("$rawName as name"), DB::raw("$rawMetric as total")]);

        $rows = $aggregated->concat($raw)
            ->groupBy('name')
            ->map(fn ($g, $name) => ['name' => (string) $name, 'total' => (int) $g->sum('total')])
            ->sortByDesc('total')
            ->values();

        $sum = max(1, $rows->sum('total'));

        return $rows->take($limit)->map(fn ($r) => $r + ['share' => $r['total'] / $sum]);
    }
}
