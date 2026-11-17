<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Audit M1 : la réponse mémorisée (rejeu d'une requête idempotente) contient le jeton de suivi de la
 * commande ; elle est désormais CHIFFRÉE (APP_KEY) au lieu d'être stockée en JSON lisible.
 * Les clés existantes sont supprimées : elles expirent sous 24 h et ne servent qu'au rejeu immédiat.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('idempotency_keys')->delete();
        DB::statement('ALTER TABLE idempotency_keys ALTER COLUMN response_json TYPE text USING NULL');
    }

    public function down(): void
    {
        DB::table('idempotency_keys')->delete();
        DB::statement('ALTER TABLE idempotency_keys ALTER COLUMN response_json TYPE jsonb USING NULL');
    }
};
