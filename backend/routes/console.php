<?php

use App\Domain\Catalog\CatalogImporter;
use App\Domain\Deliveries\DeliveryService;
use App\Domain\Geo\ZoneArea;
use App\Domain\Geo\ZoneLocator;
use App\Domain\Invoicing\InvoiceService;
use App\Domain\Payments\PaymentService;
use App\Domain\Payments\PaymentStatus;
use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\DomainException;
use App\Domain\Shared\Settings;
use App\Http\Controllers\HealthController;
use App\Models\Cart;
use App\Models\ContentPage;
use App\Models\IdempotencyKey;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Minishlink\WebPush\VAPID;

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

/** Paire de clés VAPID des notifications du navigateur (à générer UNE fois par environnement). */
Artisan::command('mk:vapid-keys', function () {
    $keys = VAPID::createVapidKeys();
    $this->warn('À copier dans le .env du serveur (la clé privée ne doit jamais être versionnée).');
    $this->warn('Changer ces clés invalide tous les abonnements : les livreurs devront réactiver les notifications.');
    $this->line("WEB_PUSH_VAPID_PUBLIC_KEY={$keys['publicKey']}");
    $this->line("WEB_PUSH_VAPID_PRIVATE_KEY={$keys['privateKey']}");

    return 0;
})->purpose('Générer les clés VAPID des notifications du navigateur aux livreurs');

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

/** Minimisation des données : efface la position GPS des commandes terminées au-delà de la durée de conservation. */
Artisan::command('mk:purge-delivery-positions {--dry-run : compter sans rien effacer}', function () {
    $days = (int) config('mkdelices.maps.position_retention_days');
    if ($days <= 0) {
        $this->info('Purge des positions désactivée (MAPS_POSITION_RETENTION_DAYS=0).');

        return 0;
    }
    $query = Order::query()->whereIn('status', ['delivered', 'cancelled', 'rejected'])
        ->whereRaw("address_snapshot->>'lat' IS NOT NULL")
        ->where(fn ($q) => $q->where('slot_end_at', '<', now()->subDays($days))
            ->orWhere(fn ($n) => $n->whereNull('slot_end_at')->where('updated_at', '<', now()->subDays($days))));
    $count = (clone $query)->count();
    if ($this->option('dry-run')) {
        $this->info("{$count} commande(s) dont la position serait effacée (conservation : {$days} j).");

        return 0;
    }
    // Requête SQL directe : n'altère ni updated_at ni l'historique ; « position_purged_at » garde la trace (et les statistiques).
    $done = $query->update(['address_snapshot' => DB::raw("(address_snapshot - 'lat' - 'lng' - 'accuracy_m') || jsonb_build_object('position_purged_at', to_jsonb(now()))")]);
    if ($done > 0) {
        app(AuditLogger::class)->log('geo.positions_purged', null, null, ['count' => $done, 'retention_days' => $days]);
    }
    $this->info("Positions effacées : {$done} commande(s).");

    return 0;
})->purpose('Effacer les positions GPS des commandes terminées au-delà de la durée de conservation');

/** Charge le périmètre desservi depuis un fichier GeoJSON (Polygon, MultiPolygon, Feature ou FeatureCollection). */
Artisan::command('mk:set-service-area {file? : chemin du fichier GeoJSON} {--clear : supprimer le périmètre}', function (Settings $settings) {
    $before = app(ZoneLocator::class)->serviceArea();
    if ($this->option('clear')) {
        $settings->set(ZoneLocator::SERVICE_AREA_SETTING, null);
        app(AuditLogger::class)->log('delivery.service_area_updated', null, ['area' => $before ? 'défini' : null], ['area' => null]);
        $this->info('Périmètre desservi supprimé : retour au choix de zone par le client.');

        return 0;
    }
    $file = (string) $this->argument('file');
    if ($file === '' || ! is_file($file)) {
        $this->error("Fichier introuvable : {$file}");

        return 1;
    }
    $json = json_decode((string) file_get_contents($file), true);
    $geometry = match ($json['type'] ?? null) {
        'Feature' => $json['geometry'] ?? null,
        'FeatureCollection' => $json['features'][0]['geometry'] ?? null,
        default => $json,
    };
    $area = ZoneArea::normalize($geometry);
    if (is_string($area)) {
        $this->error($area);

        return 1;
    }
    $settings->set(ZoneLocator::SERVICE_AREA_SETTING, $area);
    app(AuditLogger::class)->log('delivery.service_area_updated', null, ['area' => $before ? 'défini' : null], ['area' => ['source' => basename($file), 'sha1' => sha1(json_encode($area))]]);
    $this->info("Périmètre desservi enregistré ({$area['type']}, depuis ".basename($file).').');

    return 0;
})->purpose('Définir le périmètre desservi (contour GeoJSON) à partir d\'un fichier');

// Verrou limité à 10 min (audit E10) : un processus tué ne bloque plus le rapprochement 24 h.
Schedule::command('mk:reconcile-payments')->everyFiveMinutes()->withoutOverlapping(10);
Schedule::command('queue:prune-failed', ['--hours' => 720])->dailyAt('03:45')->timezone('Africa/Dakar');
Schedule::command('mk:purge-expired')->dailyAt('03:30')->timezone('Africa/Dakar');
Schedule::command('mk:purge-delivery-positions')->dailyAt('03:35')->timezone('Africa/Dakar');
Schedule::command('sanctum:prune-expired', ['--hours' => 24])->dailyAt('03:40')->timezone('Africa/Dakar');
// Supervision (audit E9) : battement du planificateur lu par /health, alerte si la file de tâches déborde.
Schedule::call(fn () => Cache::put(HealthController::SCHEDULER_HEARTBEAT, now()->getTimestamp(), now()->addHour()))->everyMinute()->name('health-heartbeat');
Schedule::command('queue:monitor', ['database:default', '--max' => 50])->everyFiveMinutes();
// Audit espace livreur E1 : mission proposée restée sans réponse -> alerte au responsable des livraisons.
Schedule::command('mk:alert-unaccepted-missions')->everyTwoMinutes()->withoutOverlapping(5);

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

/** Rattrapage : émet les factures dues non émises (ex. mentions légales complétées après coup). Idempotent. */
Artisan::command('mk:issue-invoices {--dry-run : Afficher sans émettre}', function (InvoiceService $invoices) {
    $issued = 0;
    $due = 0;
    Order::query()->where('is_test', false)->whereColumn('paid_xof', '>=', 'total_xof')->where('total_xof', '>', 0)
        ->whereNotIn('status', ['draft', 'cancelled', 'rejected'])->whereDoesntHave('invoice')
        ->orderBy('created_at')->each(function (Order $order) use ($invoices, &$issued, &$due) {
            if (! $invoices->isDue($order)) {
                return;
            }
            $due++;
            if (! $this->option('dry-run') && $invoices->issueIfDue($order)) {
                $issued++;
            }
        });
    $this->info($this->option('dry-run') ? "{$due} facture(s) à émettre." : "{$issued} facture(s) émise(s) sur {$due} due(s).");
    if (! $this->option('dry-run') && $issued < $due) {
        $this->warn('Émission bloquée pour certaines commandes : voir le journal d\'audit (invoice.blocked).');
    }

    return 0;
})->purpose('Émettre les factures dues qui ne l\'ont pas encore été');

/** Signale au responsable des livraisons les missions proposées restées sans réponse (planifiée). */
Artisan::command('mk:alert-unaccepted-missions', function (DeliveryService $deliveries) {
    $this->info($deliveries->alertUnacceptedProposals().' alerte(s) envoyée(s).');
})->purpose('Alerter le responsable des missions non acceptées par les livreurs');
