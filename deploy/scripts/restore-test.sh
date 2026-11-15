#!/usr/bin/env bash
# Test de restauration (T-020) — À EXÉCUTER SUR UNE MACHINE ISOLÉE, JAMAIS SUR LA PRODUCTION.
# Restaure une sauvegarde dans une base jetable, vérifie l'intégrité et mesure la durée (RTO).
# Usage : restore-test.sh /chemin/db-AAAAMMJJTHHMMSSZ.dump.gpg
set -euo pipefail

dump="${1:?Chemin du fichier db-*.dump.gpg}"
target_db="mk_restore_test_$(date +%s)"
: "${PGHOST:=127.0.0.1}" "${PGPORT:=5432}" "${PGUSER:=postgres}"

if [[ "${PGHOST}" != "127.0.0.1" && "${PGHOST}" != "localhost" && "${ALLOW_REMOTE_RESTORE:-no}" != "yes" ]]; then
  echo "Refus : restauration de test uniquement sur un PostgreSQL local (ALLOW_REMOTE_RESTORE=yes pour forcer)." >&2
  exit 1
fi

start=$(date +%s)
dir="$(cd "$(dirname "$dump")" && pwd)"
stamp="$(basename "$dump" | sed -E 's/^db-(.+)\.dump\.gpg$/\1/')"
# Intégrité obligatoire : une sauvegarde altérée ne doit jamais être restaurée.
(cd "$dir" && sha256sum --check "SHA256SUMS-$stamp")
media="$dir/media-$stamp.tar.gz.gpg"
if [[ -f "$media" ]]; then
  gpg --batch --decrypt "$media" | tar -tzf - > /dev/null && echo "Archive des médias lisible : $(basename "$media")"
else
  echo "Avertissement : archive des médias absente pour $stamp" >&2
fi
createdb "$target_db"
# La clé privée GPG est importée temporairement sur la machine de test uniquement.
gpg --batch --decrypt "$dump" | pg_restore --no-owner --no-privileges --dbname "$target_db"

# Contrôles : tables clés présentes, journaux d'audit lisibles, cohérence des montants.
psql -d "$target_db" -v ON_ERROR_STOP=1 -At <<'SQL'
SELECT 'commandes', count(*) FROM orders;
SELECT 'paiements', count(*) FROM payments;
SELECT 'evenements', count(*) FROM order_events;
SELECT 'incoherences_montants', count(*) FROM orders WHERE total_xof <> subtotal_xof - discount_xof + delivery_fee_xof;
SQL

duration=$(( $(date +%s) - start ))
echo "Restauration OK dans $target_db en ${duration}s (objectif RTO ≤ 8 h). Consigner le résultat dans docs/DEV-TESTS.md."
echo "Supprimer ensuite la base : dropdb $target_db"
