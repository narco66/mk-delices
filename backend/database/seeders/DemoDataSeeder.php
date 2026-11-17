<?php

namespace Database\Seeders;

use App\Domain\Catalog\ProductStatus;
use App\Domain\Shared\Settings;
use App\Models\Category;
use App\Models\DeliveryPartner;
use App\Models\DeliveryZone;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * JEU DE DÉMONSTRATION FICTIF — environnements local/testing/staging (préproduction) uniquement.
 * Produits, prix, frais et comptes sont inventés et marqués is_demo=true : ils ne constituent
 * PAS le catalogue officiel de MK Délices (en attente, voir DEV-DECISIONS-A-VALIDER.md).
 */
class DemoDataSeeder extends Seeder
{
    public const DEMO_PASSWORD = 'Demo-MKD-2026';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing', 'staging'])) {
            throw new RuntimeException('Le jeu de démonstration est interdit hors environnements local/testing/staging.');
        }

        $this->users();
        $zones = $this->zones();
        $this->partners($zones);
        $this->catalog();
        Promotion::query()->firstOrCreate(['code' => 'DEMO10'], [
            'name' => 'Code de démonstration -10 %', 'description' => 'Code fictif de test.', 'discount_type' => 'percent',
            'discount_value' => 10, 'max_discount_xof' => 5000, 'active' => true, 'is_public' => true, 'is_demo' => true,
        ]);
        // Numéro WhatsApp FICTIF pour tester le parcours en local (aucun numéro réel n'est connu).
        $settings = app(Settings::class);
        if ($settings->get('business.whatsapp_number') === null) {
            $settings->set('business.whatsapp_number', '221700000000');
        }
    }

    private function users(): void
    {
        $accounts = [
            ['admin@mk-delices.test', 'Admin Démo', User::ROLE_ADMIN],
            ['commercial@mk-delices.test', 'Commercial Démo', User::ROLE_COMMERCIAL],
            ['preparateur@mk-delices.test', 'Préparateur Démo', User::ROLE_PREPARATEUR],
            ['livraison@mk-delices.test', 'Resp. Livraison Démo', User::ROLE_RESP_LIVRAISON],
        ];
        foreach ($accounts as [$email, $name, $role]) {
            $user = User::query()->firstOrCreate(['email' => $email], ['name' => $name, 'password' => $this->passwordFor($role), 'is_active' => true]);
            $user->syncRoles([$role]);
        }
    }

    /**
     * Audit E11 : hors poste local, l'administrateur de démonstration n'a JAMAIS le mot de passe public du
     * dépôt. Il reçoit DEMO_ADMIN_PASSWORD (.env) ou, à défaut, un mot de passe aléatoire affiché une seule fois.
     */
    private function passwordFor(string $role): string
    {
        if ($role !== User::ROLE_ADMIN || app()->environment(['local', 'testing'])) {
            return self::DEMO_PASSWORD;
        }
        $password = (string) env('DEMO_ADMIN_PASSWORD', '');
        if (mb_strlen($password) < 12) {
            $password = Str::password(20, symbols: false);
            $this->command?->warn("Mot de passe de admin@mk-delices.test (affiché une seule fois, à conserver) : {$password}");
        }

        return $password;
    }

    /** @return array<string, DeliveryZone> */
    private function zones(): array
    {
        $zones = [
            ['plateau', 'Plateau (démo)', 'Plateau, Médina', 1500, 900],
            ['mermoz', 'Mermoz – Sacré-Cœur (démo)', 'Mermoz, Sacré-Cœur, Point E', 2000, 1200],
            ['almadies', 'Almadies – Ngor (démo)', 'Almadies, Ngor, Ouakam', 2500, 1500],
            ['parcelles', 'Parcelles Assainies (démo)', 'Parcelles, Grand Yoff', 3000, 1800],
        ];
        $result = [];
        foreach ($zones as $i => [$slug, $name, $districts, $fee, $partnerFee]) {
            $result[$slug] = DeliveryZone::query()->firstOrCreate(['slug' => "demo-{$slug}"], [
                'name' => $name, 'districts' => $districts, 'delivery_fee_xof' => $fee, 'partner_fee_xof' => $partnerFee,
                'min_order_xof' => 5000, 'active' => true, 'position' => $i, 'is_demo' => true,
                'description' => 'Zone et tarif fictifs de démonstration.',
            ]);
        }

        return $result;
    }

    private function partners(array $zones): void
    {
        $partners = [
            ['livreur1@mk-delices.test', 'Livreur Démo A', '+221770000001', ['plateau', 'mermoz']],
            ['livreur2@mk-delices.test', 'Livreur Démo B', '+221770000002', ['mermoz', 'almadies', 'parcelles']],
        ];
        foreach ($partners as [$email, $name, $phone, $zoneKeys]) {
            $user = User::query()->firstOrCreate(['email' => $email], ['name' => $name, 'phone' => $phone, 'password' => self::DEMO_PASSWORD, 'is_active' => true]);
            $user->syncRoles([User::ROLE_LIVREUR]);
            $partner = DeliveryPartner::query()->firstOrCreate(['user_id' => $user->id], [
                'display_name' => $name, 'phone' => $phone, 'transport_mode' => 'moto', 'active' => true, 'is_demo' => true,
            ]);
            $partner->zones()->sync(array_map(fn ($k) => $zones[$k]->id, $zoneKeys));
        }
    }

    private function catalog(): void
    {
        $categories = [];
        foreach ([
            ['tiramisus', 'Tiramisus', 'Notre spécialité, en coupes individuelles et formats à partager.'],
            ['gateaux', 'Gâteaux', 'Gâteaux d\'anniversaire et entremets à partager.'],
            ['douceurs', 'Bonbons & douceurs', 'Petites gourmandises et confiseries maison.'],
            ['desserts', 'Desserts', 'Desserts frais et verrines.'],
        ] as $i => [$slug, $name, $description]) {
            $categories[$slug] = Category::query()->firstOrCreate(['slug' => $slug], ['name' => $name, 'description' => $description, 'position' => $i, 'active' => true, 'is_demo' => true]);
        }

        $tiramisuAllergens = ['lait', 'œufs', 'gluten'];
        $storage = 'À conserver au réfrigérateur (0–4 °C) dès réception. À consommer de préférence sous 48 h. (Consigne de démonstration à valider.)';
        $products = [
            ['tiramisus', 'tiramisu-classique', 'Tiramisu classique', 'Le grand classique, café et cacao', 'Biscuits imbibés de café, crème mascarpone onctueuse, voile de cacao amer.', 'Mascarpone, œufs, sucre, biscuits cuillère (farine de blé), café, cacao.', $tiramisuAllergens, true, true, false, [
                ['TIR-CLA-IND', 'Coupe individuelle', 'Individuel', 1, 2500, 240], ['TIR-CLA-4P', 'Format 4 personnes', '4 parts', 4, 9000, 360], ['TIR-CLA-8P', 'Format 8 personnes', '8 parts', 8, 17000, 480]]],
            ['tiramisus', 'tiramisu-speculoos', 'Tiramisu spéculoos', 'Croquant caramélisé', 'Crème mascarpone légère, biscuits spéculoos et éclats caramélisés.', 'Mascarpone, œufs, sucre, biscuits spéculoos (farine de blé, épices), beurre.', $tiramisuAllergens, true, false, false, [
                ['TIR-SPE-IND', 'Coupe individuelle', 'Individuel', 1, 2800, 240], ['TIR-SPE-4P', 'Format 4 personnes', '4 parts', 4, 10000, 360]]],
            ['tiramisus', 'tiramisu-fruits-rouges', 'Tiramisu fruits rouges', 'Fraîcheur fruitée', 'Crème mascarpone vanillée, coulis et fruits rouges.', 'Mascarpone, œufs, sucre, biscuits (farine de blé), fruits rouges, vanille.', $tiramisuAllergens, false, false, true, [
                ['TIR-FRU-IND', 'Coupe individuelle', 'Individuel', 1, 3000, 240], ['TIR-FRU-4P', 'Format 4 personnes', '4 parts', 4, 11000, 360]]],
            ['tiramisus', 'tiramisu-bissap', 'Tiramisu bissap', 'Création locale', 'Une touche dakaroise : crème mascarpone et sirop de bissap.', 'Mascarpone, œufs, sucre, biscuits (farine de blé), fleurs d\'hibiscus.', $tiramisuAllergens, true, false, true, [
                ['TIR-BIS-IND', 'Coupe individuelle', 'Individuel', 1, 3000, 240]]],
            ['tiramisus', 'tiramisu-nutella', 'Tiramisu chocolat-noisette', 'Gourmandise absolue', 'Crème mascarpone, pâte chocolat-noisette et noisettes torréfiées.', 'Mascarpone, œufs, sucre, biscuits (farine de blé), chocolat, noisettes.', [...$tiramisuAllergens, 'fruits à coque'], false, false, false, [
                ['TIR-NUT-IND', 'Coupe individuelle', 'Individuel', 1, 3000, 240], ['TIR-NUT-8P', 'Format 8 personnes', '8 parts', 8, 19000, 480]]],
            ['gateaux', 'gateau-chocolat', 'Gâteau fondant au chocolat', 'Pour les anniversaires', 'Fondant intense, ganache chocolat noir.', 'Chocolat, beurre, œufs, sucre, farine de blé.', ['lait', 'œufs', 'gluten'], true, false, false, [
                ['GAT-CHO-6P', 'Gâteau 6 parts', '6 parts', 6, 12000, 720], ['GAT-CHO-12P', 'Gâteau 12 parts', '12 parts', 12, 22000, 1440]]],
            ['gateaux', 'layer-cake-vanille', 'Layer cake vanille', 'Personnalisable', 'Génoise vanille et crème légère, décor sur demande.', 'Farine de blé, œufs, beurre, sucre, crème, vanille.', ['lait', 'œufs', 'gluten'], false, true, false, [
                ['GAT-LAY-10P', 'Gâteau 10 parts', '10 parts', 10, 25000, 1440]]],
            ['douceurs', 'truffes-chocolat', 'Truffes au chocolat', 'Boîte cadeau', 'Truffes fondantes roulées dans le cacao.', 'Chocolat, crème, beurre, cacao.', ['lait'], false, false, false, [
                ['DOU-TRU-12', 'Boîte de 12', '12 pièces', null, 6000, 120], ['DOU-TRU-24', 'Boîte de 24', '24 pièces', null, 11000, 120]]],
            ['douceurs', 'bonbons-coco', 'Bonbons coco', 'Douceur d\'enfance', 'Bouchées coco fondantes.', 'Noix de coco, lait concentré, sucre.', ['lait'], false, true, false, [
                ['DOU-COC-20', 'Sachet de 20', '20 pièces', null, 3500, 60]]],
            ['desserts', 'panna-cotta-mangue', 'Panna cotta mangue', 'Fraîche et légère', 'Crème vanillée, coulis de mangue.', 'Crème, sucre, gélatine, mangue, vanille.', ['lait'], false, false, false, [
                ['DES-PAN-IND', 'Verrine individuelle', 'Individuel', 1, 2000, 180]]],
        ];

        foreach ($products as $i => [$cat, $slug, $name, $tagline, $description, $ingredients, $allergens, $featured, $new, $signatureFlag, $variants]) {
            $product = Product::query()->firstOrCreate(['slug' => $slug], [
                'category_id' => $categories[$cat]->id, 'name' => $name, 'tagline' => $tagline, 'description' => $description,
                'ingredients' => $ingredients, 'allergens' => $allergens, 'storage_instructions' => $storage, 'shelf_life_hours' => 48,
                'requires_cold_chain' => $cat !== 'douceurs', 'status' => ProductStatus::Published, 'is_featured' => $featured,
                'is_new' => $new, 'is_signature' => $slug === 'tiramisu-classique' || $signatureFlag, 'position' => $i, 'is_demo' => true,
                'published_at' => now(), 'seo_description' => "{$name} — {$tagline}. Pâtisserie artisanale livrée à Dakar.",
            ]);
            foreach ($variants as $pos => [$sku, $label, $size, $serves, $price, $prep]) {
                $product->variants()->firstOrCreate(['sku' => $sku], [
                    'label' => $label, 'size_label' => $size, 'serves' => $serves, 'price_xof' => $price,
                    'prep_minutes' => $prep, 'available' => true, 'position' => $pos, 'max_per_order' => 20,
                ]);
            }
        }
        $classique = Product::query()->where('slug', 'tiramisu-classique')->first();
        $classique?->related()->syncWithoutDetaching(Product::query()->whereIn('slug', ['tiramisu-speculoos', 'tiramisu-fruits-rouges', 'truffes-chocolat'])->pluck('id'));
    }
}
