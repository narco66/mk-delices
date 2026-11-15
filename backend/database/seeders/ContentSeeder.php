<?php

namespace Database\Seeders;

use App\Models\ContentBlock;
use App\Models\ContentPage;
use Illuminate\Database\Seeder;

/**
 * Contenus éditoriaux initiaux. Les textes sont des PROPOSITIONS à valider par MK Délices ;
 * les pages juridiques sont des trames explicitement marquées « à valider » (requires_validation).
 * Ne remplace jamais un contenu existant.
 */
class ContentSeeder extends Seeder
{
    public function run(): void
    {
        $notice = "> **Document en attente de validation.** Ce texte est une trame de travail ; il ne constitue pas un avis juridique et doit être validé par MK Délices et son conseil avant mise en production.\n\n";

        $pages = [
            'notre-histoire' => ['Notre histoire', 'Une pâtisserie artisanale dakaroise, née de la passion du tiramisu.',
                "## Une maison artisanale à Dakar\n\nMK Délices prépare à la main des tiramisus, gâteaux, bonbons et desserts gourmands, livrés chez vous à Dakar.\n\n## Le tiramisu, notre signature\n\nCrème onctueuse, biscuits imbibés, cacao généreux : le tiramisu est au cœur de notre savoir-faire.\n\n## Fait maison, chaque jour\n\nNos desserts sont préparés sur commande pour être dégustés frais.\n\n*Texte de présentation proposé — à personnaliser par MK Délices (histoire, fondatrice, valeurs).*", false],
            'livraison' => ['Livraison', 'Zones desservies, créneaux et conditions de livraison.',
                "## Comment se passe la livraison ?\n\n1. Choisissez votre zone et un créneau lors de la commande.\n2. Nous confirmons votre commande et la préparons.\n3. Un livreur partenaire vous remet votre commande au créneau choisi.\n\n## Zones et frais\n\nLes zones desservies et leurs frais sont affichés lors de la commande et sur cette page.\n\n## Conservation\n\nNos desserts frais doivent être placés au réfrigérateur dès réception.\n\n*Les zones, frais et délais définitifs restent à confirmer par MK Délices.*", true],
            'contact' => ['Contact', 'Une question, une commande spéciale ? Écrivez-nous.',
                "Vous pouvez nous joindre par WhatsApp ou téléphone aux coordonnées indiquées ci-dessous.\n\nPour les commandes événementielles (anniversaires, mariages, entreprises), contactez-nous à l'avance afin d'établir un devis.", true],
            'faq' => ['Questions fréquentes', 'Tout savoir sur nos commandes, paiements et livraisons.', 'Voir les questions ci-dessous.', false],
            'conditions-generales' => ['Conditions générales de vente', 'Conditions applicables aux commandes passées sur le site MK Délices.',
                $notice."## 1. Objet\n\nLes présentes conditions régissent les ventes de pâtisseries réalisées par MK Délices via son site.\n\n## 2. Commandes\n\nToute commande est soumise à confirmation de MK Délices. Une commande envoyée par WhatsApp n'est confirmée qu'après validation explicite.\n\n## 3. Prix et paiement\n\nLes prix sont indiqués en francs CFA (XOF), toutes taxes applicables selon la réglementation en vigueur. Un paiement n'est considéré comme reçu qu'après confirmation par le prestataire de paiement.\n\n## 4. Livraison\n\n[Zones, délais, conditions — à compléter.]\n\n## 5. Annulation et remboursement\n\n[Politique à définir par MK Délices.]\n\n## 6. Produits alimentaires et allergènes\n\nLes ingrédients et allergènes connus sont indiqués sur chaque fiche produit.\n\n## 7. Réclamations\n\n[Modalités à compléter.]\n\n## 8. Droit applicable\n\nLoi n° 2008-08 du 25 janvier 2008 sur les transactions électroniques — à confirmer par le conseil juridique.", true],
            'confidentialite' => ['Politique de confidentialité', 'Comment MK Délices traite vos données personnelles.',
                $notice."## Données collectées\n\nPrénom, téléphone, adresse et consignes de livraison, contenu de la commande, et email si vous le fournissez.\n\n## Finalités\n\nPréparer et livrer votre commande, vous informer de son suivi, et — uniquement avec votre accord explicite — vous adresser nos offres.\n\n## Destinataires\n\nLe personnel habilité de MK Délices et le livreur affecté à votre commande (adresse et téléphone uniquement pendant la livraison).\n\n## Durée de conservation\n\n[À définir selon les obligations légales.]\n\n## Vos droits\n\nConformément à la loi n° 2008-12 du 25 janvier 2008 sur la protection des données à caractère personnel, vous disposez de droits d'accès, de rectification et d'opposition. [Contact et formalités CDP à compléter.]", true],
            'mentions-legales' => ['Mentions légales', 'Informations légales sur l\'éditeur du site.',
                $notice."- Raison sociale : [à compléter]\n- NINEA / RCCM : [à compléter]\n- Adresse : Dakar, Sénégal\n- Hébergeur : [à compléter]", true],
        ];
        foreach ($pages as $slug => [$title, $excerpt, $body, $validation]) {
            ContentPage::query()->firstOrCreate(['slug' => $slug], [
                'title' => $title, 'excerpt' => $excerpt, 'body' => $body, 'status' => 'published',
                'requires_validation' => $validation, 'published_at' => now(), 'seo_description' => $excerpt,
            ]);
        }

        $blocks = [
            'home.hero' => ['home', [
                'eyebrow' => 'Pâtisserie artisanale · Dakar',
                'title' => 'Le tiramisu qui fait fondre Dakar',
                'subtitle' => 'Tiramisus, gâteaux et douceurs préparés à la main, livrés chez vous au créneau de votre choix.',
                'cta_label' => 'Découvrir nos tiramisus',
                'cta_href' => '/nos-tiramisus',
                'image' => null,
            ], 1],
            'home.arguments' => ['home', ['items' => [
                ['icon' => 'hand', 'title' => 'Fait main', 'text' => 'Chaque dessert est préparé à la commande dans notre atelier.'],
                ['icon' => 'snowflake', 'title' => 'Frais et maîtrisé', 'text' => 'Conditionnement contrôlé avant chaque départ en livraison.'],
                ['icon' => 'truck', 'title' => 'Livré à Dakar', 'text' => 'Un créneau choisi, un suivi de commande en temps réel.'],
                ['icon' => 'shield', 'title' => 'Paiement sécurisé', 'text' => 'Paiement confirmé uniquement par le prestataire.'],
            ]], 2],
            'home.steps' => ['home', ['items' => [
                ['title' => 'Choisissez', 'text' => 'Parcourez nos tiramisus et douceurs, choisissez format et quantité.'],
                ['title' => 'Commandez', 'text' => 'En ligne ou via WhatsApp, avec le créneau qui vous arrange.'],
                ['title' => 'Savourez', 'text' => 'Nous préparons et livrons votre commande, bien fraîche.'],
            ]], 3],
            'home.testimonials' => ['home', ['notice' => 'Témoignages fictifs de démonstration — à remplacer par des avis clients réels et modérés.', 'items' => [
                ['author' => 'Client de démonstration', 'text' => 'Exemple de témoignage : un tiramisu léger et généreux.', 'stars' => 5],
                ['author' => 'Cliente de démonstration', 'text' => 'Exemple de témoignage : livraison à l\'heure, emballage soigné.', 'stars' => 5],
            ]], 4],
            'faq.items' => ['faq', ['items' => [
                ['q' => 'Faut-il créer un compte pour commander ?', 'a' => 'Non. Vous pouvez commander en tant qu\'invité ; un lien de suivi sécurisé vous est remis.'],
                ['q' => 'Ma commande WhatsApp est-elle confirmée dès l\'envoi du message ?', 'a' => 'Non. Le message prépare votre commande ; elle n\'est confirmée qu\'après validation par MK Délices.'],
                ['q' => 'Quand mon paiement est-il pris en compte ?', 'a' => 'Uniquement lorsque le prestataire de paiement nous le confirme. Le retour sur notre site ne suffit pas.'],
                ['q' => 'Comment conserver mon tiramisu ?', 'a' => 'Au réfrigérateur dès réception. Les consignes précises figurent sur chaque fiche produit.'],
                ['q' => 'Où trouver les allergènes ?', 'a' => 'Sur chaque fiche produit, avant l\'ajout au panier, et dans votre récapitulatif de commande.'],
                ['q' => 'Puis-je annuler ma commande ?', 'a' => 'Tant qu\'elle n\'est pas encore confirmée, depuis votre lien de suivi. Ensuite, contactez-nous.'],
            ]], 1],
        ];
        foreach ($blocks as $key => [$group, $data, $position]) {
            ContentBlock::query()->firstOrCreate(['key' => $key], ['group' => $group, 'data' => $data, 'status' => 'published', 'position' => $position]);
        }
    }
}
