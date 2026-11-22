<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Audit S-13 (22/11/2026) : un numéro de téléphone désigne au plus un compte actif (connexion par
 * téléphone). Index partiel : les comptes supprimés (anonymisés) et les comptes sans numéro sont ignorés.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE UNIQUE INDEX users_phone_active_unique ON users (phone) WHERE phone IS NOT NULL AND phone <> '' AND anonymized_at IS NULL");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS users_phone_active_unique');
    }
};
