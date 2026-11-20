<?php

namespace Database\Seeders;

use App\Domain\Cart\CartService;
use App\Domain\Deliveries\DeliveryService;
use App\Domain\Geo\GeoPoint;
use App\Domain\Geo\ZoneArea;
use App\Domain\Geo\ZoneLocator;
use App\Domain\Orders\CheckoutService;
use App\Domain\Orders\OrderStateMachine;
use App\Domain\Orders\SlotService;
use App\Domain\Payments\Gateways\GatewayStatus;
use App\Domain\Payments\PaymentService;
use App\Domain\Payments\RefundService;
use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\DomainException;
use App\Models\Address;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\DeliveryAssignment;
use App\Models\DeliveryPartner;
use App\Models\DeliveryZone;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\Review;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * ACTIVITÉ DE DÉMONSTRATION — environnements local/testing/staging (préproduction) uniquement.
 *
 * Génère, via les VRAIS services métier, 30 jours d'historique et des commandes du jour dans tous
 * les états : clients (invités et comptes), commandes, paiements (prestataire simulé), préparation,
 * missions de livraison, incidents, remboursements, avis, règlements livreurs, brouillons WhatsApp.
 * Toutes les données sont fictives : clients is_demo, numéros 70 000 00 xx, emails en .invalid.
 * Reproductible (graine fixe). Exécutée une fois par génération : `php artisan mk:demo-refresh` archive la
 * génération précédente (commandes passées en « test », jamais supprimées) puis en produit une nouvelle, datée du jour.
 *
 * Géolocalisation : quartiers réels des zones avec des positions APPROXIMATIVES (centres de quartier ± 300 m),
 * toujours dans le périmètre desservi ; mélange GPS / carte / adresse enregistrée / saisie sans position.
 *
 * Prérequis : rôles, catalogue publié, zones (LaunchConfigurationSeeder) et comptes de démonstration (DemoDataSeeder).
 */
class DemoActivitySeeder extends Seeder
{
    public const MARKER = 'Donnée de démonstration générée par DemoActivitySeeder';

    public const CLIENT_PASSWORD = 'Demo-MKD-2026';

    private const FIRST_NAMES = ['Awa', 'Moussa', 'Fatou', 'Ibrahima', 'Aminata', 'Cheikh', 'Khady', 'Ousmane', 'Mariama', 'Babacar', 'Ndeye', 'Aliou', 'Coumba', 'Mamadou', 'Rokhaya', 'Lamine'];

    private const LAST_NAMES = ['Diop', 'Ndiaye', 'Sow', 'Fall', 'Ba', 'Sy', 'Diallo', 'Gueye', 'Cissé', 'Mbaye', 'Faye', 'Sarr', 'Kane', 'Thiam', 'Seck', 'Niang'];

    /**
     * Quartiers de Dakar et position approximative de leur centre (lat, lng) — données fictives de démonstration,
     * rattachées aux zones dont la liste de quartiers (districts) contient le nom.
     */
    private const DISTRICTS = [
        ['Plateau', 14.6680, -17.4370], ['Médina', 14.6830, -17.4500], ['Fann', 14.6930, -17.4630], ['Point E', 14.6990, -17.4580],
        ['Gueule Tapée', 14.6870, -17.4590], ['Fass', 14.6850, -17.4470],
        ['Mermoz', 14.7106, -17.4747], ['Sacré-Cœur', 14.7210, -17.4680], ['Liberté', 14.7250, -17.4610], ['Sicap', 14.7170, -17.4590],
        ['HLM', 14.7100, -17.4450], ['Grand Dakar', 14.7020, -17.4500], ['Amitié', 14.7060, -17.4600],
        ['Ouakam', 14.7240, -17.4920], ['Ngor', 14.7470, -17.5130], ['Almadies', 14.7440, -17.5230], ['Mamelles', 14.7290, -17.5030],
        ['Yoff', 14.7560, -17.4700], ['Ouest-Foire', 14.7440, -17.4780],
        ['Parcelles Assainies', 14.7650, -17.4400], ['Grand Yoff', 14.7350, -17.4550], ["Patte d'Oie", 14.7480, -17.4500], ['Cambérène', 14.7640, -17.4330],
    ];

    private const STREETS = ['Rue 10', 'Avenue Cheikh Anta Diop', 'Rue Carnot', 'Villa 112', 'Cité Keur Gorgui, villa 24', 'Rue MZ 45', null, null];

    /** @var array<string, list<array{0: string, 1: float, 2: float}>> quartiers par zone (id) */
    private array $districtsByZone = [];

    private ?array $serviceArea = null;

    private const LANDMARKS = ['Près de la pharmacie', 'Portail bleu', 'Derrière la mosquée', 'Immeuble beige, 2e étage', 'À côté de la boulangerie', 'Face à l\'école'];

    private User $admin;

    private User $commercial;

    private User $preparer;

    private User $manager;

    /** @var list<DeliveryPartner> */
    private array $partners;

    /** @var list<ProductVariant> */
    private array $variants;

    /** @var list<DeliveryZone> */
    private array $zones;

    private int $phoneSeq = 0;

    /** Scénarios imposés aux premières commandes : chaque cas métier apparaît au moins une fois. */
    private array $forcedRolls = [4, 7, 12, 14, 7, 12];

    /** Date réelle capturée AVANT tout voyage dans le temps (sinon les dates dériveraient). */
    private CarbonImmutable $today;

    public function run(): void
    {
        if (! app()->environment(['local', 'testing', 'staging'])) {
            throw new RuntimeException('Les données de démonstration sont interdites hors environnements local/testing/staging.');
        }
        // Rejouable sans doublon : on ne s'arrête que si une génération est allée À SON TERME (journal d'audit)
        // et n'a pas été archivée depuis (mk:demo-refresh).
        $generated = AuditLog::query()->where('action', 'demo.activity_generated')->latest('created_at')->value('created_at');
        $archived = AuditLog::query()->where('action', 'demo.activity_archived')->latest('created_at')->value('created_at');
        if ($generated && (! $archived || $generated > $archived)) {
            $this->command?->warn('Activité de démonstration déjà présente : rien à faire (mk:demo-refresh pour la renouveler).');

            return;
        }
        $this->prepare();
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        $this->today = CarbonImmutable::now('Africa/Dakar')->startOfDay();
        mt_srand(20261115);

        try {
            $clients = $this->clientAccounts();

            // 1. Historique : 30 derniers jours (hors lundis, jour de fermeture).
            for ($daysAgo = 30; $daysAgo >= 1; $daysAgo--) {
                $day = $this->today->subDays($daysAgo);
                if ($day->isMonday()) {
                    continue;
                }
                $count = mt_rand(1, 3) + ($day->isWeekend() ? 1 : 0);
                for ($i = 0; $i < $count; $i++) {
                    try {
                        $this->historicalOrder($day->setTime(mt_rand(8, 16), [0, 15, 30, 45][mt_rand(0, 3)]), $clients);
                    } catch (DomainException $e) {
                        // Refus métier (créneau complet, quantité…) : on passe à la commande suivante.
                        $this->command?->warn("Commande de démonstration ignorée : {$e->getMessage()}");
                    }
                }
            }

            // 2. Aujourd'hui : une commande dans chaque état du flux (tableaux de bord opérationnels).
            $this->currentOrders($clients);
        } finally {
            Carbon::setTestNow();
            CarbonImmutable::setTestNow();
        }

        app(AuditLogger::class)->log('demo.activity_generated', null, null, ['orders' => Order::query()->where('internal_notes', self::MARKER)->count()]);
        $this->command?->info('Activité de démonstration générée : '.Order::query()->where('internal_notes', self::MARKER)->count().' commandes.');
    }

    private function prepare(): void
    {
        $role = fn (string $r) => User::role($r)->where('email', 'like', '%@mk-delices.test')->first()
            ?? throw new RuntimeException("Compte de démonstration « {$r} » introuvable : lancer DemoDataSeeder.");
        $this->admin = $role(User::ROLE_ADMIN);
        $this->commercial = $role(User::ROLE_COMMERCIAL);
        $this->preparer = $role(User::ROLE_PREPARATEUR);
        $this->manager = $role(User::ROLE_RESP_LIVRAISON);
        $this->zones = DeliveryZone::query()->where('active', true)->orderBy('position')->get()->all();
        $this->variants = ProductVariant::query()->with('product.category')->whereHas('product', fn ($q) => $q->publiclyVisible())->get()
            ->filter(fn (ProductVariant $v) => $v->isSellable())->values()->all();
        $this->partners = DeliveryPartner::query()->with('user')->where('active', true)->get()->all();
        if (! $this->zones || ! $this->variants || ! $this->partners) {
            throw new RuntimeException('Zones actives, catalogue publié et livreurs actifs requis (LaunchConfigurationSeeder + import du catalogue).');
        }
        // Quartiers de chaque zone, limités au périmètre desservi s'il est défini (ex. ville de Dakar).
        $this->serviceArea = app(ZoneLocator::class)->serviceArea();
        foreach ($this->zones as $zone) {
            $text = mb_strtolower((string) $zone->districts);
            $this->districtsByZone[$zone->id] = array_values(array_filter(self::DISTRICTS, fn ($d) => str_contains($text, mb_strtolower($d[0]))
                && $this->inServiceArea($d[1], $d[2])));
        }
        // Chaque livreur de démonstration couvre toutes les zones actives.
        foreach ($this->partners as $partner) {
            $partner->zones()->syncWithoutDetaching(array_map(fn ($z) => $z->id, $this->zones));
        }
    }

    /** @return list<User> */
    private function clientAccounts(): array
    {
        $clients = [];
        foreach ([1, 2, 3] as $n) {
            $first = self::FIRST_NAMES[$n - 1];
            $last = self::LAST_NAMES[$n - 1];
            $user = User::query()->firstOrCreate(['email' => "client{$n}@mk-delices.test"], [
                'name' => "{$first} {$last}", 'phone' => $this->phone(), 'password' => self::CLIENT_PASSWORD, 'is_active' => true,
            ]);
            $user->syncRoles([User::ROLE_CLIENT]);
            $customer = Customer::query()->firstOrCreate(['user_id' => $user->id], [
                'first_name' => $first, 'last_name' => $last, 'phone' => $user->phone, 'email' => $user->email,
                'marketing_consent_at' => $n === 1 ? now() : null, 'is_demo' => true,
                'preferences' => ['favorite_flavors' => ['Classique', 'Spéculoos']],
            ]);
            $this->clientAddresses($customer, $n);
            $clients[] = $user->fresh('customer');
        }

        return $clients;
    }

    /**
     * Carnet d'adresses des comptes de démonstration (mis à jour à chaque génération, par libellé) :
     * client 1 = Maison (GPS, par défaut) + Bureau (sans position) ; client 2 = Maison (carte) ; client 3 = Maison sans position.
     */
    private function clientAddresses(Customer $customer, int $n): void
    {
        $zone = $this->zones[$n % count($this->zones)];
        $spot = $this->spot($zone, $n);
        $plans = [['Maison', true, $n === 3 ? null : ($n === 1 ? 'gps' : 'map'), $spot]];
        if ($n === 1) {
            $office = $this->zones[0];
            $plans[] = ['Bureau', false, null, $this->spot($office, 0)];
        }
        // Anciennes adresses de démonstration remplacées (données fictives des comptes .test uniquement).
        $customer->addresses()->where('district', 'Quartier de démonstration')->delete();
        foreach ($plans as [$label, $default, $source, $place]) {
            $zoneId = $label === 'Bureau' ? $this->zones[0]->id : $zone->id;
            Address::query()->updateOrCreate(['customer_id' => $customer->id, 'label' => $label], [
                'zone_id' => $zoneId, 'district' => $place['district'], 'street' => self::STREETS[$n % count(self::STREETS)],
                'landmark' => self::LANDMARKS[$n], 'is_default' => $default,
                'lat' => $source && $place['lat'] !== null ? $place['lat'] : null, 'lng' => $source && $place['lng'] !== null ? $place['lng'] : null,
                'location_source' => $source && $place['lat'] !== null ? $source : null,
                'location_accuracy_m' => $source === 'gps' && $place['lat'] !== null ? 12 : null,
            ]);
        }
    }

    /**
     * Quartier réel de la zone et position approximative (centre ± ~300 m), dans le périmètre desservi.
     *
     * @return array{district: string, lat: ?float, lng: ?float}
     */
    private function spot(DeliveryZone $zone, ?int $index = null): array
    {
        $candidates = $this->districtsByZone[$zone->id] ?? [];
        if ($candidates === []) {
            return ['district' => 'Quartier de démonstration', 'lat' => null, 'lng' => null];
        }
        [$name, $lat, $lng] = $candidates[$index !== null ? $index % count($candidates) : mt_rand(0, count($candidates) - 1)];
        $lat = round($lat + mt_rand(-27, 27) / 10000, 6);
        $lng = round($lng + mt_rand(-27, 27) / 10000, 6);
        if (! $this->inServiceArea($lat, $lng)) {
            [, $lat, $lng] = $candidates[$index !== null ? $index % count($candidates) : 0];
        }

        return ['district' => $name, 'lat' => $lat, 'lng' => $lng];
    }

    private function inServiceArea(float $lat, float $lng): bool
    {
        return $this->serviceArea === null || ZoneArea::contains($this->serviceArea, new GeoPoint($lat, $lng));
    }

    /** Commande passée puis menée jusqu'à son état final, avec horodatages cohérents. */
    private function historicalOrder(CarbonImmutable $at, array $clients): void
    {
        $this->travel($at);
        $roll = mt_rand(1, 100);
        if ($this->forcedRolls !== []) {
            $roll = array_shift($this->forcedRolls); // annulation client, refus + remboursement, échec puis relivraison, échec puis annulation
        }
        $client = mt_rand(1, 5) === 1 ? $clients[array_rand($clients)] : null;
        $order = $this->place($client, mt_rand(1, 10) <= 2 ? 'DEMO10' : null);
        if (! $order) {
            return;
        }

        if ($roll <= 4) { // annulée par le client avant confirmation
            $this->travel($at->addMinutes(20));
            app(OrderStateMachine::class)->cancelByCustomer($order, 'Changement de programme (démo)');

            return;
        }
        $this->pay($order, $at->addMinutes(3));
        if ($roll <= 7) { // refusée après paiement → remboursement approuvé par un 2e utilisateur
            $this->travel($at->addMinutes(25));
            app(OrderStateMachine::class)->apply($order, 'reject', $this->commercial, 'Rupture de mascarpone (démo)');
            $this->travel($at->addMinutes(60));
            app(RefundService::class)->approve($order->refunds()->firstOrFail(), $this->admin);

            return;
        }
        $this->prepareOrder($order, $at);
        $slot = CarbonImmutable::parse($order->fresh()->slot_start_at);
        if ($roll <= 12) { // échec de livraison puis relivraison réussie
            $this->deliver($order, $slot, fail: 'client_absent');
            $this->deliver($order, $slot->addHours(3));
        } elseif ($roll <= 14) { // échec puis annulation (produit abîmé)
            $this->deliver($order, $slot, fail: 'produit_endommage');
            $this->travel($slot->addHours(2));
            app(OrderStateMachine::class)->apply($order, 'cancel', $this->commercial, 'Produit abîmé pendant le transport (démo)');
        } else {
            $this->deliver($order, $slot);
            if (mt_rand(1, 3) === 1) {
                $this->review($order, $slot->addHours(5));
            }
        }
    }

    /** Commandes du jour réparties sur toutes les étapes. */
    private function currentOrders(array $clients): void
    {
        $now = $this->today;
        // Jour ouvré pour les commandes du jour (le lundi est fermé).
        $base = $now->isMonday() ? $now->addDay()->setTime(8, 0) : $now->setTime(8, 0);
        $steps = ['pending_unpaid', 'pending_paid', 'accepted', 'accepted', 'preparing', 'preparing', 'ready', 'ready', 'assigned', 'picked_up', 'delivery_failed'];
        foreach ($steps as $i => $step) {
            $at = $base->addMinutes($i * 7);
            $this->travel($at);
            $order = $this->place($i === 1 ? $clients[0] : null);
            if (! $order) {
                continue;
            }
            if ($step === 'pending_unpaid') {
                $snapshot = $order->address_snapshot ?? [];
                $this->travel($at->addMinutes(5));
                app(CheckoutService::class)->updateDetails($order, ['delivery' => [
                    'zone_id' => $order->zone_id, 'district' => $snapshot['district'] ?? 'Quartier de démonstration',
                    'street' => $snapshot['street'] ?? null, 'landmark' => 'Repère précisé au téléphone par le client (démo)',
                ]], $this->commercial);

                continue;
            }
            $this->pay($order, $at->addMinutes(2));
            if ($step === 'pending_paid') {
                continue;
            }
            $this->travel($at->addMinutes(10));
            app(OrderStateMachine::class)->apply($order, 'accept', $this->commercial);
            if ($step === 'accepted') {
                continue;
            }
            $this->travel($at->addMinutes(20));
            app(OrderStateMachine::class)->apply($order, 'start_preparation', $this->preparer);
            if ($step === 'preparing') {
                continue;
            }
            $this->travel($at->addMinutes(80));
            app(OrderStateMachine::class)->apply($order, 'mark_ready', $this->preparer, null, null, ['packaging_checked' => true]);
            if ($step === 'ready') {
                continue;
            }
            $partner = $this->partners[$i % count($this->partners)];
            $this->travel($at->addMinutes(90));
            $mission = app(DeliveryService::class)->assign($order, $partner, $this->manager);
            $courier = $partner->user;
            app(DeliveryService::class)->courierAction($mission, $courier, 'accept');
            if ($step === 'assigned') {
                continue;
            }
            $this->travel($at->addMinutes(100));
            app(DeliveryService::class)->courierAction($mission, $courier, 'pick_up', ['packaging_checked' => true]);
            if ($step === 'picked_up') {
                continue;
            }
            $this->travel($at->addMinutes(140));
            app(DeliveryService::class)->courierAction($mission, $courier, 'fail', [
                'incident_reason' => 'client_injoignable', 'incident_note' => 'Trois appels sans réponse (démo)', 'product_condition' => 'intact', 'contact_attempted' => true,
            ]);
        }

        // Deux brouillons WhatsApp en attente de confirmation commerciale.
        foreach ([0, 1] as $n) {
            $this->travel($base->addMinutes(100 + $n * 5));
            $cart = app(CartService::class)->create();
            $variant = $this->variants[($n * 3) % count($this->variants)];
            app(CartService::class)->setItem($cart['cart'], $variant->id, 2);
            $draft = app(CheckoutService::class)->saveWhatsAppDraft($cart['cart'], ['zone_id' => $this->zones[0]->id, 'first_name' => self::FIRST_NAMES[10 + $n]], null)['order'];
            $draft->forceFill(['internal_notes' => self::MARKER])->save();
        }

        // Règlement des livreurs : les missions des 30 jours sauf la dernière semaine sont réglées.
        $this->travel($base->addMinutes(150));
        $old = DeliveryAssignment::query()->whereIn('status', ['delivered', 'failed'])->where('partner_fee_xof', '>', 0)
            ->whereNull('settled_at')->where('closed_at', '<', $base->subDays(7))->pluck('id')->all();
        if ($old) {
            app(DeliveryService::class)->settle($old, $this->manager);
        }
    }

    private function place(?User $client, ?string $promo = null): ?Order
    {
        $cart = app(CartService::class)->create($client?->customer?->id);
        $lines = mt_rand(1, 3);
        $zone = $this->zones[mt_rand(1, 10) <= 7 ? mt_rand(0, min(1, count($this->zones) - 1)) : mt_rand(0, count($this->zones) - 1)];
        // Adresse choisie d'abord : la zone d'une adresse enregistrée fixe le minimum de commande à atteindre.
        $delivery = $this->deliveryFor($client, $zone);
        $subtotal = 0;
        for ($l = 0; $l < $lines; $l++) {
            $variant = $this->variants[mt_rand(0, count($this->variants) - 1)];
            if (! str_contains(mb_strtolower($variant->label), 'coupe') && mt_rand(1, 3) > 1) {
                continue; // majorité de coupes individuelles
            }
            $qty = mt_rand(1, 4);
            // Respecte le maximum par commande du format (déjà dans le panier compris).
            $inCart = (int) $cart['cart']->items()->where('variant_id', $variant->id)->value('quantity');
            $qty = min($qty, ($variant->max_per_order ?? 20) - $inCart);
            if ($qty < 1) {
                continue;
            }
            app(CartService::class)->setItem($cart['cart'], $variant->id, $qty, increment: true);
            $subtotal += $variant->price_xof * $qty;
        }
        // Minimum de commande de la zone atteint en ajoutant des coupes Classique.
        $classic = collect($this->variants)->first(fn ($v) => $v->sku === 'TIR-CLA-IND') ?? $this->variants[0];
        while ($subtotal < ($zone->min_order_xof ?? 0)) {
            app(CartService::class)->setItem($cart['cart'], $classic->id, 1, increment: true);
            $subtotal += $classic->price_xof;
        }
        if ($promo) {
            app(CartService::class)->setPromotionCode($cart['cart'], $promo);
        }

        $lead = max(240, (int) $cart['cart']->items()->with('variant')->get()->max(fn ($i) => $i->variant->prep_minutes));
        $slots = app(SlotService::class)->available($lead, 6);
        if ($slots === []) {
            return null;
        }
        $first = $client ? $client->customer->first_name : self::FIRST_NAMES[mt_rand(0, count(self::FIRST_NAMES) - 1)];
        $last = $client ? $client->customer->last_name : self::LAST_NAMES[mt_rand(0, count(self::LAST_NAMES) - 1)];
        try {
            $placed = app(CheckoutService::class)->placeOrder($cart['cart'], [
                'customer' => ['first_name' => $first, 'last_name' => $last, 'phone' => $client?->customer?->phone ?? $this->phone(),
                    'email' => $client?->email],
                'fulfillment_type' => 'delivery',
                'delivery' => $delivery,
                'slot_start_at' => $slots[mt_rand(0, count($slots) - 1)]['start_at'],
                'payment_method' => 'simulator',
                'notes' => mt_rand(1, 6) === 1 ? 'Écrire « Joyeux anniversaire » si possible (démo)' : null,
                'accepted_terms' => true,
                'promotion_code' => $promo,
            ], $client);
        } catch (DomainException) {
            return null; // créneau complet ou promotion épuisée : on passe à la suivante
        }
        $order = $placed['order'];
        $order->forceFill(['internal_notes' => self::MARKER])->save();
        $order->customer?->forceFill(['is_demo' => true])->save();

        return $order;
    }

    /**
     * Adresse de livraison : 45 % GPS (dont quelques positions imprécises), 20 % repère sur la carte,
     * 35 % saisie manuelle sans position ; un client avec compte utilise son adresse par défaut.
     */
    private function deliveryFor(?User $client, DeliveryZone &$zone): array
    {
        $landmark = self::LANDMARKS[mt_rand(0, count(self::LANDMARKS) - 1)];
        $saved = $client?->customer?->addresses()->where('is_default', true)->first();
        if ($saved) {
            $zone = collect($this->zones)->firstWhere('id', $saved->zone_id) ?? $zone;

            return ['zone_id' => $saved->zone_id, 'district' => $saved->district, 'street' => $saved->street, 'landmark' => $saved->landmark ?? $landmark]
                + ($saved->lat !== null ? ['lat' => $saved->lat, 'lng' => $saved->lng, 'location_source' => 'saved', 'accuracy_m' => $saved->location_accuracy_m] : []);
        }
        $place = $this->spot($zone);
        $base = ['zone_id' => $zone->id, 'district' => $place['district'], 'street' => self::STREETS[mt_rand(0, count(self::STREETS) - 1)], 'landmark' => $landmark];
        $roll = mt_rand(1, 100);
        if ($place['lat'] === null || $roll > 65) {
            return $base;
        }
        $gps = $roll <= 45;

        return $base + [
            'lat' => $place['lat'], 'lng' => $place['lng'], 'location_source' => $gps ? 'gps' : 'map',
            'accuracy_m' => $gps ? (mt_rand(1, 8) === 1 ? mt_rand(80, 160) : mt_rand(6, 40)) : null,
        ];
    }

    private function pay(Order $order, CarbonImmutable $at): void
    {
        $this->travel($at);
        $payment = app(PaymentService::class)->initiate($order->fresh(), 'simulator', 'demo-'.Str::uuid());
        app(PaymentService::class)->applyVerifiedStatus($payment, new GatewayStatus('succeeded', $payment->amount_xof, 'XOF', 'demo'), 'demo');
    }

    private function prepareOrder(Order $order, CarbonImmutable $at): void
    {
        $machine = app(OrderStateMachine::class);
        $this->travel($at->addMinutes(15));
        $machine->apply($order, 'accept', $this->commercial);
        $slot = CarbonImmutable::parse($order->fresh()->slot_start_at);
        $this->travel($slot->subHours(3));
        $machine->apply($order, 'start_preparation', $this->preparer);
        $this->travel($slot->subMinutes(70));
        $machine->apply($order, 'mark_ready', $this->preparer, null, null, ['packaging_checked' => true]);
    }

    private function deliver(Order $order, CarbonImmutable $slot, ?string $fail = null): void
    {
        $deliveries = app(DeliveryService::class);
        $partner = $this->partners[mt_rand(0, count($this->partners) - 1)];
        $this->travel($slot->subMinutes(55));
        $mission = $deliveries->assign($order->fresh(), $partner, $this->manager);
        $this->travel($slot->subMinutes(50));
        $deliveries->courierAction($mission, $partner->user, 'accept');
        $this->travel($slot->subMinutes(20));
        $deliveries->courierAction($mission, $partner->user, 'pick_up', ['packaging_checked' => true]);
        // Trajet réaliste (20 à 30 min hors froid) ; environ une livraison sur douze dépasse le seuil de la chaîne
        // du froid (30 min) pour illustrer l'alerte et la trace dans l'historique.
        $this->travel($slot->addMinutes(mt_rand(1, 12) === 1 ? mt_rand(15, 25) : mt_rand(0, 9)));
        if ($fail) {
            $deliveries->courierAction($mission, $partner->user, 'fail', [
                'incident_reason' => $fail, 'incident_note' => 'Incident de démonstration', 'contact_attempted' => true,
                'product_condition' => $fail === 'produit_endommage' ? 'abime' : 'intact',
            ]);

            return;
        }
        // Remise avec le code donné par le client (cas nominal) : pas d'alerte « sans code » dans la démonstration.
        $code = (string) $mission->order()->value('delivery_code');
        $deliveries->courierAction($mission, $partner->user, 'deliver', $code !== ''
            ? ['proof_type' => 'code', 'proof_reference' => $code]
            : ['proof_type' => 'recipient_confirmation', 'proof_reference' => 'Remis en main propre (démo)']);
    }

    private function review(Order $order, CarbonImmutable $at): void
    {
        $this->travel($at);
        $item = $order->items()->first();
        $comments = ['Délicieux, très frais !', 'Livré à l\'heure, emballage soigné.', 'Le meilleur tiramisu de Dakar.', 'Très bon, un peu trop sucré à mon goût.', 'Parfait pour l\'anniversaire.'];
        Review::query()->firstOrCreate(['order_id' => $order->id, 'product_id' => $item->product_id], [
            'customer_id' => $order->customer_id,
            'author_name' => ($order->customer_snapshot['first_name'] ?? 'Client').' (démo)',
            'stars' => [5, 5, 4, 5, 3][mt_rand(0, 4)],
            'comment' => $comments[mt_rand(0, count($comments) - 1)],
            'moderation_status' => mt_rand(1, 4) === 1 ? 'pending' : 'approved',
            'moderated_by' => $this->commercial->id,
            'moderated_at' => now(),
        ]);
    }

    private function travel(CarbonImmutable $at): void
    {
        Carbon::setTestNow($at->utc());
        CarbonImmutable::setTestNow($at->utc());
    }

    /** Numéros fictifs de la tranche 70 000 00 xx. */
    private function phone(): string
    {
        return sprintf('+22170000%04d', ++$this->phoneSeq);
    }
}
