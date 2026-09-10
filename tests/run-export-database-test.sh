#!/bin/bash
# Provision a throwaway MySQL user and databases, then run the exporter's both-arms proof.
#
#   tests/run-export-database-test.sh                 # run both arms against the working tree
#   KM_KEEP=1 tests/run-export-database-test.sh       # leave the databases behind for inspection
#
# Everything it creates is prefixed km_r505_ and is dropped again on the way out. It never touches a
# database it did not create, and it refuses rather than guessing if it cannot reach a server.
set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PREFIX="km_r505_"
DBUSER="km_r505_test"
DBPASS="$(head -c 18 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 20)"

# The admin channel. `sudo mysql` is unix-socket root on this box; a server that answers neither
# way is a BLIND run, not a green one.
admin() { sudo mysql -N -B -e "$1"; }

if ! admin "SELECT 1" >/dev/null 2>&1; then
    echo "BLIND: no admin access to a local MySQL/MariaDB server (tried: sudo mysql)." >&2
    echo "       The exporter proof needs a real server; it cannot be simulated." >&2
    exit 2
fi

SERVER_VERSION="$(admin "SELECT VERSION()")"
echo "[proof] server: ${SERVER_VERSION}"

admin "DROP USER IF EXISTS '${DBUSER}'@'localhost';"
admin "CREATE USER '${DBUSER}'@'localhost' IDENTIFIED BY '${DBPASS}';"
admin "GRANT ALL PRIVILEGES ON \`${PREFIX}%\`.* TO '${DBUSER}'@'localhost';"
admin "FLUSH PRIVILEGES;"

CNF="$(mktemp /tmp/km-r505-cnf-XXXXXX)"
chmod 600 "$CNF"
cat > "$CNF" <<EOF
[client]
host=127.0.0.1
port=3306
user=${DBUSER}
password=${DBPASS}
EOF

cleanup() {
    rm -f "$CNF"
    if [ "${KM_KEEP:-0}" != "1" ]; then
        for suffix in src_view dst_view src_plain dst_plain; do
            admin "DROP DATABASE IF EXISTS \`${PREFIX}${suffix}\`;" >/dev/null 2>&1
        done
        admin "DROP USER IF EXISTS '${DBUSER}'@'localhost';" >/dev/null 2>&1
    else
        echo "[proof] KM_KEEP=1: databases and user ${DBUSER} left in place"
    fi
}
trap cleanup EXIT

KM_DB_HOST=127.0.0.1 \
KM_DB_PORT=3306 \
KM_DB_USER="${DBUSER}" \
KM_DB_PASS="${DBPASS}" \
KM_DB_PREFIX="${PREFIX}" \
KM_DB_CNF="${CNF}" \
php "${HERE}/test-export-database.php"
RC=$?

exit $RC
