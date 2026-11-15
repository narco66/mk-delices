<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Référentiels techniques (tous environnements, idempotents) + jeu fictif en local/testing uniquement.
     * Aucun compte administrateur n'est créé en production : utiliser `php artisan mk:create-admin`.
     */
    public function run(): void
    {
        $this->call([RolesAndPermissionsSeeder::class, SettingsSeeder::class, ContentSeeder::class]);

        if (app()->environment(['local', 'testing'])) {
            $this->call(DemoDataSeeder::class);
        }
    }
}
