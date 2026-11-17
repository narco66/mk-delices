#!/usr/bin/env bash
# Sauvegarde chiffrée PostgreSQL + médias (SEC-009, DEPLOYMENT §5).
# - format custom pg_dump (restauration sélective possible), chiffrement asymétrique GPG
#   (la clé PRIVÉE de déchiffrement n'est jamais stockée sur le serveur) ;
# - copie hors serveur via rclone (OBLIGATOIRE, sauf ALLOW_LOCAL_ONLY=1 sur un poste de test) ;
# - rotation locale ; succès signalé à une sonde externe (HEARTBEAT_URL), échec = code de sortie non nul.
#
# Configuration : /etc/mk-delices/backup.env (root:root, 600), modèle dans deploy/backup.env.example.
# Exécuté par root (cron) uniquement : il lit ce fichier, ~root/.pgpass et le trousseau GPG de root.
set -euo pipefail

CONFIG_FILE="${MK_BACKUP_ENV:-/etc/mk-delices/backup.env}"
if [[ -f "$CONFIG_FILE" ]]; then
  # shellcheck disable=SC1090
  set -a; . "$CONFIG_FILE"; set +a
fi

: "${BACKUP_DIR:=/var/backups/mk-delices}"
: "${RETENTION_DAYS:=14}"                       # durée locale ; la rétention légale se règle côté stockage distant
: "${GPG_RECIPIENT:?Définir GPG_RECIPIENT dans $CONFIG_FILE (empreinte de la clé publique de sauvegarde)}"
: "${PGHOST:=127.0.0.1}" "${PGPORT:=5432}" "${PGUSER:=mk_backup}" "${PGDATABASE:=mk_delices}"
: "${MEDIA_DIR:=/var/www/mk-delices/backend/storage/app/public}"
: "${RCLONE_REMOTE:=}"                           # ex. « s3-mk:mk-delices-backups »
: "${ALLOW_LOCAL_ONLY:=0}"
: "${HEARTBEAT_URL:=}"                           # ex. URL de ping Healthchecks.io / UptimeRobot (alerte si absente)
export PGHOST PGPORT PGUSER PGDATABASE

if [[ -z "$RCLONE_REMOTE" && "$ALLOW_LOCAL_ONLY" != "1" ]]; then
  echo "$(date -u +%FT%TZ) ÉCHEC : RCLONE_REMOTE non défini — une sauvegarde restée sur le serveur ne protège pas d'une perte du serveur." >&2
  exit 2
fi

stamp="$(date -u +%Y%m%dT%H%M%SZ)"
umask 077
mkdir -p "$BACKUP_DIR"

db_file="$BACKUP_DIR/db-$stamp.dump.gpg"
media_file="$BACKUP_DIR/media-$stamp.tar.gz.gpg"
# Clé publique importée sans signature de confiance : --trust-model always évite « Unusable public key ».
gpg_encrypt=(gpg --batch --yes --trust-model always --encrypt --recipient "$GPG_RECIPIENT")

# Le mot de passe est lu depuis ~/.pgpass (droits 600), jamais passé en argument.
pg_dump --format=custom --no-owner --no-privileges "$PGDATABASE" | "${gpg_encrypt[@]}" --output "$db_file"
tar -C "$MEDIA_DIR" -czf - . | "${gpg_encrypt[@]}" --output "$media_file"

# Sommes de contrôle avec des noms relatifs : vérifiables après téléchargement ailleurs (restore-test.sh).
(cd "$BACKUP_DIR" && sha256sum "$(basename "$db_file")" "$(basename "$media_file")" > "SHA256SUMS-$stamp")

if [[ -n "$RCLONE_REMOTE" ]]; then
  rclone copy "$BACKUP_DIR" "$RCLONE_REMOTE" --include "*-$stamp*"
fi

find "$BACKUP_DIR" -type f -mtime +"$RETENTION_DAYS" -delete
echo "$(date -u +%FT%TZ) sauvegarde OK : $(basename "$db_file"), $(basename "$media_file")"

# Horodatage lisible par l'application (contrôle « backup » de /health, alerte si > 26 h).
: "${STATUS_FILE:=/var/lib/mk-delices/last-backup}"
mkdir -p "$(dirname "$STATUS_FILE")" && date -u +%s > "$STATUS_FILE" && chmod 644 "$STATUS_FILE"

if [[ -n "$HEARTBEAT_URL" ]]; then
  curl -fsS -m 10 --retry 3 "$HEARTBEAT_URL" > /dev/null || echo "Avertissement : sonde de supervision injoignable" >&2
fi
