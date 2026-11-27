<?php

namespace VinVui\Stats\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use VinVui\Stats\Stats;

/**
 * Regroupe les visites brutes par jour puis supprime le détail.
 * Peut être relancée sans risque : chaque jour est recalculé entièrement,
 * et les jours manqués (planificateur arrêté) sont rattrapés.
 */
class AggregateCommand extends Command
{
    protected $signature = 'stats:aggregate';

    protected $description = 'Regroupe les visites par jour et purge les visites brutes';

    public function handle(): int
    {
        $today = CarbonImmutable::today()->toDateString();
        $days = DB::table('stats_visits')->where('day', '<', $today)->distinct()->orderBy('day')->pluck('day');

        foreach ($days as $day) {
            $day = (string) $day;
            DB::transaction(function () use ($day) {
                $visits = DB::table('stats_visits')->where('day', $day);

                DB::table('stats_daily')->updateOrInsert(['day' => $day], [
                    'visitors' => (clone $visits)->distinct()->count('visitor'),
                    'pageviews' => (clone $visits)->count(),
                    'origin' => 'site',
                ]);

                foreach ([
                    ['stats_daily_pages', 'path', 'path', true],
                    ['stats_daily_sources', 'source', "COALESCE(source, '".Stats::DIRECT."')", false],
                    ['stats_daily_devices', 'device', 'device', false],
                ] as [$table, $column, $expression, $withPageviews]) {
                    DB::table($table)->where('day', $day)->delete();
                    $rows = (clone $visits)
                        ->when($column === 'source', fn ($q) => $q->where(fn ($w) => $w->whereNull('source')->orWhere('source', '<>', '')))
                        ->groupBy(DB::raw($expression))
                        ->get([DB::raw("$expression as name"), DB::raw('COUNT(DISTINCT visitor) as visitors'), DB::raw('COUNT(*) as pageviews')])
                        ->map(fn ($r) => ['day' => $day, $column => $r->name, 'visitors' => $r->visitors]
                            + ($withPageviews ? ['pageviews' => $r->pageviews] : []))
                        ->all();
                    if ($rows) {
                        DB::table($table)->insert($rows);
                    }
                }
            });
            $this->line("Jour regroupé : $day");
        }

        $keep = max(0, (int) config('stats.keep_raw_days', 2));
        $limit = CarbonImmutable::today()->subDays($keep)->toDateString();
        $deleted = DB::table('stats_visits')->where('day', '<', $limit)->whereIn('day', DB::table('stats_daily')->select('day'))->delete();
        DB::table('stats_salts')->where('day', '<', $today)->delete();

        $this->info(count($days).' jour(s) regroupé(s), '.$deleted.' visite(s) brute(s) purgée(s).');

        return self::SUCCESS;
    }
}
