# Matrice d'implémentation — MK Délices

**Mise à jour :** 15/11/2026 · Référentiel : PRD §4, BUSINESS-RULES, SECURITY-RULES, TEST-PLAN.

**Statuts :** ✅ Terminé (implémenté **et** vérifié par un test exécuté) · 🟡 Partiel (implémenté, vérification ou dépendance externe incomplète) · ⛔ Bloqué (décision ou contrat externe requis) · ⬜ Non commencé (hors MVP).

Les tests cités sont ceux de `backend/tests` (PHPUnit sur PostgreSQL) et `frontend/e2e` (Playwright). Résultats : `docs/DEV-TESTS.md`.

## 1. Exigences fonctionnelles (PRD)

| ID | Fonctionnalité | Prio | Backend | API | Frontend | Tables | Sécurité | Tests | Statut |
|---|---|---|---|---|---|---|---|---|---|
| CAT-001 | Catalogue et vitrines | M | `ProductController` (admin), `CatalogController`, `ProductImageService`, scope `publiclyVisible` | `GET /catalog/*`, `/admin/products*`, `/admin/categories` | Accueil, `/nos-tiramisus`, `/boutique` (recherche, filtres, tri), `/produits/:slug` (allergènes avant ajout), admin Catalogue | categories, products, product_variants, product_images, product_relations | `catalog.manage` ; publication refusée sans allergènes/ingrédients ; upload : MIME réel, ré-encodage WebP, EXIF supprimé | CatalogTest (6), ProductImageUploadTest (3), E2E Accueil + Scénario A | ✅ |
| CART-002 | Panier et devis | M | `CartService`, `QuoteService`, `PromotionService` | `POST/GET /cart`, `/cart/items*`, `PUT /cart/promotion`, `POST /checkout/quote` | Tiroir panier, `/panier`, récapitulatif checkout | carts, cart_items, promotions | Jeton de panier haché ; aucun prix accepté du client | CheckoutTest (prix falsifié, promo), unitaires remise | ✅ |
| ORDER-003 | Commande invitée | M | `CheckoutService`, `SlotService`, `StockService`, `OrderTracking`, `Idempotency` | `POST /checkout/orders` (Idempotency-Key), `GET /orders/{ref}/status` | `/commander`, `/commande/:ref/confirmation`, `/suivi/:ref` | orders, order_items, order_events, customers, idempotency_keys | Référence non prédictible, jeton de suivi haché à durée limitée, 404 indistinct | CheckoutTest (12), E2E Scénario A | ✅ |
| WA-004 | Passerelle WhatsApp | M | `CheckoutService::saveWhatsAppDraft/confirmDraft`, `WhatsAppMessageBuilder` | `POST /checkout/whatsapp-draft`, `POST /orders/{ref}/whatsapp-draft` | Bouton « Envoyer mon panier sur WhatsApp », bouton flottant, suivi | orders (`channel=whatsapp`, `status=draft`) | Message sans jeton ni donnée superflue ; brouillon ≠ commande ≠ paiement | OrderWorkflowTest (Scénario C, confirmation commerciale), E2E Scénario C | ✅ (numéro réel : ⛔ D-WA-01 ; WhatsApp Business Platform : ⬜) |
| PAY-005 | Encaissement | M | `PaymentGateway` (Simulator, Wave, OrangeMoney), `PaymentService`, `RefundService`, job `ProcessPaymentEvent`, `mk:reconcile-payments` | `POST /orders/{ref}/payments`, `/payments/refresh`, `POST /webhooks/payments/{provider}`, `/admin/payments*`, `/admin/refunds*` | `/paiement/:ref`, retour, écran simulateur (test), admin Paiements/Remboursements | payments, payment_events, refunds | Webhook signé + consultation PSP ; déduplication `ON CONFLICT` ; montant/devise vérifiés ; double contrôle des remboursements | PaymentTest (11), E2E Scénario B | 🟡 chaîne complète ✅ avec le simulateur ; Wave ⛔ D-PAY-01 (non exécuté) ; Orange Money ⛔ D-PAY-02 |
| PREP-006 | Préparation | M | `OrderStateMachine` | `POST /admin/orders/{id}/transitions`, `GET /admin/preparation` | Admin › Préparation (kanban), fiche commande | orders, order_events | `preparation.manage` ; contrôle conditionnement obligatoire ; coordonnées masquées au préparateur | OrderWorkflowTest (Scénario D, T-011, version), E2E back-office | ✅ |
| DEL-007 | Livraisons partenaires | M | `DeliveryService` | `/admin/delivery/*`, `/courier/missions*` | Admin › Livraisons, Livreurs, Zones, Règlements ; espace `/livreur` | delivery_zones, delivery_partners, delivery_partner_zones, delivery_assignments | Index unique partiel (1 mission active), éligibilité zone, adresse dévoilée après acceptation et masquée après clôture | DeliveryTest (Scénarios E, F, G, T-012/13/14/15), E2E back-office (livreur 360 px) | ✅ |
| CMS-008 | Site et contenus | M | `ContentController`, `BackOfficeController` (pages, blocs) | `GET /content/*`, `/admin/content/*` | Pages publiques (Markdown), admin Contenus | content_pages, content_blocks, settings | `content.manage` ; brouillon / publication | EndpointsSmokeTest, E2E administration (brouillon → publication) | ✅ |
| SEC-009 | Accès et audit | M | Spatie Permission, `Gate::before` admin, `AuditLogger`, triggers d'immuabilité | toutes routes protégées | Gardes de routes, menus filtrés | roles, permissions, audit_logs, order_events | Deny-by-default côté serveur | SecurityTest (11), DeliveryTest Scénario G, E2E Scénario G | ✅ |
| STATS-010 | Pilotage commercial | S | `StatsService` | `GET /admin/dashboard`, `/admin/stats` | Admin › Vue générale, Statistiques (graphiques) | (agrégats SQL) | `stats.view` | ReportingTest | ✅ (taux de conversion : ⛔ D-STATS-01) |
| LOY-011 | Fidélisation | S | Comptes clients, `PromotionService`, avis | `/auth/register`, `/account/*`, `/orders/{ref}/reviews`, `/admin/reviews` | `/inscription`, `/compte`, avis depuis le suivi, admin Avis/Promotions | customers, addresses, reviews, promotions | Consentement marketing distinct, non précoché, révocable | SecurityTest (inscription), CheckoutTest (promo) | 🟡 (points de fidélité ⬜ — V2) |
| GPS-012 | Traçage GPS continu | C | — | — | — | — | Inactif par défaut | — | ⬜ (option, non requis au MVP) |
| EVT-013 | Commandes événementielles | C | — | — | Page Contact mentionne le devis | — | — | — | ⬜ (option) |

## 2. Règles de gestion (BUSINESS-RULES §4)

| Règle | Implémentation | Test | Statut |
|---|---|---|---|
| BR-001 Produit actif à la validation | `ProductVariant::isSellable`, devis verrouillé `FOR UPDATE` | `test_disabled_product_cannot_be_added_nor_ordered` | ✅ |
| BR-002 Total serveur entier, remise plafonnée | `QuoteService`, contrainte `chk_orders_amounts` | `test_client_side_price_is_ignored…`, unitaires | ✅ |
| BR-003 Zone active obligatoire | `Quote::assertValid` | `test_inactive_zone_blocks_checkout…` | ✅ |
| BR-004 Créneau : capacité, délai, verrou atomique | `SlotService::reserve` (`pg_advisory_xact_lock`) | `test_full_slot_is_refused…` | ✅ |
| BR-005 `paid` uniquement après confirmation PSP | `PaymentService::applyVerifiedStatus` | `test_signed_webhook_is_not_trusted…`, `test_browser_return…` | ✅ |
| BR-006 Refus après paiement ⇒ remboursement | `OrderStateMachine::onTermination` | `test_rejecting_paid_order_opens_refund…` | ✅ |
| BR-007 WhatsApp ne confirme pas | brouillon `draft`, aucun paiement | Scénario C | ✅ |
| BR-008 Livreur actif et éligible | `DeliveryService::assign` | `test_partner_outside_zone…` | ✅ |
| BR-009 Un seul livreur actif | index unique partiel + verrou | `test_second_active_assignment_is_rejected` | ✅ |
| BR-010 Preuve horodatée | `deliver()` exige type + référence | Scénario E | ✅ |
| BR-011 Annulation selon état | actions `cancel` / annulation client | `test_customer_can_cancel_only_before_acceptance` | ✅ |
| BR-012 Périssable / chaîne du froid | contrôle conditionnement (préparation + enlèvement), badges « produit frais » ; seuil sanitaire | Scénarios D/E | 🟡 seuil ⛔ D-QUA-01 |
| BR-013 Remboursé ≤ capturé | `RefundService` + contrainte `refunded_xof <= paid_xof` | `test_requester_cannot_approve…` | ✅ |
| BR-014 Concurrence | `version` + `expected_version`, `lockForUpdate`, verrous consultatifs | `test_stale_version_is_rejected`, `test_last_unit_can_only_be_reserved_once` | ✅ |
| BR-015 Journal acteur/motif/date | `OrderJournal`, table immuable | Scénario D, `test_order_journal_is_immutable…` | ✅ |

## 3. Exigences de sécurité (SECURITY-RULES §3)

| ID | Statut | Preuve |
|---|---|---|
| SEC-001 HTTPS/HSTS, cookies sécurisés | 🟡 HSTS émis si HTTPS ; cookies HttpOnly/SameSite=Lax ; `SESSION_SECURE_COOKIE` à activer en production | `SecurityHeaders`, `.env.example` |
| SEC-002 Sanctum + CSRF | ✅ | E2E (login réel via cookie XSRF), test client API |
| SEC-003 Autorisation par objet | ✅ | Scénario G, matrice de rôles |
| SEC-004 Uploads | ✅ MIME réel, dimensions, ré-encodage WebP, script déguisé refusé | `ProductImageUploadTest` |
| SEC-005 Webhook signé et idempotent | ✅ | T-007, T-008 |
| SEC-006 Secrets hors Git | ✅ `.env` ignorés, `.env.example` sans secret | `.gitignore` |
| SEC-007 Limitation de débit | ✅ | `test_login…rate_limit` (429) |
| SEC-008 Journaux expurgés | ✅ `AuditLogger::redact`, téléphones masqués dans `notification_logs` | revue de code |
| SEC-009 Sauvegardes chiffrées testées | ⛔ dépend de l'hébergement (D-HEB-01) | — |
| SEC-010 Dépendances contrôlées | ✅ production : 0 vulnérabilité ; outillage ESLint : 7 « moderate » (minimatch) sans impact production, suivi | DEV-TESTS |
| SEC-011 Exposition PII minimale | ✅ | `test_courier_and_preparer_see_masked…`, Scénario E |
| SEC-012 En-têtes de sécurité | 🟡 nosniff, Referrer-Policy, X-Frame-Options, CSP stricte sur l'API ; CSP du SPA à définir au niveau Nginx | `test_security_headers…` |
| SEC-013 Consentement marketing explicite | ✅ case non précochée, distincte | inscription + checkout |
| SEC-014 Double contrôle remboursements | ✅ | `test_requester_cannot_approve_own_refund…` |

## 4. Exigences non fonctionnelles (PRD §6)

| ID | Statut | Commentaire |
|---|---|---|
| NFR-001 Mobile-first, 360 px | ✅ | E2E « aucun débordement horizontal » à 360 px |
| NFR-002 Performance API | 🟡 | Non mesuré sous charge (à faire en préproduction) |
| NFR-003 Disponibilité | ⛔ | Dépend de l'hébergement |
| NFR-004 Sécurité | ✅/🟡 | voir §3 |
| NFR-005 Traçabilité | ✅ | `order_events`, `audit_logs` immuables |
| NFR-006 Accessibilité WCAG 2.2 AA | ✅ (automatisé) | axe-core : 0 violation serious/critical sur 10 écrans ; commande au clavier seul (T-022) ; test avec lecteur d'écran par une personne recommandé avant production |
| NFR-007 Localisation | ✅ | Français, XOF entier, Africa/Dakar, téléphone sénégalais normalisé |
| NFR-008 Reprise RPO/RTO | ⛔ | Dépend de l'hébergement |
| NFR-009 Référencement | ✅ | `SpaController` (méta, JSON-LD), sitemap, robots ; `SeoTest` |
