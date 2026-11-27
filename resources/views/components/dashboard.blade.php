@props(['title' => 'Statistiques de visites'])
@php
    $stats = \VinVui\Stats\Stats::fromRequest(request('periode'));
    $totals = $stats->totals();
    $series = $stats->series();
    $chart = new \VinVui\Stats\Support\Chart($series, 'visitors');
    $coords = $chart->coordinates();
    $monthly = \VinVui\Stats\Stats::PERIODS[$stats->period]['unit'] === 'month';
    $sources = $stats->topSources();
    $pages = $stats->topPages();
    $devices = $stats->devices();
    $deviceLabels = ['mobile' => 'Mobile', 'desktop' => 'Ordinateur', 'tablet' => 'Tablette'];
    $since = $stats->since();
    $fmt = fn ($n) => number_format((int) $n, 0, ',', ' ');
    $labelEvery = max(1, (int) ceil($series->count() / 6));
    // Historique Plausible : provenance, pages et appareils ne sont connus qu'en total sur toute la période
    $emptyText = $stats->period !== 'tout' && $totals['visitors'] > 0 && \Illuminate\Support\Facades\DB::table('stats_daily')->where('origin', 'plausible')->exists()
        ? "Pas de détail sur cette période : l'historique repris de Plausible n'a que des totaux, visibles dans « Depuis le début »."
        : 'Pas encore de données.';
    // Période à cheval sur l'historique importé : le détail ne couvre que les jours comptés par le site
    $siteSince = collect([
        \Illuminate\Support\Facades\DB::table('stats_daily')->where('origin', 'site')->min('day'),
        \Illuminate\Support\Facades\DB::table('stats_visits')->min('day'),
    ])->filter()->min();
    [$rangeStart] = $stats->range();
    $partialDetail = $stats->period !== 'tout' && $siteSince && $rangeStart->lt(\Carbon\CarbonImmutable::parse($siteSince))
        && \Illuminate\Support\Facades\DB::table('stats_daily')->where('origin', 'plausible')->exists();
@endphp

<div class="lstats" style="--lstats-accent: {{ preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) config('stats.accent')) ? config('stats.accent') : '#4f46e5' }}">
    <style>
        .lstats { --lstats-text:#111827; --lstats-muted:#6b7280; --lstats-line:#e5e7eb; --lstats-bg:#ffffff; --lstats-soft:#f9fafb;
            color:var(--lstats-text); font-family:inherit; font-size:14px; line-height:1.4; }
        .lstats *, .lstats *::before, .lstats *::after { box-sizing:border-box; }
        .lstats .ls-head { display:flex; flex-wrap:wrap; gap:12px; align-items:center; justify-content:space-between; margin-bottom:16px; }
        .lstats .ls-title { font-size:18px; font-weight:600; margin:0; }
        .lstats .ls-tabs { display:inline-flex; background:var(--lstats-soft); border:1px solid var(--lstats-line); border-radius:9999px; padding:3px; }
        .lstats .ls-tab { padding:6px 14px; border-radius:9999px; color:var(--lstats-muted); text-decoration:none; font-weight:500; font-size:13px; }
        .lstats .ls-tab.is-active { background:var(--lstats-bg); color:var(--lstats-text); box-shadow:0 1px 2px rgba(0,0,0,.08); }
        .lstats .ls-card { background:var(--lstats-bg); border:1px solid var(--lstats-line); border-radius:14px; padding:20px; }
        .lstats .ls-kpis { display:grid; grid-template-columns:repeat(3, minmax(0,1fr)); gap:16px; margin-bottom:16px; }
        .lstats .ls-kpi-label { color:var(--lstats-muted); font-size:13px; font-weight:500; }
        .lstats .ls-kpi-value { font-size:28px; font-weight:700; margin-top:4px; letter-spacing:-.01em; }
        .lstats .ls-change { display:inline-block; margin-left:8px; font-size:12px; font-weight:600; padding:2px 8px; border-radius:9999px; vertical-align:middle; }
        .lstats .ls-up { color:#047857; background:#ecfdf5; } .lstats .ls-down { color:#b91c1c; background:#fef2f2; }
        .lstats .ls-chart svg { display:block; width:100%; height:auto; overflow:visible; }
        .lstats .ls-chart .ls-grid { stroke:var(--lstats-line); stroke-width:1; }
        .lstats .ls-chart text { fill:var(--lstats-muted); font-size:13px; }
        .lstats .ls-hit:hover + .ls-dot { opacity:1; }
        .lstats .ls-dot { opacity:0; transition:opacity .1s; }
        .lstats .ls-cols { display:grid; grid-template-columns:repeat(2, minmax(0,1fr)); gap:16px; margin-top:16px; }
        .lstats .ls-card-title { display:flex; justify-content:space-between; font-weight:600; margin-bottom:12px; }
        .lstats .ls-card-title span { color:var(--lstats-muted); font-weight:500; font-size:12px; text-transform:uppercase; letter-spacing:.04em; }
        .lstats .ls-row { position:relative; display:flex; justify-content:space-between; gap:12px; padding:7px 10px; margin-bottom:4px; border-radius:8px; overflow:hidden; }
        .lstats .ls-bar { position:absolute; inset:0 auto 0 0; background:var(--lstats-accent); opacity:.10; border-radius:8px; }
        .lstats .ls-row-name { position:relative; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .lstats .ls-row-value { position:relative; font-weight:600; font-variant-numeric:tabular-nums; }
        .lstats .ls-devices { display:flex; height:10px; border-radius:9999px; overflow:hidden; background:var(--lstats-soft); margin:6px 0 14px; }
        .lstats .ls-empty { color:var(--lstats-muted); padding:12px 0; }
        .lstats .ls-foot { color:var(--lstats-muted); font-size:12px; margin-top:14px; }
        @media (max-width: 768px) { .lstats .ls-kpis, .lstats .ls-cols { grid-template-columns:1fr; } }
    </style>

    <div class="ls-head">
        <h2 class="ls-title">{{ $title }}</h2>
        <nav class="ls-tabs" aria-label="Période">
            @foreach (\VinVui\Stats\Stats::PERIODS as $key => $p)
                <a href="{{ request()->fullUrlWithQuery(['periode' => $key]) }}" class="ls-tab {{ $stats->period === $key ? 'is-active' : '' }}">{{ $p['label'] }}</a>
            @endforeach
        </nav>
    </div>

    <div class="ls-kpis">
        @foreach ([
            ['Visiteurs', $totals['visitors'], $totals['visitors_change']],
            ['Pages vues', $totals['pageviews'], $totals['pageviews_change']],
            ['Pages par visiteur', $totals['per_visitor'], null],
        ] as [$label, $value, $change])
            <div class="ls-card">
                <div class="ls-kpi-label">{{ $label }}</div>
                <div class="ls-kpi-value">
                    {{ is_float($value) ? number_format($value, 1, ',', ' ') : $fmt($value) }}
                    @if (! is_null($change))
                        <span class="ls-change {{ $change >= 0 ? 'ls-up' : 'ls-down' }}" title="Par rapport à la période précédente">{{ $change >= 0 ? '+' : '' }}{{ $change }} %</span>
                    @endif
                </div>
            </div>
        @endforeach
    </div>

    <div class="ls-card ls-chart">
        <div class="ls-card-title">Visiteurs {{ $monthly ? 'par mois' : 'par jour' }}</div>
        <svg viewBox="-60 0 1080 {{ \VinVui\Stats\Support\Chart::HEIGHT + 20 }}" role="img" aria-label="Courbe des visiteurs">
            <defs>
                <linearGradient id="lstats-fill" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0" stop-color="var(--lstats-accent)" stop-opacity=".22"/>
                    <stop offset="1" stop-color="var(--lstats-accent)" stop-opacity="0"/>
                </linearGradient>
            </defs>
            @foreach ($chart->gridLines() as [$y, $value])
                <line class="ls-grid" x1="0" x2="{{ \VinVui\Stats\Support\Chart::WIDTH }}" y1="{{ $y }}" y2="{{ $y }}"/>
                <text x="-14" y="{{ $y + 7 }}" text-anchor="end">{{ $fmt($value) }}</text>
            @endforeach
            <path d="{{ $chart->area() }}" fill="url(#lstats-fill)"/>
            <path d="{{ $chart->line() }}" fill="none" stroke="var(--lstats-accent)" stroke-width="3" stroke-linejoin="round" stroke-linecap="round"/>
            @foreach ($series->values() as $i => $point)
                @php [$x, $y] = $coords[$i]; $w = \VinVui\Stats\Support\Chart::WIDTH / max(1, $series->count() - 1); @endphp
                <rect class="ls-hit" x="{{ $x - $w / 2 }}" y="0" width="{{ $w }}" height="{{ $chart->baseline() }}" fill="transparent">
                    <title>{{ $monthly ? ucfirst($point['date']->translatedFormat('F Y')) : ucfirst($point['date']->translatedFormat('l j F')) }} : {{ $fmt($point['visitors']) }} visiteur{{ $point['visitors'] > 1 ? 's' : '' }}, {{ $fmt($point['pageviews']) }} page{{ $point['pageviews'] > 1 ? 's' : '' }} vue{{ $point['pageviews'] > 1 ? 's' : '' }}</title>
                </rect>
                <circle class="ls-dot" cx="{{ $x }}" cy="{{ $y }}" r="6" fill="var(--lstats-accent)" stroke="#fff" stroke-width="2"/>
                @if (($i % $labelEvery === 0 && $series->count() - 1 - $i >= $labelEvery / 2) || $i === $series->count() - 1)
                    <text x="{{ $x }}" y="{{ $chart->baseline() + 34 }}" text-anchor="{{ $i === 0 ? 'start' : ($i === $series->count() - 1 ? 'end' : 'middle') }}">{{ $monthly ? $point['date']->translatedFormat('M Y') : $point['date']->translatedFormat('j M') }}</text>
                @endif
            @endforeach
        </svg>
    </div>

    <div class="ls-cols">
        <div class="ls-card">
            <div class="ls-card-title">Provenance <span>Visiteurs</span></div>
            @forelse ($sources as $row)
                <div class="ls-row"><div class="ls-bar" style="width: {{ round($row['share'] * 100, 1) }}%"></div>
                    <div class="ls-row-name">{{ $row['name'] }}</div><div class="ls-row-value">{{ $fmt($row['total']) }}</div></div>
            @empty
                <div class="ls-empty">{{ $emptyText }}</div>
            @endforelse
        </div>

        <div class="ls-card">
            <div class="ls-card-title">Pages les plus vues <span>Vues</span></div>
            @forelse ($pages as $row)
                <div class="ls-row"><div class="ls-bar" style="width: {{ round($row['share'] * 100, 1) }}%"></div>
                    <div class="ls-row-name" title="{{ $row['name'] }}">{{ $row['name'] }}</div><div class="ls-row-value">{{ $fmt($row['total']) }}</div></div>
            @empty
                <div class="ls-empty">{{ $emptyText }}</div>
            @endforelse

            <div class="ls-card-title" style="margin-top:18px">Appareils <span>Part des visiteurs</span></div>
            @if ($devices->isEmpty())
                <div class="ls-empty">{{ $emptyText }}</div>
            @else
                <div class="ls-devices">
                    @foreach ($devices as $i => $row)
                        <div style="width: {{ round($row['share'] * 100, 1) }}%; background: var(--lstats-accent); opacity: {{ [1, .55, .3][$i] ?? .3 }}"></div>
                    @endforeach
                </div>
                @foreach ($devices as $i => $row)
                    <div class="ls-row" style="padding:3px 0"><div class="ls-row-name"><span style="display:inline-block;width:10px;height:10px;border-radius:3px;margin-right:8px;background:var(--lstats-accent);opacity:{{ [1, .55, .3][$i] ?? .3 }}"></span>{{ $deviceLabels[strtolower($row['name'])] ?? $row['name'] }}</div>
                        <div class="ls-row-value">{{ round($row['share'] * 100) }} %</div></div>
                @endforeach
            @endif
        </div>
    </div>

    @if ($partialDetail)
        <p class="ls-foot" style="margin-top:10px">Provenance, pages et appareils : comptés par le site depuis le {{ \Carbon\CarbonImmutable::parse($siteSince)->translatedFormat('j F Y') }} ; pour la période précédente, voir « Depuis le début ».</p>
    @endif

    <p class="ls-foot">
        Visites comptées sans cookie ni donnée personnelle ; un visiteur est compté une fois par jour.
        @if ($since) Données depuis le {{ $since }}. @endif
    </p>
</div>
