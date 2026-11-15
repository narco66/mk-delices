<?php

use App\Domain\Payments\PaymentService;
use App\Domain\Payments\PaymentStatus;
use App\Models\Cart;
use App\Models\IdempotencyKey;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/** Création sécurisée d'un administrateur (aucun mot de passe connu par défaut en production). */
Artisan::command('mk:create-admin', function () {
    $email = text('Email de l\'administrateur', required: true, validate: fn ($v) => filter_var($v, FILTER_VALIDATE_EMAIL) ? null : 'Email invalide');
    $name = text('Nom', required: true);
    $pwd = password('Mot de passe (12 caractères min., majuscule, minuscule, chiffre)', required: true);
    $validator = Validator::make(['password' => $pwd, 'email' => $email], [
        'password' => [Password::min(12)->letters()->mixedCase()->numbers()],
        'email' => ['unique:users,email'],
    ]);
    if ($validator->fails()) {
        $this->error(implode(' ', $validator->errors()->all()));

        return 1;
    }
    $user = User::query()->create(['email' => mb_strtolower($email), 'name' => $name, 'password' => $pwd, 'is_active' => true]);
    $user->assignRole(User::ROLE_ADMIN);
    $this->info("Administrateur {$email} créé.");

    return 0;
})->purpose('Créer un compte administrateur MK Délices');

/** Rapprochement planifié des paiements en attente (TRD §6). */
Artisan::command('mk:reconcile-payments', function (PaymentService $payments) {
    $pending = Payment::query()->whereIn('status', [PaymentStatus::Initiated->value, PaymentStatus::Pending->value])
        ->where('created_at', '<', now()->subMinutes(2))->limit(200)->get();
    foreach ($pending as $payment) {
        $payments->reconcile($payment);
    }
    $this->info("{$pending->count()} paiement(s) vérifié(s).");
})->purpose('Rapprocher les paiements en attente auprès des prestataires');

/** Purge des données techniques expirées (paniers non convertis, clés d'idempotence). */
Artisan::command('mk:purge-expired', function () {
    $carts = Cart::query()->whereNull('converted_order_id')->where('expires_at', '<', now()->subDays(7))->delete();
    $keys = IdempotencyKey::query()->where('expires_at', '<', now())->delete();
    $this->info("Paniers supprimés : {$carts} ; clés d'idempotence : {$keys}.");
})->purpose('Purger paniers expirés et clés d\'idempotence');

Schedule::command('mk:reconcile-payments')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('mk:purge-expired')->dailyAt('03:30')->timezone('Africa/Dakar');

/** Marque comme commandes de test celles créées par les tests E2E (prénom « E2E »), hors production uniquement. */
Artisan::command('mk:flag-test-orders {--first-name=E2E}', function () {
    if (app()->isProduction()) {
        $this->error('Commande interdite en production.');

        return 1;
    }
    $count = Order::query()->where('is_test', false)
        ->where(fn ($q) => $q->whereRaw("customer_snapshot->>'first_name' = ?", [$this->option('first-name')])
            ->orWhere(fn ($d) => $d->where('channel', 'whatsapp')->where('status', 'draft')->whereNull('customer_id')))
        ->update(['is_test' => true]);
    $this->info("{$count} commande(s) marquée(s) comme test.");

    return 0;
})->purpose('Marquer les commandes créées par les tests E2E (environnements hors production)');
