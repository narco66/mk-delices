# Journal de développement — MK Délices

## 14/11/2026 — Phase 0 : audit du cadrage et préparation
- **Fait :** lecture des 12 documents, de l'OpenAPI et de la matrice CSV ; inventaire de l'environnement (PHP 8.5.12, Composer 2.10, Node 22.23, PostgreSQL 17 sur 5432 et **18 sur 5433** — retenu : 18/5433).
- **Contradictions relevées :** 15 arbitrages consignés (`DEV-DECISIONS-A-VALIDER.md` §2).
- **Bases créées :** `mk_delices` (développement) et `mk_delices_test` (tests), encodage UTF-8.

## 14/11/2026 — Phase 1 : socle backend
- **Fait :** projet Laravel 13.42 (`backend/`), Sanctum (session SPA), Spatie Permission 8.5 (clés UUID), Intervention Image 4.
- **Migrations :** utilisateurs/sessions (UUID), permissions, catalogue, zones/clients/paramètres, paniers/commandes/événements, paiements/événements/remboursements, livraisons, contenus/avis/notifications/audit/idempotence — **10 migrations, 42 tables**, contraintes CHECK et index partiels, triggers d'immuabilité.
- **Seeders :** rôles/permissions, paramètres (sans écraser les valeurs saisies), contenus éditoriaux (trames juridiques signalées), jeu fictif `local/testing` uniquement.
- **Anomalie :** fuseau de session PostgreSQL `Africa/Lagos` → décalage d'1 h des horodatages ; corrigé (`timezone=UTC`). Les données de démonstration insérées avant la correction conservent ce décalage (fictives, sans incidence).
- **Non suivi :** le gabarit Laravel a ajouté `backend/CLAUDE.md` demandant d'installer Laravel Boost ; outil facultatif, non installé.

## 14/11/2026 — Phases 2 à 5 : domaine métier et API
- **Fait :** services du domaine (catalogue, panier, devis, créneaux, stock, commandes, machine à états, WhatsApp, paiements avec simulateur/Wave/Orange Money, remboursements, livraisons, statistiques, notifications), 101 routes API, ressources par rôle, format d'erreur unique, limitation de débit, en-têtes de sécurité.
- **Tests :** 56 tests PHPUnit écrits ; 1re exécution 42/56 ; corrections : `latestOfMany` (MAX(uuid)), déduplication webhook sans exception, session absente hors SPA, créneau suivant, e-mails de test → **56/56**.

## 14–15/11/2026 — Phases 2 à 6 : interfaces
- **Fait :** SPA React (boutique, panier, commande, paiement, suivi, compte client), back-office (tableau de bord, commandes, préparation, livraisons, livreurs, zones, règlements, paiements, remboursements, catalogue, promotions, avis, contenus, clients, utilisateurs, paramètres, journal) et espace livreur. Les pages back-office et livreur ont été produites par deux sous-agents en parallèle, puis intégrées et vérifiées.
- **Anomalies :** TypeScript 7 incompatible avec typescript-eslint (retour à TS 6) ; `NODE_ENV=production` global sur le poste (dépendances de dev omises) ; icônes de marque retirées de lucide ; règles React 19 (`set-state-in-effect`) → état dérivé ; double copie de React dans le pré-bundle Vite.

## 15/11/2026 — Phase 7 : qualité et recette
- **E2E Playwright (Edge) :** 1re exécution en échec (session perdue après connexion, origine `127.0.0.1`/`localhost` différente lors de la redirection PSP, sélecteurs) → corrections → **10/10**.
- **SEO :** `SpaController` (méta, JSON-LD Product, sitemap, robots) ; anomalie de chargement paresseux et `robots.txt` statique corrigées.
- **Ajouts :** test « smoke » de 36 endpoints avec données multiples, tests unitaires PHP et Vitest, CI GitHub Actions, script de démarrage Windows.
- **Résultats finaux :** PHPUnit **71/71 (629 assertions)**, Vitest 9/9, E2E 10/10, `tsc` et ESLint sans erreur, build OK, audits sans vulnérabilité.
- **Données de développement :** les commandes créées par les tests manuels/E2E ont été marquées `is_test = true`.

## Travaux restants (voir rapport final §15)
Décisions métier/juridiques, contrats PSP, hébergement, sauvegardes, tests de charge, audit d'accessibilité outillé, parcours E2E du back-office, initialisation Git.
