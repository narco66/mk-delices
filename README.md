# MK Délices — plateforme e-commerce

Boutique en ligne, commande WhatsApp, paiement, préparation et livraison pour **MK Délices**, pâtisserie artisanale à Dakar (spécialité : tiramisu).

- `backend/` — API REST Laravel 13 + PostgreSQL
- `frontend/` — SPA React 19 / TypeScript / Vite (boutique, back-office, espace livreur)
- `docs/` — dossier de cadrage (01 à 13), documentation de développement (`DEV-*.md`, `RAPPORT-FINAL-…`) et **guides utilisateurs** (`docs/guides/`)
- `deploy/` — configuration de production prête à adapter (Nginx, Supervisor, cron, sauvegarde/restauration, script de déploiement, modèle `.env`)

> **État :** application prête pour une recette technique **avec données fictives**. Catalogue, prix, zones, numéro WhatsApp, prestataires de paiement et textes juridiques restent à fournir par MK Délices (`docs/DEV-DECISIONS-A-VALIDER.md`). Aucun paiement réel n'est possible tant qu'un contrat marchand n'est pas configuré.

## Prérequis

| Outil | Version testée |
|---|---|
| PHP | ≥ 8.3 (testé 8.5.12) avec `pdo_pgsql`, `gd`, `intl`, `mbstring`, `fileinfo`, `zip` |
| Composer | 2.x |
| PostgreSQL | 17 ou 18 (testé 18.4) |
| Node.js / npm | 22.x / 10.x |

## Installation (Windows / Laragon)

```powershell
# 1. Bases de données (adapter port et utilisateur à votre serveur PostgreSQL)
psql -h 127.0.0.1 -p 5433 -U postgres -c "CREATE DATABASE mk_delices ENCODING 'UTF8' TEMPLATE template0;"
psql -h 127.0.0.1 -p 5433 -U postgres -c "CREATE DATABASE mk_delices_test ENCODING 'UTF8' TEMPLATE template0;"

# 2. Backend
cd backend
composer install
copy .env.example .env          # renseigner DB_PORT, DB_PASSWORD ; en local : PAYMENTS_SIMULATOR_ENABLED=true + un secret
php artisan key:generate
php artisan migrate
php artisan db:seed             # rôles, paramètres, contenus ; + jeu FICTIF en local/testing
php artisan storage:link

# 3. Frontend
cd ..\frontend
copy .env.example .env
npm ci --include=dev            # --include=dev indispensable si NODE_ENV=production est défini sur le poste
```

## Démarrage

```powershell
powershell -ExecutionPolicy Bypass -File scripts\start-dev.ps1
```
ou manuellement : `php artisan serve` (8000), `php artisan queue:work` (indispensable pour confirmer les paiements), `npx vite` (5173).

| URL | Usage |
|---|---|
| http://localhost:5173 | Boutique (utiliser `localhost`, valeur de `FRONTEND_URL`) |
| http://localhost:5173/admin | Back-office |
| http://localhost:5173/livreur | Espace livreur |

## Comptes de démonstration (local/testing uniquement)

Mot de passe commun : `Demo-MKD-2026`

| Email | Rôle |
|---|---|
| admin@mk-delices.test | Administrateur |
| commercial@mk-delices.test | Gestionnaire commercial |
| preparateur@mk-delices.test | Préparateur |
| livraison@mk-delices.test | Responsable des livraisons |
| livreur1@mk-delices.test, livreur2@mk-delices.test | Livreurs partenaires |
| client1@mk-delices.test, client2@…, client3@… | Clients avec compte |

Données de démonstration (≈ 80 commandes sur 30 jours dans tous les états, paiements, remboursements, livraisons, incidents, avis) et parcours de découverte : **`docs/ACCES-DEMONSTRATION.md`**.

Le seeder de démonstration refuse de s'exécuter hors `local`/`testing`. En production : `php artisan mk:create-admin`.

## Paiement de test

Avec `PAYMENTS_SIMULATOR_ENABLED=true` (local uniquement), le moyen « Paiement simulé » apparaît au checkout : il redirige vers un écran prestataire fictif qui envoie un **webhook signé**, traité par la file d'attente puis vérifié auprès du « prestataire ». Aucun débit réel.

## Tests

```bash
cd backend  && php artisan test            # 74 tests, PostgreSQL mk_delices_test
cd backend  && vendor/bin/pint --test      # style PSR-12
cd frontend && npm test                    # Vitest
cd frontend && npm run typecheck && npm run lint && npm run build
cd frontend && E2E_CHANNEL=msedge npx playwright test   # serveurs démarrés ; commandes de test marquées automatiquement
```
Résultats détaillés : `docs/DEV-TESTS.md`.

## Commandes utiles

| Commande | Rôle |
|---|---|
| `php artisan mk:create-admin` | Créer un administrateur (mot de passe saisi, jamais par défaut) |
| `php artisan mk:reconcile-payments` | Rapprocher les paiements en attente (planifié toutes les 5 min) |
| `php artisan mk:purge-expired` | Purger paniers expirés et clés d'idempotence (planifié quotidiennement) |
| `php artisan schedule:work` | Exécuter le planificateur en local |
| `php artisan mk:import-catalogue <csv> [--publish] [--dry-run]` | Importer le catalogue (brouillons par défaut) |
| `php artisan mk:import-content <slug> <fichier> [--validated]` | Charger un texte validé dans une page du site |
| `php artisan db:seed --class=LaunchConfigurationSeeder` | Appliquer la configuration de lancement validée (zones, horaires, règles) |
| `php artisan db:seed --class=DemoActivitySeeder` | Générer l'activité de démonstration (local/testing uniquement) |
| `php artisan mk:flag-test-orders` | Marquer `is_test` les commandes créées par les tests E2E (hors production) |

## Déploiement

Non exécuté (hébergeur à choisir). Fichiers prêts dans `deploy/` :

| Fichier | Rôle |
|---|---|
| `deploy/nginx/mk-delices.conf` | HTTPS, en-têtes de sécurité et CSP, cache des assets, routage API/SPA, journaux sans chaîne de requête |
| `deploy/supervisor/mk-delices-worker.conf` | Worker de file d'attente (paiements, emails) |
| `deploy/cron/mk-delices` | Planificateur Laravel + sauvegarde quotidienne |
| `deploy/scripts/backup.sh` | Sauvegarde PostgreSQL + médias chiffrée (GPG), copie distante (rclone), rotation |
| `deploy/scripts/restore-test.sh` | Test de restauration sur base jetable (T-020, mesure du RTO) |
| `deploy/scripts/deploy.sh` | Déploiement : sauvegarde, migrations, build, caches, tests de fumée |
| `deploy/env.production.example` | Modèle de configuration de production (sans secret) |

Topologie et variables : `docs/DEV-ARCHITECTURE.md` §4 et `docs/11-DEPLOYMENT/`. La CI (`.github/workflows/ci.yml`) exécute lint, types, tests PostgreSQL, build et audits.

## Guides utilisateurs

`docs/guides/` : administrateur, gestionnaire commercial, préparateur, responsable des livraisons, livreur.

## Limitations connues

- Wave : adaptateur écrit d'après la documentation publique, **jamais exécuté contre un compte réel** ; Orange Money et carte : non implémentés (contrats/spécifications requis).
- Notifications client par email uniquement (`MAIL_MAILER=log` en dev) ; SMS / WhatsApp automatisés non branchés.
- Points de fidélité, suivi GPS et commandes événementielles : hors MVP.
