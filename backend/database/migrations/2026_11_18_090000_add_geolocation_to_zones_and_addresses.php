<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Géolocalisation (décisions DEC-GEO-01/02 du 17/11/2026) — migration additive uniquement :
 * - contour de chaque zone (GeoJSON Polygon/MultiPolygon, coordonnées [lng, lat]) dessiné par MK Délices ;
 *   sans contour, la zone reste « déclarée » par le client comme aujourd'hui ;
 * - origine et précision de la position d'une adresse enregistrée.
 * Aucune donnée existante n'est modifiée. Pas de PostGIS : peu de zones, test « point dans polygone » en PHP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_zones', function (Blueprint $table) {
            $table->jsonb('area')->nullable();
        });
        Schema::table('addresses', function (Blueprint $table) {
            $table->string('location_source', 20)->nullable();
            $table->unsignedInteger('location_accuracy_m')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('addresses', fn (Blueprint $table) => $table->dropColumn(['location_source', 'location_accuracy_m']));
        Schema::table('delivery_zones', fn (Blueprint $table) => $table->dropColumn('area'));
    }
};
