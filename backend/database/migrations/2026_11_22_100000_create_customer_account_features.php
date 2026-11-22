<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fonctions du compte pour l'application mobile et le site (22/11/2026) :
 * - appareils mobiles enregistrés pour les notifications push (FCM, Android et iOS) ;
 * - produits favoris ;
 * - suppression de compte (exigence Google Play / App Store) : le compte est anonymisé, pas effacé,
 *   car les commandes et factures doivent être conservées (obligations comptables).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            // Jeton d'appareil FCM : le même téléphone renvoie le même jeton, réattribué au dernier compte connecté.
            $table->text('token');
            $table->char('token_hash', 64)->unique();
            $table->string('platform', 10);
            $table->string('app_version', 30)->nullable();
            // Jeton de connexion de l'appareil : sa révocation (déconnexion, mot de passe changé) supprime l'appareil.
            $table->foreignId('access_token_id')->nullable()->constrained('personal_access_tokens')->cascadeOnDelete();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamps();
            $table->index('user_id');
        });

        Schema::create('favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'product_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestampTz('anonymized_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('anonymized_at'));
        Schema::dropIfExists('favorites');
        Schema::dropIfExists('mobile_devices');
    }
};
