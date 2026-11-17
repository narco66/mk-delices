<?php

namespace Database\Seeders;

use App\Domain\Cart\CartService;
use App\Domain\Deliveries\DeliveryService;
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
 * Reproductible (graine fixe) et exécutable une seule fois.
 *
 * Prérequis : rôles, catalogue publié, zones (LaunchConfigurationSeeder) et comptes de démonstration (DemoDataSeeder).
 */
class DemoActivitySeeder extends Seeder
{
    public const MARKER = 'Donnée de démonstration générée par DemoActivitySeeder';

    public const CLIENT_PASSWORD = 'Demo-MKD-2026';

    private const FIRST_NAMES = ['Awa', 'Moussa', 'Fatou', 'Ibrahima', 'Aminata', 'Cheikh', 'Khady', 'Ousmane', 'Mariama', 'Babacar', 'Ndeye', 'Aliou', 'Coumba', 'Mamadou', 'Rokhaya', 'Lamine'];

    private const LAST_NAMES = ['Diop', 'Ndiaye', 'Sow', 'Fall', 'Ba', 'Sy', 'Diallo', 'Gueye', 'Cissé', 'Mbaye', 'Faye', 'Sarr', 'Kane', 'Thiam', 'Seck', 'Niang'];

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
        // Rejouable sans doublon : on ne s'arrête que si une génération est allée À SON TERME (journal d'audit).
        if (AuditLog::query()->where('action', 'demo.activity_generated')->exists()) {
            $this->command?->warn('Activité de démonstration déjà présente : rien à faire.');

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
            if (! $customer->addresses()->exists()) {
                Address::query()->create([
                    'customer_id' => $customer->id, 'zone_id' => $this->zones[$n % count($this->zones)]->id, 'label' => 'Maison',
                    'district' => 'Quartier de démonstration', 'landmark' => self::LANDMARKS[$n], 'is_default' => true,
                ]);
            }
            $clients[] = $user->fresh('customer');
        }

        return $clients;
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
                'delivery' => ['zone_id' => $zone->id, 'district' => 'Quartier de démonstration', 'landmark' => self::LANDMARKS[mt_rand(0, count(self::LANDMARKS) - 1)]],
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
        $this->travel($slot->subMinutes(40));
        $deliveries->courierAction($mission, $partner->user, 'pick_up', ['packaging_checked' => true]);
        $this->travel($slot->addMinutes(mt_rand(5, 70)));
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
