<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notifications du navigateur (web push) aux livreurs : un abonnement par appareil (décision MK Délices du
 * 17/11/2026). Les clés p256dh/auth sont publiques côté navigateur ; elles servent à chiffrer le message.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->text('endpoint')->unique();
            $table->string('p256dh', 200);
            $table->string('auth', 100);
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamps();
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
