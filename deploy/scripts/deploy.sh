#!/usr/bin/env bash
# Déploiement applicatif (DEPLOYMENT §3) — à lancer sur le serveur par l'utilisateur de déploiement
# (membre du groupe www-data, sudo limité à : systemctl reload phpX.Y-fpm, /usr/local/sbin/mk-backup).
#
# Usage : deploy/scripts/deploy.sh <tag-ou-commit>
# Paramètres (variables d'environnement) — permettent de séparer préproduction et production (audit E7) :
#   APP_DIR    dossier de l'application        (défaut /var/www/mk-delices ; préproduction : /var/www/mk-delices-staging)
#   PHP_FPM    service PHP-FPM à recharger     (défaut php8.4-fpm)
#   BASE_URL   URL publique pour les tests de fumée (défaut : APP_URL lu dans la configuration Laravel)
#   SMOKE_AUTH identifiants « utilisateur:motdepasse » si le site est protégé (préproduction)
#   SKIP_BACKUP=1 pour un premier déploiement (base vide)
#
# Principes (audit B4) :
#  - tout ce qui peut échouer sans toucher au site (dépendances, build) est fait AVANT la maintenance ;
#  - en cas d'échec APRÈS la mise en maintenance, le site RESTE en maintenance (jamais remis en ligne
#    dans un état incohérent) ; on corrige puis on relance, ou on revient au tag précédent ;
#  - le tag précédent est mémorisé (.deployed-ref) pour un retour arrière : deploy.sh <tag précédent>.
set -euo pipefail

ref="${1:?Indiquer le tag ou commit à déployer (jamais une branche mobile)}"
APP_DIR="${APP_DIR:-/var/www/mk-delices}"
PHP_FPM="${PHP_FPM:-php8.4-fpm}"
artisan=(php "$APP_DIR/backend/artisan")
cd "$APP_DIR"

previous="$(cat .deployed-ref 2>/dev/null || git rev-parse --short HEAD)"
echo "Déploiement de $ref dans $APP_DIR (version actuelle : $previous)"

echo "1/9 Sauvegarde préalable"
if [[ "${SKIP_BACKUP:-0}" != "1" ]]; then
  sudo /usr/local/sbin/mk-backup   # enveloppe root de deploy/scripts/backup.sh (lit /etc/mk-delices/backup.env)
fi

echo "2/9 Récupération du code ($ref) dans une copie de travail temporaire"
git fetch --tags origin
git worktree prune
build_dir="$(mktemp -d)"
trap 'rm -rf "$build_dir"' EXIT
git worktree add --detach "$build_dir" "$ref" > /dev/null

echo "3/9 Build du frontend hors ligne (le site actuel continue de servir l'ancienne version)"
(cd "$build_dir/frontend" && npm ci --include=dev --no-audit --no-fund && npm run build)

echo "4/9 Dépendances backend vérifiées (sans toucher au site)"
(cd "$build_dir/backend" && composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-scripts > /dev/null)

echo "5/9 Mise en maintenance"
"${artisan[@]}" down --retry=30
on_failure() {
  echo "ÉCHEC après mise en maintenance : le site reste en maintenance." >&2
  echo "Corriger puis relancer, ou revenir à la version précédente : $0 $previous" >&2
  rm -rf "$build_dir"
}
trap on_failure ERR

echo "6/9 Bascule du code, dépendances et migrations additives"
git checkout --detach "$ref"
rm -rf frontend/dist.new && mkdir -p frontend/dist.new && cp -r "$build_dir/frontend/dist/." frontend/dist.new/
# Conserver les anciens fichiers JS/CSS (noms hachés) : les onglets ouverts continuent de fonctionner.
if [[ -d frontend/dist/assets ]]; then cp -rn frontend/dist/assets/. frontend/dist.new/assets/; fi
rm -rf frontend/dist.old
if [[ -d frontend/dist ]]; then mv frontend/dist frontend/dist.old; fi   # conservé pour un retour arrière rapide
mv frontend/dist.new frontend/dist
(cd backend && composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction)
"${artisan[@]}" migrate --force          # migrations expand/contract uniquement (jamais destructives)
"${artisan[@]}" db:seed --class=RolesAndPermissionsSeeder --force
"${artisan[@]}" db:seed --class=SettingsSeeder --force   # n'écrase jamais les valeurs saisies

echo "7/9 Caches et permissions"
"${artisan[@]}" optimize                  # config, routes, vues, événements
"${artisan[@]}" storage:link 2>/dev/null || true
# PHP-FPM et le worker (www-data) doivent pouvoir écrire, quel que soit l'utilisateur qui déploie.
chgrp -R www-data backend/storage backend/bootstrap/cache
chmod -R g+rwX backend/storage backend/bootstrap/cache
find backend/storage backend/bootstrap/cache -type d -exec chmod g+s {} +

echo "8/9 Redémarrage des workers et remise en ligne"
"${artisan[@]}" queue:restart
sudo systemctl reload "$PHP_FPM"
"${artisan[@]}" up
trap - ERR
echo "$ref" > .deployed-ref
git worktree remove --force "$build_dir" 2>/dev/null || true

echo "9/9 Tests de fumée"
base="${BASE_URL:-$("${artisan[@]}" tinker --execute 'echo config("app.url");' 2>/dev/null | tail -1)}"
curl_opts=(-fsS --max-time 20)
[[ -n "${SMOKE_AUTH:-}" ]] && curl_opts+=(-u "$SMOKE_AUTH")
curl "${curl_opts[@]}" "$base/up" > /dev/null
curl "${curl_opts[@]}" "$base/api/v1/config" > /dev/null
curl "${curl_opts[@]}" "$base/api/v1/catalog/products?per_page=1" > /dev/null
curl "${curl_opts[@]}" "$base/" | grep -q '<title>'
echo "Déploiement de $ref terminé sur $base (précédent : $previous). Surveiller les journaux 30 minutes (5xx, jobs en échec, paiements en attente)."
