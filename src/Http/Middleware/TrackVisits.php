<?php

namespace VinVui\Stats\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use VinVui\Stats\Recorder;

/**
 * Compte les pages vues APRÈS l'envoi de la réponse (middleware « terminable ») :
 * aucun impact sur le temps d'affichage, et aucune erreur ne peut atteindre le visiteur.
 */
class TrackVisits
{
    public function __construct(private Recorder $recorder)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            if ($this->recorder->shouldRecord($request, $response)) {
                $this->recorder->record($request);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
