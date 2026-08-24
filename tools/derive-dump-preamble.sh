#!/usr/bin/env bash
#
# DERIVE THE DUMP PREAMBLE FROM A REAL mysqldump. NEVER HAND-TYPE IT.
#
# WHY. The plugin's PHP dumper writes its own SQL. Its first line was `SET FOREIGN_KEY_CHECKS=0;` and
# then straight into CREATE TABLE, so every session setting a real dump establishes was simply absent:
#
#   * the session charset, so the import ran at whatever the destination's client default happened to
#     be (measured on cp1: utf8mb3), which is the ERROR 1366 an emoji in a post produces;
#   * TIME_ZONE, which matters more than it looks. Without `SET TIME_ZONE='+00:00'` every TIMESTAMP
#     column is re-interpreted in the destination's offset, so a migrated site's posts silently change
#     date and nobody notices until a customer asks why;
#   * SQL_MODE, FOREIGN_KEY_CHECKS, UNIQUE_CHECKS and SQL_NOTES, which decide whether the import
#     tolerates the ordering and the AUTO_INCREMENT zero values a real dump relies on.
#
# HAND-TYPING THIS LIST IS THE DEFECT, NOT THE FIX. A hand-typed sed missing the COLLATE rules already
# invented an error that was not real on this exact job, and a hand-typed preamble drifts the moment
# the server version changes: measured here, MariaDB's mysqldump emits NINE session lines with
# `--no-data --no-create-info` and TEN with its default flags, the difference being UNIQUE_CHECKS. A
# list typed from memory would have been wrong about which, and nothing would have said so.
#
# So this runs a REAL mysqldump against a REAL table and captures what it actually emits. The generated
# artefacts are committed, and `verify-dump-preamble.sh` re-runs this and refuses if they differ.
#
# usage:  tools/derive-dump-preamble.sh [--via-ssh <host>]
#
# The portal box has no MySQL server, so `--via-ssh cp1-kapsule` is the normal invocation there. The
# credential is taken from MYSQL_PWD or from the portal env, and is never printed.

set -euo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VIA_SSH=""
if [ "${1:-}" = "--via-ssh" ]; then VIA_SSH="${2:?--via-ssh needs a host}"; fi

PROBE_DB="kapsule_preamble_probe"

# The probe MUST contain a table with a row. mysqldump suppresses the UNIQUE_CHECKS pair entirely when
# there is no data section, so a probe dumped with --no-data captures a preamble that is missing a line
# the real thing emits. That is exactly the drift this script exists to prevent, so the probe is built
# rather than assumed.
SQL_BUILD="DROP DATABASE IF EXISTS \`${PROBE_DB}\`;
CREATE DATABASE \`${PROBE_DB}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE \`${PROBE_DB}\`;
CREATE TABLE probe (id int NOT NULL AUTO_INCREMENT, v varchar(16) NOT NULL, PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO probe (v) VALUES ('x');"

run_remote() {
  # $1 = shell command to run where mysqldump lives
  if [ -n "$VIA_SSH" ]; then
    local pw="${MYSQL_PWD:-}"
    if [ -z "$pw" ] && [ -r /var/www/kapsulecloud-portal/.env.local ]; then
      pw="$(grep -m1 '^DATABASE_ROOT_PASSWORD=' /var/www/kapsulecloud-portal/.env.local \
            | sed 's/^DATABASE_ROOT_PASSWORD=//' | sed 's/^"//;s/"$//' | sed "s/^'//;s/'$//")"
    fi
    [ -n "$pw" ] || { echo "no MySQL credential available" >&2; exit 1; }
    ssh "$VIA_SSH" "MYSQL_PWD='${pw}' $1"
  else
    bash -c "$1"
  fi
}

echo "Building probe database..." >&2
printf '%s' "$SQL_BUILD" | run_remote "mysql -h127.0.0.1 -uroot" >/dev/null

echo "Capturing mysqldump output (default flags)..." >&2
RAW="$(run_remote "mysqldump -h127.0.0.1 -uroot --default-character-set=utf8mb4 ${PROBE_DB}")"

run_remote "mysql -h127.0.0.1 -uroot -e 'DROP DATABASE IF EXISTS \`${PROBE_DB}\`'" >/dev/null

# The functional preamble is the run of session statements BEFORE the first DDL, and the epilogue is
# the run AFTER the last one. Extracted by shape, not by a remembered line count, so a server that
# emits one more or one fewer is captured correctly rather than silently truncated to what fits.
PREAMBLE="$(printf '%s\n' "$RAW" | awk '/^\/\*![0-9]+ SET/ { print; next } /^(DROP|CREATE|LOCK|INSERT|UNLOCK|\/\*!40000)/ { exit }')"
EPILOGUE="$(printf '%s\n' "$RAW" | awk '/^UNLOCK TABLES;/ { seen=1; next } seen && /^\/\*![0-9]+ SET/ { print }')"

if [ -z "$PREAMBLE" ] || [ -z "$EPILOGUE" ]; then
  echo "REFUSING: captured an empty preamble or epilogue. mysqldump output was not the expected shape." >&2
  printf '%s\n' "$RAW" | head -30 >&2
  exit 1
fi

# A preamble that does not establish the charset or the time zone is not the thing this exists to
# capture, whatever mysqldump printed. Assert the two settings the customer's data depends on, so a
# silently-different mysqldump cannot generate a well-formed but useless artefact.
printf '%s\n' "$PREAMBLE" | grep -q 'SET NAMES' \
  || { echo "REFUSING: captured preamble sets no charset." >&2; exit 1; }
printf '%s\n' "$PREAMBLE" | grep -q "SET TIME_ZONE='+00:00'" \
  || { echo "REFUSING: captured preamble sets no time zone." >&2; exit 1; }

mkdir -p "$REPO/includes"

# ---- artefact 1: the raw captured text, which is what both generators read -------------------------
{
  echo "-- GENERATED by tools/derive-dump-preamble.sh from a real mysqldump. Do not edit."
  echo "-- @preamble"
  printf '%s\n' "$PREAMBLE"
  echo "-- @epilogue"
  printf '%s\n' "$EPILOGUE"
} > "$REPO/includes/dump-preamble.sql"

# ---- artefact 2: the PHP the plugin's writer emits -------------------------------------------------
{
  echo "<?php"
  echo "/**"
  echo " * GENERATED by tools/derive-dump-preamble.sh from a real mysqldump. DO NOT EDIT BY HAND."
  echo " *"
  echo " * Re-derive with:   tools/derive-dump-preamble.sh --via-ssh cp1-kapsule"
  echo " * Verified in CI by: tools/verify-dump-preamble.sh"
  echo " *"
  echo " * These are the session statements a real mysqldump wraps its output in. The plugin's own"
  echo " * writer emitted none of them, so an imported site could arrive with its timestamps shifted by"
  echo " * the destination's UTC offset and its four-byte characters rejected outright."
  echo " */"
  echo ""
  echo "class Kapsule_Dump_Preamble {"
  echo ""
  echo "    public static function preamble(): string {"
  echo "        return implode( \"\\n\", array("
  printf '%s\n' "$PREAMBLE" | sed "s/'/\\\\'/g; s/^/            '/; s/$/',/"
  echo "        ) ) . \"\\n\";"
  echo "    }"
  echo ""
  echo "    public static function epilogue(): string {"
  echo "        return implode( \"\\n\", array("
  printf '%s\n' "$EPILOGUE" | sed "s/'/\\\\'/g; s/^/            '/; s/$/',/"
  echo "        ) ) . \"\\n\";"
  echo "    }"
  echo "}"
} > "$REPO/includes/class-dump-preamble.php"

echo "Wrote includes/dump-preamble.sql and includes/class-dump-preamble.php" >&2
printf '%s\n' "$PREAMBLE" | sed 's/^/  preamble: /' >&2
printf '%s\n' "$EPILOGUE" | sed 's/^/  epilogue: /' >&2
