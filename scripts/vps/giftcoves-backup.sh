#!/bin/sh
# Nightly backup of GiftCoves production (owner, 2026-11-25: "Yes, but delete the previous after
# quality check"). Installed on the VPS as /root/bin/giftcoves-backup.sh, run by the systemd timer
# giftcoves-backup.timer at 02:15 UTC. Source of truth is this file in the repo:
# docs/deployment.md, "Backups".
#
# Until that day nothing backed this database up: Coolify's only scheduled backup is of Coolify.
#
# What it keeps: ONE good set in /root/backups/giftcoves/, a database dump and a tar of the
# uploaded pictures (media_data). A new set replaces the previous one only after it passes every
# check below; a failed night leaves the previous set where it was and exits non-zero, which
# `systemctl status giftcoves-backup` and the log show.
#
# The checks, and why each one:
#   - pg_dump exited 0 (a dump cut short by a full disk or a killed container still leaves a file)
#   - the archive's table of contents reads (pg_restore -l), so the file is a whole custom-format dump
#   - it holds data for at least as many tables as the database has (minus 2 for tables created
#     during the dump), and for the tables that matter by name
#   - it is at least 80% of the previous good dump's size: the catalogue grows or churns slowly,
#     so a dump that shrank by a fifth overnight is a broken dump, not a smaller database. If the
#     owner ever deletes a large share on purpose, the first night after fails this; run the script
#     by hand with MIN_RATIO=0 once.
set -eu

DIR=/root/backups/giftcoves
PG=postgres-sxvq4xkdg4svfxyltliwhnck
MEDIA=/var/lib/docker/volumes/gr0kqzz1er3s79u17vdph27t_media-data/_data
MIN_RATIO=${MIN_RATIO:-80}
STAMP=$(date -u +%Y%m%dT%H%M)
NEW="$DIR/prod-$STAMP.dump"
NEWMEDIA="$DIR/media-$STAMP.tar.gz"
LOG="$DIR/backup.log"

mkdir -p "$DIR"
log() { echo "$(date -u '+%F %T') $*" >> "$LOG"; echo "$*"; }
fail() { log "FAILED: $*"; rm -f "$NEW" "$NEWMEDIA"; exit 1; }

PREV=$(ls -1t "$DIR"/*.dump 2>/dev/null | grep -v "^$NEW$" | head -1 || true)

log "start $NEW"
nice -n 10 ionice -c2 -n7 docker exec "$PG" sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc -Z 3' > "$NEW" \
  || fail "pg_dump exited non-zero"

tables=$(docker exec "$PG" sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -XAtc "select count(*) from pg_tables where schemaname = '"'"'public'"'"'"') \
  || fail "could not count the tables"
toc=$(docker exec -i "$PG" pg_restore -l < "$NEW") || fail "table of contents does not read"
data=$(printf '%s\n' "$toc" | grep -c ' TABLE DATA ' || true)
[ "$data" -ge $((tables - 2)) ] || fail "data for $data tables, the database has $tables"
for t in products product_groups users wishlists wishlist_items cove_plans migrations; do
  printf '%s\n' "$toc" | grep -q " TABLE DATA public $t " || fail "no data for $t"
done

size=$(stat -c %s "$NEW")
if [ -n "$PREV" ]; then
  prev=$(stat -c %s "$PREV")
  [ $((size * 100)) -ge $((prev * MIN_RATIO)) ] || fail "$size bytes, under ${MIN_RATIO}% of the previous $prev"
fi

tar -czf "$NEWMEDIA" -C "$MEDIA" . || fail "media tar"
tar -tzf "$NEWMEDIA" > /dev/null || fail "media tar does not read"

# Passed: only now does the previous set go.
for f in "$DIR"/*.dump "$DIR"/*.dump.log "$DIR"/media-*.tar.gz; do
  [ -e "$f" ] || continue
  [ "$f" = "$NEW" ] || [ "$f" = "$NEWMEDIA" ] || rm -f "$f"
done
log "ok $NEW: $size bytes, data for $data of $tables tables; media $(stat -c %s "$NEWMEDIA") bytes; previous removed"
