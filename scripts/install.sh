#!/bin/sh
# Install (or remove) the APM modules in a Zabbix 8.0 frontend.
#
#   sudo ./scripts/install.sh                  # install / upgrade
#   sudo ./scripts/install.sh --uninstall      # remove the module folders
#   ZABBIX_UI=/path/to/ui sudo -E ./scripts/install.sh   # custom frontend directory
#
# Then: Administration -> General -> Modules -> Scan directory, and enable "APM Overview", "APM service health" and
# "APM service map". Existing folders are backed up to /var/backups/zabbix-apm-dashboard-<date>.
set -eu

MODULES="ApmOverview ApmServiceHealth ApmServiceMap"
SRC="$(cd "$(dirname "$0")/.." && pwd)/modules"

if [ -z "${ZABBIX_UI:-}" ]; then
	for dir in /usr/share/zabbix/ui /usr/share/zabbix /var/www/html/zabbix; do
		if [ -f "$dir/zabbix.php" ]; then
			ZABBIX_UI=$dir
			break
		fi
	done
fi

if [ -z "${ZABBIX_UI:-}" ] || [ ! -f "$ZABBIX_UI/zabbix.php" ]; then
	echo "Zabbix frontend not found; set ZABBIX_UI to the directory that contains zabbix.php." >&2
	exit 1
fi

if ! grep -q "ZABBIX_VERSION.*'8\." "$ZABBIX_UI/include/defines.inc.php" 2>/dev/null; then
	echo "Warning: these modules need Zabbix 8.0 or later (native APM)." >&2
fi

DEST="$ZABBIX_UI/modules"
BACKUP="/var/backups/zabbix-apm-dashboard-$(date +%Y%m%d-%H%M%S)"

backup() {
	for m in $MODULES; do
		if [ -d "$DEST/$m" ]; then
			mkdir -p "$BACKUP"
			cp -a "$DEST/$m" "$BACKUP/"
		fi
	done
	[ -d "$BACKUP" ] && echo "Backup of the previous version: $BACKUP" || true
}

if [ "${1:-}" = "--uninstall" ]; then
	backup
	for m in $MODULES; do
		rm -rf "${DEST:?}/$m"
	done
	echo "Removed. Disable or remove the modules in Administration -> General -> Modules if they are still listed."
	exit 0
fi

if command -v php >/dev/null 2>&1; then
	for f in $(find "$SRC" -name '*.php'); do
		php -l "$f" >/dev/null || { echo "Syntax error in $f, nothing installed." >&2; exit 1; }
	done
fi

backup
mkdir -p "$DEST"

# The frontend's PHP user must be able to read the files; nothing needs to be writable.
OWNER="$(stat -c '%U:%G' "$ZABBIX_UI/zabbix.php")"

for m in $MODULES; do
	rm -rf "${DEST:?}/$m"
	cp -R "$SRC/$m" "$DEST/"
	chown -R "$OWNER" "$DEST/$m"
	find "$DEST/$m" -type d -exec chmod 755 {} +
	find "$DEST/$m" -type f -exec chmod 644 {} +
done

echo "Installed in $DEST: $MODULES"
echo "Next: Administration -> General -> Modules -> Scan directory, then enable the three modules."
