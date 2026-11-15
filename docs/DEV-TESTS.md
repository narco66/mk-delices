# Résultats des tests — MK Délices

**Dernière exécution :** 15/11/2026 · Poste Windows 11 / Laragon · PHP 8.5.12 · PostgreSQL 18.4 (port 5433) · Node 22.23 · Edge (Playwright).

Seuls des tests **réellement exécutés** figurent ici. Commandes de reproduction en fin de document.

## 1. Synthèse

| Suite | Outil | Base / cible | Résultat |
|---|---|---|---|
| Backend — fonctionnels + unitaires | PHPUnit 12 | PostgreSQL `mk_delices_test` | **71 tests, 629 assertions, 0 échec** (35 s) |
| Style PHP | Laravel Pint (PSR-12) | `backend/` | conforme |
| Frontend — unitaires | Vitest 5 + Testing Library | jsdom | **9 tests, 0 échec** |
| Frontend — types / lint | `tsc -b`, ESLint | `frontend/src` | 0 erreur TS ; ESLint 0 erreur, 26 avertissements non bloquants (*fast refresh*, compatibilité react-hook-form/compilateur React) |
| Build de production | Vite 8 | `frontend/dist` | OK (plus gros bloc : 333 kB / 103 kB gzip) |
| Bout en bout | Playwright 1.65 (Edge) | Vite :5173 → Laravel :8000 → PostgreSQL `mk_delices` | **10 exécutions réussies** (5 parcours × mobile 360 px et desktop) |
| Dépendances | `composer audit`, `npm audit` | — | 0 vulnérabilité connue |

## 2. Détail backend (PHPUnit, PostgreSQL)

| Fichier | Tests | Couvre |
|---|---|---|
| `CatalogTest` | 6 | T-001, CAT-001, publication, audit des prix, droits |
| `CheckoutTest` | 12 | T-002, T-003, T-004, T-005, T-016, T-017, T-023, créneau saturé, promotion, annulation client |
| `PaymentTest` | 11 | Scénarios B et H, T-006 à T-010, paiement tardif, encaissement manuel, double contrôle |
| `OrderWorkflowTest` | 8 | Scénarios C et D, T-011, T-018, version concurrente, journal immuable, brouillon WhatsApp confirmé |
| `DeliveryTest` | 6 | Scénarios E, F, G, T-012 à T-015, refus livreur |
| `SecurityTest` | 11 | SEC-009, matrice RBAC, T-019 (429), en-têtes, inscription publique, compte désactivé |
| `ReportingTest` | 2 | STATS-010, T-024 |
| `SeoTest` | 4 | NFR-009 (méta, JSON-LD, sitemap, robots) |
| `EndpointsSmokeTest` | 1 | 36 endpoints GET avec données multiples (aucun 500, pas de chargement paresseux) |
| `Unit/DomainHelpersTest` | 10 | téléphone sénégalais, masquage, montants XOF, calcul de remise |

## 3. Fiches de recette des scénarios obligatoires

### Scénario A — Commande classique
- **Prérequis :** jeu de démonstration, zone active, simulateur de paiement actif.
- **Actions (E2E `parcours.spec.ts`) :** fiche « Tiramisu classique » → allergènes visibles → format 4 personnes × 2 → panier → `/commander` (prénom, téléphone, zone, quartier, créneau, CGV) → Confirmer.
- **Attendu :** commande `pending_confirmation`, référence `MKD-XXXX-XXXX`, total calculé par le serveur.
- **Constaté :** conforme (mobile 360 px et desktop), aucun débordement horizontal.
- **Preuve :** exécution Playwright du 15/11/2026 (10/10) ; `CheckoutTest::test_guest_order_is_created…`.
- **Statut :** ✅ · **Anomalies :** voir §4 (A-04, A-05).

### Scénario B — Paiement
- **Actions :** redirection vers le prestataire simulé → « paiement réussi » → webhook HMAC signé → job de traitement → consultation de l'état chez le prestataire.
- **Attendu :** `payment_status = paid` une seule fois ; statut commercial inchangé (`pending_confirmation`).
- **Constaté :** conforme ; page de retour « Paiement confirmé » issue du rapprochement serveur.
- **Preuve :** `PaymentTest::test_scenario_b…`, E2E, table `payment_events` (`processing_status = processed`).
- **Statut :** ✅ avec prestataire **simulé**. Wave/Orange Money réels : non testés (contrats absents, D-PAY-01/02).

### Scénario C — WhatsApp
- **Actions :** panier → « Envoyer mon panier sur WhatsApp ».
- **Attendu :** brouillon référencé, message encodé contenant la référence et les articles, aucun paiement, aucun doublon en cas de nouvel envoi ; conversion sur le site = même référence.
- **Constaté :** conforme ; `wa.me` redirige vers `api.whatsapp.com/send?phone=…&text=…` (comportement de WhatsApp).
- **Preuve :** `OrderWorkflowTest::test_scenario_c…`, E2E Scénario C.
- **Statut :** ✅

### Scénario D — Préparation
- **Actions :** commercial accepte (version attendue) → préparateur démarre → « prête » sans puis avec contrôle du conditionnement.
- **Attendu :** préparateur ne peut pas accepter (403) ; contrôle obligatoire (422) ; journal acteur/horodatage ; coordonnées figées.
- **Constaté :** conforme. **Preuve :** `OrderWorkflowTest::test_scenario_d…`. **Statut :** ✅

### Scénario E — Livraison
- **Actions :** affectation → livreur consulte (adresse masquée) → accepte (adresse dévoilée) → livrer avant enlèvement (409) → enlèvement avec contrôle → remise avec preuve.
- **Constaté :** conforme ; coordonnées à nouveau masquées après clôture ; montant dû au livreur calculé séparément.
- **Preuve :** `DeliveryTest::test_scenario_e…`. **Statut :** ✅

### Scénario F — Incident
- **Actions :** échec déclaré (motif, note, état du produit, contact tenté) → responsable consulte le tableau → réaffectation.
- **Constaté :** commande `delivery_failed`, incident horodaté, nouvelle mission acceptée → `assigned`.
- **Preuve :** `DeliveryTest::test_scenario_f…`. **Statut :** ✅

### Scénario G — Sécurité
- **Actions :** livreur B consulte/agit sur la mission de A ; accède à `/admin/orders`, `/admin/stats`.
- **Attendu / constaté :** 403 partout ; état de la mission inchangé ; redirection UI vers « accès refusé ».
- **Preuve :** `DeliveryTest::test_scenario_g…`, `SecurityTest::test_role_matrix…`, E2E Scénario G. **Statut :** ✅

### Scénario H — Idempotence
- **Actions :** même webhook reçu 3 fois ; nouvel identifiant d'événement pour un paiement déjà confirmé ; double clic « Commander » (même clé) ; clé réutilisée avec un autre contenu.
- **Constaté :** un seul événement enregistré, montant payé inchangé ; même commande renvoyée (`Idempotent-Replayed: true`) ; 409 `idempotency_key_reused`.
- **Preuve :** `PaymentTest::test_scenario_b…`, `CheckoutTest::test_double_click…`. **Statut :** ✅

## 4. Anomalies détectées pendant les tests et corrections

| ID | Anomalie | Cause | Correction | Re-test |
|---|---|---|---|---|
| A-01 | Créneaux décalés d'une heure | Fuseau de session PostgreSQL du serveur (`Africa/Lagos`, UTC+1) | `timezone = UTC` dans `config/database.php` | ✅ |
| A-02 | Erreur 500 sur relations « mission active » | `latestOfMany()` génère `MAX(uuid)`, invalide en PostgreSQL | Relation simple (unicité garantie par index partiel) | ✅ |
| A-03 | Webhook en double : transaction PostgreSQL interrompue | Doublon détecté par exception d'unicité | `INSERT … ON CONFLICT DO NOTHING` | ✅ |
| A-04 | Après connexion, retour à la page de connexion | `queryClient.clear()` détachait l'observateur de session | Purge sélective des requêtes | ✅ (E2E) |
| A-05 | Prochain créneau non proposé quand le premier est complet | Candidats limités à 1 avant filtrage de capacité | Recherche sur 60 créneaux | ✅ |
| A-06 | Page produit : erreur 500 côté pré-rendu SEO | Chargement paresseux interdit (mode strict) avec plusieurs formats | Disponibilité calculée sans relation ; test SEO avec plusieurs formats + test « smoke » multi-enregistrements | ✅ |
| A-07 | `robots.txt` statique masquant la version dynamique | Fichier par défaut de Laravel | Fichier supprimé | ✅ |
| A-08 | Écran d'erreur React (`useContext` null) en développement | Pré-bundle Vite régénéré en cours de session (deux copies de React) | `optimizeDeps.include` | ✅ |
| A-09 | Vitest « failed to find the current suite » | Terminal ouvert sur `c:\` (minuscule) : Vitest chargé deux fois | Lanceur `scripts/vitest.mjs` | ✅ |
| A-10 | Dépendances de développement absentes | `NODE_ENV=production` défini globalement sur le poste | `npm ci --include=dev` (documenté) | ✅ |

## 5. Couverture non automatisée / restant à faire

- Téléversement d'images produit (contrôle MIME/ré-encodage) : implémenté, **non testé automatiquement**.
- Pages back-office non parcourues en E2E : catalogue (édition), contenus, paramètres, utilisateurs, livraisons, préparation, espace livreur. Leurs API sont couvertes (PHPUnit) ; leurs écrans sont vérifiés par compilation TypeScript, ESLint et build, mais pas par un parcours navigateur.
- Tests de charge (NFR-002), audit d'accessibilité outillé (axe), restauration de sauvegarde (T-020) : non réalisés.
- T-022 (parcours au clavier seul) : non automatisé.
- Le test E2E crée des commandes dans la base de développement ; elles ont été marquées `is_test = true` (exclues des indicateurs). À l'avenir, lancer l'E2E sur une base dédiée.

## 6. Reproduire

```bash
# Backend (base mk_delices_test)
cd backend && php artisan test && vendor/bin/pint --test

# Frontend
cd frontend && npm test && npm run typecheck && npm run lint && npm run build

# E2E (backend :8000 + worker de file + Vite :5173 démarrés, jeu de démonstration chargé)
cd frontend && E2E_CHANNEL=msedge npx playwright test
```
