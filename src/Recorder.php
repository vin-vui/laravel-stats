<?php

namespace VinVui\Stats;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Enregistre une page vue. Point d'entrée unique : si le comptage côté serveur
 * se révélait trop bruité, seul ce fichier serait à remplacer.
 */
class Recorder
{
    /** Navigation interne au site, exclue de la provenance. */
    public const INTERNAL = '';

    /** Robots, outils et contrôles de disponibilité (en plus de tout « bot », « crawl », « spider »). */
    private const BOT_PATTERN = '/bot|crawl|spider|slurp|scrape|curl|wget|python|httpclient|okhttp|go-http|java\/|libwww|headless|lighthouse|pingdom|uptime|monitor|healthcheck|preview|facebookexternalhit|whatsapp|telegram|discord|feedfetcher|validator|^$/i';

    public function shouldRecord(Request $request, Response $response): bool
    {
        if (! config('stats.enabled', true) || ! $request->isMethod('GET')) {
            return false;
        }
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            return false;
        }
        if ($request->prefetch()) {
            return false; // préchargement : la page n'a pas (encore) été vue
        }
        // Navigation Inertia (sites en Vue) : chaque changement de page est une requête JSON,
        // à compter comme une page vue, sauf les rechargements partiels de données
        $inertiaPage = $request->hasHeader('X-Inertia') && ! $request->hasHeader('X-Inertia-Partial-Data')
            && ! $request->hasHeader('X-Inertia-Partial-Component');
        if (! $inertiaPage) {
            if (! str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
                return false;
            }
            // Requêtes Livewire, Inertia partielles ou JavaScript : pas une nouvelle page
            if ($request->ajax() || $request->hasHeader('X-Livewire') || $request->hasHeader('X-Inertia')) {
                return false;
            }
        }
        // Les personnes connectées sont les administrateurs du site
        if ($request->user()) {
            return false;
        }
        // Le serveur lui-même (contrôle de santé du script de déploiement)
        if (in_array($request->ip(), ['127.0.0.1', '::1'], true)) {
            return false;
        }
        if (preg_match(self::BOT_PATTERN, (string) $request->userAgent())) {
            return false;
        }
        // Les vrais navigateurs envoient toujours leur langue ; la plupart des robots non déclarés, non
        if (! $request->hasHeader('Accept-Language')) {
            return false;
        }
        $path = ltrim($request->path(), '/');
        foreach ((array) config('stats.ignore_paths', []) as $pattern) {
            if (Str::is($pattern, $path)) {
                return false;
            }
        }

        return true;
    }

    public function record(Request $request): void
    {
        try {
            $today = CarbonImmutable::today();

            DB::table('stats_visits')->insert([
                'day' => $today->toDateString(),
                'path' => Str::limit('/'.ltrim($request->path(), '/'), 250, ''),
                'source' => $this->source($request),
                'device' => $this->device((string) $request->userAgent()),
                'visitor' => $this->visitor($request, $today),
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            // Une statistique perdue ne doit jamais casser une page publique
            report($e);
        }
    }

    /** Empreinte du jour : IP + navigateur + site, avec une clé aléatoire renouvelée chaque jour. L'IP n'est jamais stockée. */
    private function visitor(Request $request, CarbonImmutable $day): string
    {
        $salt = DB::table('stats_salts')->where('day', $day->toDateString())->value('salt');
        if (! $salt) {
            $salt = bin2hex(random_bytes(32));
            DB::table('stats_salts')->insertOrIgnore(['day' => $day->toDateString(), 'salt' => $salt]);
            $salt = DB::table('stats_salts')->where('day', $day->toDateString())->value('salt');
            DB::table('stats_salts')->where('day', '<', $day->toDateString())->delete();
        }

        return substr(hash('sha256', $salt.'|'.$request->ip().'|'.$request->userAgent().'|'.$request->getHost()), 0, 16);
    }

    private function source(Request $request): ?string
    {
        $host = strtolower((string) parse_url((string) $request->headers->get('referer'), PHP_URL_HOST));
        $host = preg_replace('/^(www|m|l|lm)\./', '', $host);
        if ($host === '') {
            return null; // accès direct : lien tapé, favori, application…
        }
        if ($host === preg_replace('/^www\./', '', strtolower($request->getHost()))) {
            return self::INTERNAL; // clic d'une page à l'autre du site : pas une provenance
        }

        $known = [
            'google' => 'Google', 'bing.com' => 'Bing', 'duckduckgo.com' => 'DuckDuckGo', 'qwant.com' => 'Qwant',
            'ecosia.org' => 'Ecosia', 'yahoo' => 'Yahoo', 'facebook.com' => 'Facebook', 'instagram.com' => 'Instagram',
            'linkedin.com' => 'LinkedIn', 't.co' => 'X (Twitter)', 'twitter.com' => 'X (Twitter)', 'x.com' => 'X (Twitter)',
            'youtube.com' => 'YouTube', 'chatgpt.com' => 'ChatGPT', 'perplexity.ai' => 'Perplexity',
        ];
        foreach ($known as $needle => $name) {
            if ($host === $needle || str_ends_with($host, '.'.$needle) || (! str_contains($needle, '.') && preg_match('/(^|\.)'.$needle.'\./', $host))) {
                return $name;
            }
        }

        return Str::limit($host, 120, '');
    }

    private function device(string $ua): string
    {
        if (preg_match('/ipad|tablet|kindle|silk|playbook|(android(?!.*mobile))/i', $ua)) {
            return 'tablet';
        }

        return preg_match('/mobi|iphone|ipod|android|blackberry|opera mini|iemobile/i', $ua) ? 'mobile' : 'desktop';
    }
}
