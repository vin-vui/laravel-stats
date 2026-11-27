<?php

namespace VinVui\Stats\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use VinVui\Stats\Stats;
use ZipArchive;

/**
 * Reprise de l'historique Plausible à partir des exports CSV du tableau de bord :
 *  - export « Tout l'historique » (obligatoire) : chiffres par mois, et totaux de provenance,
 *    pages et appareils sur toute la période ;
 *  - export « 28 jours » (facultatif) : chiffres jour par jour des 28 derniers jours.
 *
 * Les jours déjà comptés par le site lui-même ne sont jamais écrasés.
 * La commande peut être relancée : l'import précédent est d'abord effacé.
 */
class ImportPlausibleCommand extends Command
{
    protected $signature = 'stats:import-plausible
        {all : ZIP de l\'export « Tout l\'historique » du tableau de bord Plausible}
        {--days= : ZIP de l\'export « 28 jours » (chiffres jour par jour)}';

    protected $description = "Reprend l'historique d'un site depuis les exports CSV de Plausible";

    private const ORIGIN = 'plausible';

    public function handle(): int
    {
        $all = $this->readZip($this->argument('all'));
        $days = $this->option('days') ? $this->readZip($this->option('days')) : [];
        if (! isset($all['visitors.csv'])) {
            $this->error('visitors.csv introuvable dans l\'export « Tout l\'historique ».');

            return self::FAILURE;
        }

        $months = $this->rows($all['visitors.csv']); // date = 1er du mois
        $daily = isset($days['visitors.csv']) ? $this->rows($days['visitors.csv']) : [];
        $siteDays = DB::table('stats_daily')->where('origin', 'site')->pluck('day')->map(fn ($d) => (string) $d)->flip();

        DB::transaction(function () use ($months, $daily, $all, $siteDays) {
            // 1. Effacer un import précédent
            $old = DB::table('stats_daily')->where('origin', self::ORIGIN)->pluck('day');
            foreach (['stats_daily_pages', 'stats_daily_sources', 'stats_daily_devices'] as $table) {
                DB::table($table)->whereIn('day', $old)->delete();
            }
            DB::table('stats_daily')->where('origin', self::ORIGIN)->delete();

            // 2. Jours détaillés (export 28 jours)
            $dailyByMonth = [];
            foreach ($daily as $r) {
                $day = $r['date'];
                $dailyByMonth[substr($day, 0, 7)]['visitors'] = ($dailyByMonth[substr($day, 0, 7)]['visitors'] ?? 0) + $r['visitors'];
                $dailyByMonth[substr($day, 0, 7)]['pageviews'] = ($dailyByMonth[substr($day, 0, 7)]['pageviews'] ?? 0) + $r['pageviews'];
                if (! isset($siteDays[$day])) {
                    DB::table('stats_daily')->insert(['day' => $day, 'visitors' => $r['visitors'], 'pageviews' => $r['pageviews'], 'origin' => self::ORIGIN]);
                }
            }

            // 3. Mois : le reste du mois non couvert par les jours détaillés, inscrit au 1er du mois
            foreach ($months as $r) {
                $month = substr($r['date'], 0, 7);
                $first = $month.'-01';
                $visitors = max(0, $r['visitors'] - ($dailyByMonth[$month]['visitors'] ?? 0));
                $pageviews = max(0, $r['pageviews'] - ($dailyByMonth[$month]['pageviews'] ?? 0));
                if (($visitors === 0 && $pageviews === 0) || isset($siteDays[$first])) {
                    continue;
                }
                $existing = DB::table('stats_daily')->where('day', $first)->first();
                DB::table('stats_daily')->updateOrInsert(['day' => $first], [
                    'visitors' => $visitors + (int) ($existing->visitors ?? 0),
                    'pageviews' => $pageviews + (int) ($existing->pageviews ?? 0),
                    'origin' => self::ORIGIN,
                ]);
            }

            // 4. Provenance, pages, appareils : totaux sur toute la période, inscrits au premier jour de l'historique
            $firstDay = collect($months)->pluck('date')->concat(collect($daily)->pluck('date'))->min();
            if (! $firstDay) {
                return;
            }
            if (! DB::table('stats_daily')->where('day', $firstDay)->exists()) {
                DB::table('stats_daily')->insert(['day' => $firstDay, 'visitors' => 0, 'pageviews' => 0, 'origin' => self::ORIGIN]);
            }
            $this->breakdown($all['sources.csv'] ?? null, 'stats_daily_sources', 'source', $firstDay,
                fn ($name) => $name === 'Direct / None' ? Stats::DIRECT : $name);
            $this->breakdown($all['devices.csv'] ?? null, 'stats_daily_devices', 'device', $firstDay,
                fn ($name) => ['desktop' => 'desktop', 'laptop' => 'desktop', 'mobile' => 'mobile', 'tablet' => 'tablet'][strtolower($name)] ?? '');
            // Pages techniques (connexion…) exclues, comme pour le comptage du site
            $ignored = (array) config('stats.ignore_paths', []);
            $this->breakdown($all['pages.csv'] ?? null, 'stats_daily_pages', 'path', $firstDay,
                fn ($name) => \Illuminate\Support\Str::is($ignored, ltrim($name, '/')) ? '' : $name, withPageviews: true);
        });

        $count = DB::table('stats_daily')->where('origin', self::ORIGIN)->count();
        $this->info("Historique Plausible importé : $count jour(s) ou mois, depuis le ".DB::table('stats_daily')->where('origin', self::ORIGIN)->min('day').'.');

        return self::SUCCESS;
    }

    private function breakdown(?string $csv, string $table, string $column, string $day, callable $name, bool $withPageviews = false): void
    {
        if (! $csv) {
            return;
        }
        $totals = [];
        foreach ($this->csv($csv) as $r) {
            $raw = trim($r['name'] ?? '');
            $key = $raw === '(not set)' ? '' : mb_substr($name($raw), 0, $column === 'path' ? 255 : 120);
            if ($key === '') {
                continue;
            }
            $totals[$key]['visitors'] = ($totals[$key]['visitors'] ?? 0) + (int) ($r['visitors'] ?? 0);
            $totals[$key]['pageviews'] = ($totals[$key]['pageviews'] ?? 0) + (int) ($r['pageviews'] ?? 0);
        }
        DB::table($table)->where('day', $day)->delete();
        foreach ($totals as $key => $t) {
            DB::table($table)->insert(['day' => $day, $column => $key, 'visitors' => $t['visitors']]
                + ($withPageviews ? ['pageviews' => $t['pageviews']] : []));
        }
    }

    /** Lignes de visitors.csv : date au format AAAA-MM-JJ, nombres entiers. */
    private function rows(string $csv): array
    {
        $rows = [];
        foreach ($this->csv($csv) as $r) {
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $r['date'] ?? '')) {
                continue;
            }
            $rows[] = ['date' => CarbonImmutable::parse($r['date'])->toDateString(), 'visitors' => (int) $r['visitors'], 'pageviews' => (int) $r['pageviews']];
        }

        return $rows;
    }

    private function csv(string $content): array
    {
        $lines = array_values(array_filter(preg_split('/\r?\n/', trim($content)), 'strlen'));
        $header = str_getcsv(array_shift($lines) ?? '', ',', '"', '');

        return array_map(fn ($l) => array_combine($header, array_pad(str_getcsv($l, ',', '"', ''), count($header), '')) ?: [], $lines);
    }

    /** Fichiers CSV d'un ZIP (ou d'un dossier déjà décompressé). */
    private function readZip(string $path): array
    {
        $files = [];
        if (is_dir($path)) {
            foreach (glob(rtrim($path, '/').'/*.csv') as $f) {
                $files[basename($f)] = file_get_contents($f);
            }

            return $files;
        }
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new \RuntimeException("Impossible d'ouvrir $path");
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (str_ends_with($name, '.csv') && ! str_contains($name, '/')) {
                $files[$name] = $zip->getFromIndex($i);
            }
        }
        $zip->close();

        return $files;
    }
}
