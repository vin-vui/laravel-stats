<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Visites brutes : une ligne par page vue, regroupées chaque nuit puis supprimées.
        Schema::create('stats_visits', function (Blueprint $table) {
            $table->id();
            $table->date('day')->index();
            $table->string('path', 255);
            $table->string('source', 120)->nullable();
            $table->string('device', 10);
            $table->char('visitor', 16); // empreinte anonyme, valable un seul jour
            $table->timestamp('created_at')->useCurrent();
        });

        // Clé aléatoire du jour servant à calculer l'empreinte, supprimée le lendemain.
        Schema::create('stats_salts', function (Blueprint $table) {
            $table->date('day')->primary();
            $table->char('salt', 64);
        });

        // Résumés par jour (alimentés par l'agrégation nocturne ou l'import Plausible).
        Schema::create('stats_daily', function (Blueprint $table) {
            $table->date('day')->primary();
            $table->unsignedInteger('visitors')->default(0);
            $table->unsignedInteger('pageviews')->default(0);
            $table->string('origin', 10)->default('site'); // « site » ou « plausible »
        });

        Schema::create('stats_daily_pages', function (Blueprint $table) {
            $table->date('day');
            $table->string('path', 255);
            $table->unsignedInteger('visitors')->default(0);
            $table->unsignedInteger('pageviews')->default(0);
            $table->unique(['day', 'path']);
        });

        Schema::create('stats_daily_sources', function (Blueprint $table) {
            $table->date('day');
            $table->string('source', 120);
            $table->unsignedInteger('visitors')->default(0);
            $table->unique(['day', 'source']);
        });

        Schema::create('stats_daily_devices', function (Blueprint $table) {
            $table->date('day');
            $table->string('device', 10);
            $table->unsignedInteger('visitors')->default(0);
            $table->unique(['day', 'device']);
        });
    }

    public function down(): void
    {
        foreach (['stats_daily_devices', 'stats_daily_sources', 'stats_daily_pages', 'stats_daily', 'stats_salts', 'stats_visits'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
