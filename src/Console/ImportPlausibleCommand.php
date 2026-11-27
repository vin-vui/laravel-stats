<?php

namespace VinVui\Stats\Console;

use Illuminate\Console\Command;

/**
 * Import de l'historique Plausible : à écrire d'après le format réel de l'export complet.
 */
class ImportPlausibleCommand extends Command
{
    protected $signature = 'stats:import-plausible {zip : Chemin du ZIP exporté depuis Plausible}';

    protected $description = "Importe l'historique d'un export Plausible";

    public function handle(): int
    {
        $this->error("Import pas encore disponible : en attente d'un export complet pour en connaître le format.");

        return self::FAILURE;
    }
}
