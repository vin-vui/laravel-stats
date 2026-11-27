# laravel-stats

Statistiques de visites maison pour les sites Laravel : comptage côté serveur, sans cookie ni donnée personnelle, tableau de bord affiché directement dans l'admin du site.

## Installation dans un site

1. Déclarer le dépôt et installer le paquet :

   ```bash
   composer config repositories.laravel-stats vcs https://github.com/vin-vui/laravel-stats
   composer require vin-vui/laravel-stats:^0.1
   php artisan migrate
   ```

2. Afficher le tableau de bord dans une page de l'admin (Blade) :

   ```blade
   <x-stats::dashboard />
   ```

3. Activer le planificateur Laravel du site (Forge : onglet du site, « Laravel scheduler ») pour le regroupement de chaque nuit (`stats:aggregate`, 00 h 20). Sans planificateur, rien n'est perdu : le tableau de bord lit aussi les visites pas encore regroupées.

4. Couleur du tableau de bord, dans le `.env` (entre guillemets, sinon `#` démarre un commentaire) :

   ```
   STATS_ACCENT="#059669"
   ```

## Ce qui est compté

Toute page HTML servie en GET avec succès, après l'envoi de la réponse. Sont ignorés : les robots et navigateurs automatisés, les personnes connectées (l'admin), le serveur lui-même, les requêtes Livewire, Inertia et AJAX, les pages techniques listées dans `config/stats.php` (`ignore_paths`).

Un visiteur est reconnu à l'aide d'une empreinte de son adresse IP et de son navigateur, calculée avec une clé aléatoire renouvelée chaque jour : l'adresse IP n'est jamais enregistrée et l'empreinte ne permet pas de suivre quelqu'un d'un jour à l'autre.

## Reprise de l'historique Plausible

Depuis le tableau de bord Plausible, télécharger l'export CSV de la période « Tout l'historique » et, si possible, celui des 28 derniers jours, puis :

```bash
php artisan stats:import-plausible chemin/export-tout.zip --days=chemin/export-28-jours.zip
```

La courbe est reprise mois par mois (et jour par jour sur les 28 derniers jours) ; la provenance, les pages et les appareils ne sont connus qu'en total sur toute la période et s'affichent dans « Depuis le début ». La commande peut être relancée sans créer de doublon.
