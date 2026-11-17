<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Audit I7 : les déclencheurs d'immuabilité bloquaient UPDATE et DELETE ligne à ligne, mais pas TRUNCATE
 * (qui vide une table d'un coup). Journal d'audit, historique des commandes et factures en sont désormais
 * protégés aussi. (Un administrateur de la base peut toujours retirer un déclencheur : c'est le rôle du
 * compte applicatif sans droits de propriétaire, recommandé en production, de l'en empêcher.)
 */
return new class extends Migration
{
    private const TABLES = ['audit_logs', 'order_events', 'invoices'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            DB::unprepared("CREATE TRIGGER trg_{$table}_no_truncate BEFORE TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION mk_forbid_mutation();");
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS trg_{$table}_no_truncate ON {$table};");
        }
    }
};
