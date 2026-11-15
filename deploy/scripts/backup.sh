#!/usr/bin/env bash
# Sauvegarde chiffrée PostgreSQL + médias (SEC-009, DEPLOYMENT §5).
# - format custom pg_dump (restauration sélective possible), chiffrement asymétrique GPG
#   (la clé PRIVÉE de déchiffrement n'est jamais stockée sur le serveur) ;
# - copie hors serveur via rclone (destination à configurer) ;
# - rotation locale ; échec signalé par code de sortie non nul (à brancher sur la supervision).
set -euo pipefail

: "${BACKUP_DIR:=/var/backups/mk-delices}"
: "${RETENTION_DAYS:=14}"                       # durée locale ; la rétention légale se règle côté stockage distant
: "${GPG_RECIPIENT:?Définir GPG_RECIPIENT (empreinte de la clé publique de sauvegarde)}"
: "${PGHOST:=127.0.0.1}" "${PGPORT:=5432}" "${PGUSER:=mk_backup}" "${PGDATABASE:=mk_delices}"
: "${MEDIA_DIR:=/var/www/mk-delices/backend/storage/app/public}"
: "${RCLONE_REMOTE:=}"                           # ex. « s3-mk:mk-delices-backups » ; vide = pas de copie distante (déconseillé)

stamp="$(date -u +%Y%m%dT%H%M%SZ)"
mkdir -p "$BACKUP_DIR"
umask 077

db_file="$BACKUP_DIR/db-$stamp.dump.gpg"
media_file="$BACKUP_DIR/media-$stamp.tar.gz.gpg"

# Le mot de passe est lu depuis ~/.pgpass (droits 600), jamais passé en argument.
pg_dump --format=custom --no-owner --no-privileges "$PGDATABASE" \
  | gpg --batch --yes --encrypt --recipient "$GPG_RECIPIENT" --output "$db_file"
tar -C "$MEDIA_DIR" -czf - . | gpg --batch --yes --encrypt --recipient "$GPG_RECIPIENT" --output "$media_file"

sha256sum "$db_file" "$media_file" > "$BACKUP_DIR/SHA256SUMS-$stamp"

if [[ -n "$RCLONE_REMOTE" ]]; then
  rclone copy "$BACKUP_DIR" "$RCLONE_REMOTE" --include "*-$stamp*"
fi

find "$BACKUP_DIR" -type f -mtime +"$RETENTION_DAYS" -delete
echo "$(date -u +%FT%TZ) sauvegarde OK : $(basename "$db_file"), $(basename "$media_file")"
