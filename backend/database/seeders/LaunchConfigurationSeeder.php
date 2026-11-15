<?php

namespace Database\Seeders;

use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\Settings;
use App\Models\DeliveryPartner;
use App\Models\DeliveryZone;
use App\Models\Product;
use App\Models\Promotion;
use Illuminate\Database\Seeder;

/**
 * Configuration de lancement VALIDÉE par la direction de MK Délices le 15/11/2026
 * (docs/propositions/02-ZONES-ET-LIVRAISON.md et 01-CATALOGUE-ET-PRIX.md).
 *
 * Idempotent et sans suppression : peut être rejoué sur chaque environnement
 * (développement, préproduction, production) : php artisan db:seed --class=LaunchConfigurationSeeder
 *
 * Ne configure AUCUN moyen de paiement (contrat marchand non signé) et ne publie
 * aucun texte juridique comme définitif (relecture par un juriste requise).
 */
class LaunchConfigurationSeeder extends Seeder
{
    public const ZONES = [
        ['slug' => 'zone-1-centre', 'name' => 'Zone 1 — Centre', 'districts' => 'Plateau, Médina, Fann, Point E, Gueule Tapée, Fass', 'delivery_fee_xof' => 1500, 'min_order_xof' => 5000, 'partner_fee_xof' => 1000, 'extra_lead_minutes' => 0],
        ['slug' => 'zone-2-dakar-intermediaire', 'name' => 'Zone 2 — Dakar intermédiaire', 'districts' => 'Mermoz, Sacré-Cœur, Liberté 1 à 6, Sicap, HLM, Grand Dakar, Amitié', 'delivery_fee_xof' => 2000, 'min_order_xof' => 5000, 'partner_fee_xof' => 1300, 'extra_lead_minutes' => 0],
        ['slug' => 'zone-3-corniche-ouest', 'name' => 'Zone 3 — Corniche Ouest', 'districts' => 'Ouakam, Ngor, Almadies, Mamelles, Yoff, Ouest-Foire', 'delivery_fee_xof' => 2500, 'min_order_xof' => 7500, 'partner_fee_xof' => 1700, 'extra_lead_minutes' => 30],
        ['slug' => 'zone-4-nord', 'name' => 'Zone 4 — Nord', 'districts' => 'Parcelles Assainies, Grand Yoff, Patte d\'Oie, Cambérène', 'delivery_fee_xof' => 3000, 'min_order_xof' => 10000, 'partner_fee_xof' => 2000, 'extra_lead_minutes' => 30],
    ];

    public function run(): void
    {
        $settings = app(Settings::class);
        $audit = app(AuditLogger::class);

        // 1. Zones pilotes (création ou mise à jour par slug).
        foreach (self::ZONES as $position => $zone) {
            DeliveryZone::query()->updateOrCreate(['slug' => $zone['slug']], $zone + [
                'active' => true,
                'position' => $position,
                'is_demo' => false,
                'description' => 'Livraison sur créneau de 2 h, produits transportés au frais.',
            ]);
        }

        // 2. Horaires, créneaux et règles opérationnelles validés.
        $open = [['10:00', '20:00']];
        $values = [
            'business.hours' => ['mon' => [], 'tue' => $open, 'wed' => $open, 'thu' => $open, 'fri' => $open, 'sat' => $open, 'sun' => $open],
            'ordering.slot_duration_minutes' => 120,
            'ordering.slot_capacity' => 5,
            'ordering.min_lead_minutes' => 240,
            'ordering.max_days_ahead' => 14,
            'ordering.max_qty_per_line' => 20,
            'ordering.pickup_enabled' => false,
            'quality.cold_chain_max_minutes' => 30,
            'delivery.proof_types' => ['recipient_confirmation', 'code'],
            'payments.require_payment_before_acceptance' => true,
            'delivery.failed_fee_percent' => 50,
            'delivery.client_fault_reasons' => ['client_absent', 'client_injoignable', 'refus_client'],
        ];
        foreach ($values as $key => $value) {
            $settings->set($key, $value);
        }
        // Paiement à la livraison désactivé (décision validée) ; les autres moyens restent inactifs tant
        // qu'aucun contrat marchand n'est signé et testé.
        $methods = (array) $settings->get('payments.methods', []);
        $methods['cash_on_delivery'] = false;
        $settings->set('payments.methods', $methods);

        // 3. Code de lancement, créé INACTIF : à activer (avec dates) le jour de l'ouverture.
        Promotion::query()->firstOrCreate(['code' => 'BIENVENUE10'], [
            'name' => 'Bienvenue chez MK Délices',
            'description' => '10 % sur votre première commande (remise plafonnée à 2 000 FCFA).',
            'discount_type' => 'percent',
            'discount_value' => 10,
            'max_discount_xof' => 2000,
            'active' => false,
            'is_public' => true,
            'is_demo' => false,
        ]);

        // 4. Données de démonstration retirées de la vente (réversible : désactivation / archivage, aucune suppression).
        $demoZones = DeliveryZone::query()->where('is_demo', true)->where('active', true)->update(['active' => false]);
        $demoProducts = Product::query()->where('is_demo', true)->get();
        foreach ($demoProducts as $product) {
            $product->update(['status' => 'archived']);
            $product->delete();
        }
        // Livreurs de démonstration rattachés aux nouvelles zones pour que les tests locaux restent possibles.
        $zoneIds = DeliveryZone::query()->whereIn('slug', array_column(self::ZONES, 'slug'))->pluck('id')->all();
        DeliveryPartner::query()->where('is_demo', true)->get()->each(fn (DeliveryPartner $p) => $p->zones()->syncWithoutDetaching($zoneIds));

        $audit->log('launch.configuration_applied', null, null, [
            'zones' => array_column(self::ZONES, 'slug'),
            'demo_zones_deactivated' => $demoZones,
            'demo_products_archived' => $demoProducts->count(),
        ]);
    }
}
