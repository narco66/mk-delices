# Spécifications UI/UX et design system

**MK DÉLICES** — Dossier de cadrage fonctionnel et technique

**Document 04/12** · Version 1.0 · 14 novembre 2026

---

## Gouvernance du document

| Champ | Valeur |
|---|---|
| Projet | MK Délices — Plateforme e-commerce pâtisserie |
| Version | 1.0 — cadrage initial du 14 novembre 2026 |
| Statut | Proposition de référence ; à valider par MK Délices |
| Localisation | Dakar, Sénégal |
| Monnaie / fuseau | XOF / Africa/Dakar (UTC+0) |
| Socle préconisé | React + TypeScript + Vite ; Laravel 13 / PHP ≥ 8.3 ; PostgreSQL |
| Hypothèses | Catalogue, prix, zones, frais, prestataires et identité visuelle à confirmer |

**Convention :** « DOIT » = exigence impérative ; « DEVRAIT » = souhaitable ; « option » = hors MVP ou à arbitrer. Les exemples de tarifs, d'adresses, de parfums et de durées ne constituent jamais des données commerciales officielles. Toutes les valeurs monétaires sont enregistrées en unités entières de XOF.


## 1. Positionnement visuel

Concept créatif proposé : **atelier gourmand premium**, élégant et chaleureux, fond ivoire, accents cacao et framboise, grandes photographies des tiramisus, parcours de commande minimaliste. Le logo et l identité officielle restent à fournir ; palette ci-dessous indicative et modifiable.

## 2. Design tokens

| Token | Valeur de départ | Utilisation |
| --- | --- | --- |
| color.background | #FFF9F2 | Fond principal |
| color.surface | #FFFFFF | Cartes et surfaces |
| color.primary | #5B342B | Boutons cacao |
| color.accent | #B74264 | Promotions framboise |
| color.text | #2A211F | Texte principal |
| color.success | #22724F | État réussi |
| color.error | #B3261E | Erreur accessible |
| radius.card | 16px | Cartes produit |
| radius.control | 10px | Champs et boutons |
| spacing.base | 4 / 8 / 12 / 16 / 24 / 32px | Système espacement |
| font.heading | Fraunces ou police approuvée | Titres |
| font.body | Inter ou police approuvée | Contenus |

## 3. Gabarits d écrans

| ID écran | Route proposée | Contenu et CTA | États |
| --- | --- | --- | --- |
| UX-01 | / | Hero tiramisu, best sellers, catégories, livraison, CTA commander | Chargement CMS, produits vides |
| UX-02 | /boutique | Filtres, grille 2 col mobile, tri et catégories | Aucun résultat |
| UX-03 | /produits/:slug | Galerie, allergènes, format, prix, quantité, Ajouter au panier | Indisponible |
| UX-04 | /panier | Articles, prix, frais estimés, CTA valider | Panier vide |
| UX-05 | /commander | Destinataire, téléphone, quartier, repères, créneau | Adresse non desservie |
| UX-06 | /paiement/:ref | Mode, redirection PSP, état de vérification | PSP indisponible |
| UX-07 | /suivi/:token | Étapes non sensibles et contact support | Jeton expiré |
| UX-08 | /admin/commandes | Tableau filtres, détails et actions autorisées | Accès refusé |
| UX-09 | /admin/preparation | Kanban préparation avec minuterie métier | Rupture |
| UX-10 | /livreur/missions | Cartes missions, navigation externe optionnelle | Mission réaffectée |

## 4. Composants et interactions

**Composants :** Header, Hero, ProductCard, PriceBadge, ProductVariantPicker, AllergyNotice, QuantityStepper, CartDrawer, DeliveryZonePicker, SlotPicker, AddressForm, PaymentMethodTile, OrderTimeline, StatusPill, AssignmentDialog, ConfirmationModal, Skeleton, Toast, EmptyState, DataTable.

**Règles :** un seul CTA dominant par écran ; rappel du total avant paiement ; prix formatés en XOF sans décimales ; focus visible ; messages d’erreur précis ; actions destructives confirmées ; modales accessibles et fermables au clavier. Sur mobile, bouton « Commander » fixé en bas seulement si non masquant les contenus.

## 5. Responsive et accessibilité

Points de rupture indicatifs : 360–639px smartphone ; 640–1023px tablette ; ≥1024px desktop. Cibles tactiles ≥44×44px ; contraste des textes ≥4,5:1 (hors grandes lettres) ; textes alternatifs sur images ; champs étiquetés ; pas de couleur seule pour les statuts ; prise en charge réduction des animations. Vérifier WCAG 2.2 AA sur flux critiques.

## 6. Prototypage et livraison Figma

Créer composants avec variantes (default, hover, focus, disabled, loading, error), variables de couleurs et typographies ; pages distinctes « Public », « Checkout », « Admin », « Livreur », « Design System ». Relier chaque écran UX-xx à la fonctionnalité PRD et créer prototypage client avec conditions de livraison. Livrables : maquettes 360/768/1440px, bibliothèque de composants, tokens exportables et revue de contraste.

## 7. Contenus et médias

Les photos doivent appartenir à MK Délices ou bénéficier d une licence. Des illustrations de démonstration ne doivent pas être présentées comme photographies des produits réels. Prévoir recadrages 1:1, 4:5, 16:9, chargement différé et textes alternatifs. Pour chaque tiramisu, afficher dans l ordre : nom, photo, prix, format, description, ingrédients/allergènes, disponibilité, délai de préparation et CTA.

## Références et réserves

**Sources externes de vérification (consultées le 14/11/2026) :**
- https://laravel.com/framework/docs/13.x/releases
- https://docs.wave.com/business
- https://docs.wave.com/checkout
- https://espaceclientv2.orange.sn/orange-money/api-internet
- https://www.cdp.sn/legislation/textes-legislatifs
- https://www.cdp.sn/legislation/textes-reglementaires

Le présent document distingue faits du projet communiqués, recommandations de conception et décisions commerciales à confirmer. Les paramètres externes (PSP, tarification, obligations légales, contrats) doivent faire l objet de vérifications au moment de l implémentation.

## Approbation et contrôle de changement

| Rôle | Visa / date |
|---|---|
| Direction MK Délices | À compléter |
| Responsable produit / opérations | À compléter |
| Responsable technique | À compléter |

Toute modification après validation doit créer un historique de version, une justification et une analyse d impact sur les autres documents.
