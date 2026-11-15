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

## 15/11/2026 — Phase 7 (suite) : back-office, accessibilité, images
- **Dépôt Git** initialisé (`main`, commit `37ff8d2`) et publié sur `github.com/narco66/mk-delices`.
- **Images (SEC-004) :** 3 tests (ré-encodage WebP vérifié par signature binaire, script déguisé refusé, dimensions, droits, suppression).
- **E2E back-office :** scénarios D+E dans le navigateur avec 5 sessions distinctes (client, commercial, préparateur, responsable, livreur 360 px). Commande `mk:flag-test-orders` + `global-teardown` pour marquer automatiquement les commandes de test.
- **Accessibilité :** audit axe-core WCAG 2.2 AA sur 10 écrans → corrections des contrastes (`caramel-600`, nouveau jeton `cocoa-400`) et du nom accessible du logo ; commande au clavier seul (T-022).
- **UX :** bouton « Confirmer » désactivé quand un blocage est affiché (minimum de commande, zone…), avec explication reliée.
- **Résultats :** PHPUnit 74/74 (658 assertions), Vitest 9/9, Playwright 32/32, axe 0 violation serious/critical.

## 15/11/2026 — Phases 7 et 8 : fin de recette technique, préparation du déploiement
- **E2E administration :** catalogue (création, photo, publication, désactivation), promotions, contenus, utilisateurs (création puis désactivation en direct), paramètres, paiements, remboursement en double contrôle, scénario F avec règlement du livreur. Sessions de démonstration ouvertes une fois par campagne (`auth.setup.ts`).
- **Défauts corrigés :** page de retour de paiement après remboursement, débordement mobile de la fiche produit, `setState` dans un effet de l'éditeur de pages ; données de test restées publiques après une campagne interrompue, neutralisées.
- **Déploiement :** dossier `deploy/` (Nginx, Supervisor, cron, sauvegarde chiffrée, test de restauration, script de déploiement, modèle `.env`).
- **Guides utilisateurs :** `docs/guides/` (5 rôles).
- **Emails :** envoi SMTP vérifié de bout en bout avec Mailpit.
- **Qualité du code :** ESLint 0 avertissement (`useWatch`, modules de libellés dédiés).
- **Résultats :** PHPUnit 74/74, Vitest 9/9, Playwright 47/47.

## 15/11/2026 — Validation des propositions par la direction
- **Décision :** la direction valide les propositions (catalogue, prix, zones, horaires, paiement Wave, règles de livraison, projets juridiques sur le fond).
- **Chargement (base de développement, après sauvegarde `pg_dump`) :** catalogue de 8 produits publié (`mk:import-catalogue --publish`), `LaunchConfigurationSeeder` (4 zones, horaires mardi→dimanche 10 h–20 h, règles opérationnelles, code BIENVENUE10 inactif, données de démonstration retirées de la vente sans suppression), textes juridiques chargés en restant marqués « en attente de validation ».
- **Tests :** `LaunchConfigurationTest` (configuration, idempotence, catalogue visible = catalogue validé).
- **Reste :** contrat Wave, photos, vérification des allergènes sur étiquettes, devis livreurs, juriste et CDP, évolution « 50 % de la course échouée ».

## 15/11/2026 — Règle « course échouée à 50 % » et données de démonstration
- **Règle validée et implémentée :** course échouée rémunérée à 50 % si le motif est imputable au client (paramètres `delivery.failed_fee_percent`, `delivery.client_fault_reasons`) ; montants dus, règlements, espace livreur et écran « Règlements » mis à jour ; tests PHPUnit dédiés.
- **Données de démonstration :** `DemoActivitySeeder` (vrais services, voyage dans le temps contrôlé, graine fixe, local/testing uniquement) — ≈ 80 commandes sur 30 jours, tous les états, paiements, remboursements, missions, incidents, avis, règlements ; 3 comptes clients. Test `DemoActivityTest` (invariants métier, idempotence).
- **Anomalies corrigées pendant la génération :** dérive des dates due au voyage dans le temps, chargement paresseux en mode strict, dépassement du maximum par commande ; une première génération interrompue a été exclue (`is_test`) sans suppression.
- **Accès :** `docs/ACCES-DEMONSTRATION.md`.

## Travaux restants (voir rapport final §15)
Décisions métier/juridiques, contrats PSP, hébergement, sauvegardes, tests de charge, test avec lecteur d'écran, exécution réelle du déploiement et du test de restauration une fois l'hébergeur choisi.
