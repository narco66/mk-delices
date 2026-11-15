<?php

use App\Domain\Catalog\CatalogImporter;
use App\Domain\Payments\PaymentService;
use App\Domain\Payments\PaymentStatus;
use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\DomainException;
use App\Models\Cart;
use App\Models\ContentPage;
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

/** Import du catalogue depuis le modèle CSV (brouillons par défaut, aucune suppression). */
Artisan::command('mk:import-catalogue {file} {--publish : publier immédiatement les produits importés} {--dry-run : vérifier sans rien enregistrer}', function (CatalogImporter $importer) {
    try {
        $report = $importer->import($this->argument('file'), (bool) $this->option('publish'), (bool) $this->option('dry-run'));
    } catch (DomainException $e) {
        $this->error($e->getMessage());

        return 1;
    }
    $this->table(['Produits créés', 'Produits mis à jour', 'Formats créés', 'Formats mis à jour'], [[
        $report['products_created'], $report['products_updated'], $report['variants_created'], $report['variants_updated'],
    ]]);
    $this->info(match (true) {
        $report['dry_run'] => 'Simulation : rien n\'a été enregistré.',
        (bool) $this->option('publish') => 'Import enregistré : les produits sont publiés et commandables.',
        default => 'Import enregistré. Les nouveaux produits sont en brouillon : vérifiez-les puis publiez-les dans le back-office.',
    });

    return 0;
})->purpose('Importer le catalogue depuis un fichier CSV');

/** Charge un texte (Markdown) dans une page de contenu ; --validated retire la mention « en attente de validation ». */
Artisan::command('mk:import-content {slug} {file} {--title= : titre de la page} {--validated : texte validé (juriste / direction)}', function () {
    $file = $this->argument('file');
    if (! is_readable($file)) {
        $this->error("Fichier introuvable : {$file}");

        return 1;
    }
    $body = trim(preg_replace('/^\xEF\xBB\xBF/', '', file_get_contents($file)));
    $page = ContentPage::query()->firstOrNew(['slug' => $this->argument('slug')]);
    $before = $page->exists ? ['requires_validation' => $page->requires_validation, 'length' => mb_strlen($page->body)] : null;
    $page->fill([
        'title' => $this->option('title') ?: ($page->title ?? $this->argument('slug')),
        'body' => $body,
        'status' => $page->status ?? 'draft',
        'requires_validation' => ! $this->option('validated'),
    ]);
    $page->save();
    app(AuditLogger::class)->log('content.imported', $page, $before, ['requires_validation' => $page->requires_validation, 'length' => mb_strlen($body)]);
    $this->info("Page « {$page->slug} » mise à jour (".($page->requires_validation ? 'toujours marquée à valider' : 'validée').", statut : {$page->status}).");

    return 0;
})->purpose('Charger un texte validé dans une page du site');
