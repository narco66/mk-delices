#!/usr/bin/env bash
# Déploiement applicatif (DEPLOYMENT §3) — à lancer sur le serveur depuis /var/www/mk-delices.
# Pré-requis : sauvegarde récente vérifiée, .env de production en place (hors Git), CI verte sur le commit.
# Usage : deploy/scripts/deploy.sh <tag-ou-commit>
set -euo pipefail

ref="${1:?Indiquer le tag ou commit à déployer (jamais une branche mobile)}"
app=/var/www/mk-delices
cd "$app"

echo "1/8 Sauvegarde préalable"
"$app/deploy/scripts/backup.sh"

echo "2/8 Récupération du code ($ref)"
git fetch --tags origin
git checkout --detach "$ref"

echo "3/8 Dépendances backend (sans outils de développement)"
cd backend
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
composer audit

echo "4/8 Mode maintenance + migrations additives"
php artisan down --retry=30
trap 'php artisan up' EXIT
php artisan migrate --force          # migrations expand/contract uniquement (jamais destructives)
php artisan db:seed --class=RolesAndPermissionsSeeder --force
php artisan db:seed --class=SettingsSeeder --force   # n'écrase jamais les valeurs saisies

echo "5/8 Build frontend"
cd ../frontend
npm ci --include=dev
npm run build
npm audit --omit=dev --audit-level=high

echo "6/8 Caches Laravel"
cd ../backend
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan storage:link || true

echo "7/8 Redémarrage des workers"
php artisan queue:restart
sudo systemctl reload php8.4-fpm

php artisan up
trap - EXIT

echo "8/8 Tests de fumée"
base="${APP_URL:-https://exemple.sn}"
curl -fsS "$base/up" > /dev/null
curl -fsS "$base/api/v1/config" > /dev/null
curl -fsS "$base/api/v1/catalog/products?per_page=1" > /dev/null
curl -fsS "$base/" | grep -q '<title>'
echo "Déploiement de $ref terminé. Surveiller les journaux 30 minutes (5xx, jobs en échec, paiements en attente)."
