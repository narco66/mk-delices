# Architecture implémentée — MK Délices

**Mise à jour :** 15/11/2026. Ce document décrit ce qui est **réellement codé** ; les écarts avec le cadrage sont listés dans `DEV-DECISIONS-A-VALIDER.md` §2.

## 1. Vue d'ensemble

Monolithe modulaire (TRD §1) :

```
Navigateur (SPA React)  ──/api/v1, /sanctum──▶  API Laravel 13 (PHP ≥ 8.3)  ──▶  PostgreSQL 18
        ▲                                         │   ├─ file d'attente (table jobs) : webhooks, emails
        │ HTML initial + méta SEO                 │   ├─ planificateur : rapprochement paiements, purge
        └──────────── SpaController ◀─────────────┘   └─ stockage public : images WebP
Prestataire de paiement ──webhook signé──▶ /api/v1/webhooks/payments/{provider}
```

| Couche | Technologies |
|---|---|
| Frontend | React 19, TypeScript 6, Vite 8, Tailwind CSS 4, React Router 8, TanStack Query 5, React Hook Form + Zod, Radix Dialog, Sonner, Recharts, lucide-react |
| Backend | Laravel 13.42, Sanctum 4 (session SPA), Spatie Permission 8.5, Intervention Image 4 (GD) |
| Données | PostgreSQL 18 (UUID, `jsonb`, `timestamptz`, contraintes CHECK, index partiels, triggers) |
| Tests | PHPUnit 12 (PostgreSQL réel), Vitest 5, Testing Library, Playwright 1.65 |

## 2. Backend

```
backend/app/
├── Domain/                      # logique métier (services, enums) — contrôleurs légers
│   ├── Catalog/                 ProductStatus, ProductImageService
│   ├── Cart/                    CartService, QuoteService, Quote
│   ├── Orders/                  OrderStatus, OrderPaymentStatus, OrderStateMachine, CheckoutService,
│   │                            SlotService, StockService, OrderTracking, OrderReference,
│   │                            OrderJournal, OrderNotifier, WhatsAppMessageBuilder
│   ├── Payments/                PaymentService, RefundService, GatewayRegistry, PaymentStatus,
│   │   └── Gateways/            PaymentGateway (interface), SimulatorGateway, WaveGateway, OrangeMoneyGateway
│   ├── Deliveries/              DeliveryService, AssignmentStatus
│   ├── Promotions/              PromotionService
│   ├── Reporting/               StatsService
│   └── Shared/                  Settings, AuditLogger, Idempotency, RequestContext, DomainException, Money, Phone
├── Http/Controllers/Api/V1/     public, Admin/*, Courier/*  + SpaController (SEO)
├── Http/Middleware/             AssignRequestId, SecurityHeaders, EnsureUserIsActive
├── Http/Resources/              réponses par rôle (OrderResource, PublicOrderResource, MissionResource…)
├── Jobs/ProcessPaymentEvent     traitement asynchrone des webhooks (reprises exponentielles)
├── Models/                      26 modèles Eloquent (UUID)
└── Notifications/               StaffOrderActivity (base), CustomerOrderUpdate (email)
```

### 2.1 Machines à états (trois, indépendantes)
- **Commande** (`OrderStateMachine`) : actions manuelles `submit, accept, reject, cancel, start_preparation, mark_ready, hand_over_pickup` avec permission, motif obligatoire, version attendue ; transitions système pilotées par la livraison (`ready→assigned`, `assigned→picked_up|ready`, `picked_up→delivered|delivery_failed`, `delivery_failed→assigned`). Chaque transition : verrou `FOR UPDATE`, `version++`, horodatage, `order_events`, `audit_logs`, notification après commit.
- **Paiement de la commande** : `unpaid, pending, paid, failed, partially_refunded, refunded` ; **tentative** (`payments.status`) : `initiated, pending, succeeded, failed, cancelled, expired`.
- **Mission** : `proposed, accepted, refused, picked_up, delivered, failed, cancelled`.

### 2.2 Paiement
1. `POST /orders/{ref}/payments` (jeton de suivi + `Idempotency-Key`) → session ouverte réutilisée si existante.
2. Le prestataire appelle `POST /webhooks/payments/{provider}` → signature vérifiée (401 sinon) → `INSERT … ON CONFLICT DO NOTHING` sur `(provider, provider_event_id)` → 200 immédiat → job.
3. Le job **consulte l'état chez le prestataire** (`fetchStatus`) puis `applyVerifiedStatus` : contrôle montant/devise, `paid` une seule fois, paiement tardif sur commande annulée ⇒ demande de remboursement.
4. Le retour navigateur appelle seulement `/payments/refresh` (rapprochement serveur) ; il n'écrit jamais `paid`.
5. `mk:reconcile-payments` (toutes les 5 min) rattrape les webhooks perdus.

Le **simulateur** (`SimulatorGateway`) reproduit ce cycle (webhook HMAC-SHA256) et n'est actif que si `APP_ENV ∈ {local, testing}` **et** `PAYMENTS_SIMULATOR_ENABLED=true`.

### 2.3 Concurrence et idempotence
- Commandes : `Idempotency-Key` + hash de la charge utile, verrou consultatif par clé (`pg_advisory_xact_lock`).
- Créneaux : verrou consultatif par créneau, comptage des commandes occupantes.
- Stock : `UPDATE … SET stock_qty = stock_qty - n WHERE stock_qty >= n`.
- Missions : index unique partiel `WHERE status IN ('proposed','accepted','picked_up')`.
- Promotions : ligne verrouillée lors de la confirmation ; un seul code par commande.

### 2.4 Base de données (42 tables)
Migrations dans `backend/database/migrations` (ordre : identités → catalogue → zones/clients → paniers/commandes → paiements → livraisons → contenus/audit). Montants `bigint` XOF ; contrainte `total = sous-total − remise + livraison` ; snapshots (prix, libellés, allergènes, conservation, adresse, client) ; `order_events` et `audit_logs` protégés contre UPDATE/DELETE par trigger. Connexion forcée en UTC ; affichage en `Africa/Dakar`.

### 2.5 Sécurité
Session Sanctum (cookies HttpOnly, SameSite=Lax, CSRF `XSRF-TOKEN`), aucune donnée d'authentification dans `localStorage`. RBAC Spatie (24 permissions, 6 rôles), administrateur via `Gate::before`. Limitation de débit (`api`, `login`, `cart`, `checkout`, `tracking`, `webhooks`). Réponses filtrées par rôle (téléphone masqué pour le préparateur, adresse du client visible par le livreur uniquement pendant la mission). Format d'erreur unique `{error:{code,message,details},request_id}` ; en-tête `X-Request-Id` propagé dans les journaux.

## 3. Frontend

```
frontend/src/
├── app, routes/router.tsx       routes publiques, /admin (lazy), /livreur (lazy), gardes de rôle
├── components/ui                Button, Form (Field, Input…), Feedback (Skeleton, EmptyState, ErrorState, Notice), Display (Modal, StatusPill, Price, QuantityStepper…)
├── components/brand             Logo, DessertArt (illustrations), ProductVisual (srcset, lazy)
├── features/auth|cart|catalog   contextes de session et de panier, composants catalogue
├── hooks                        données publiques, SEO
├── layouts                      PublicLayout, AdminLayout, CourierLayout
├── lib                          api (CSRF, erreurs), format (XOF, Dakar), storage
└── pages                        public, shop, checkout, orders, account, admin/{dashboard,orders,operations,catalog,system}, courier
```

Données serveur via TanStack Query ; formulaires React Hook Form + Zod avec erreurs serveur rattachées aux champs ; états chargement/vide/erreur sur chaque écran. Design system : jetons `@theme` (`index.css`) issus du document UI-UX.

## 4. Déploiement cible (non exécuté)

```
Internet ──HTTPS──▶ Nginx
   ├─ /assets/*, /favicon.svg       → frontend/dist (cache long)
   ├─ /api/*, /sanctum/*, /storage/* → PHP-FPM (backend/public/index.php)
   ├─ /sitemap.xml, /robots.txt     → PHP-FPM
   └─ toutes les autres URL         → PHP-FPM (SpaController : index.html + méta SEO)
PHP-FPM ─▶ PostgreSQL (port non exposé) ; supervisor : queue:work ; cron : schedule:run
```

Variables à définir en production : `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`, `FRONTEND_URL`, `SESSION_DOMAIN`, `SESSION_SECURE_COOKIE=true`, `SANCTUM_STATEFUL_DOMAINS`, `SPA_INDEX_PATH`, `DB_*`, `MAIL_*`, `QUEUE_CONNECTION`, identifiants PSP. Compte administrateur : `php artisan mk:create-admin` (aucun mot de passe par défaut).
