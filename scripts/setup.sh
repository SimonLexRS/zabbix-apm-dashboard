#!/usr/bin/env bash
# Zabbix APM on one Ubuntu/Debian server that already runs Zabbix 8: ClickHouse, the OpenTelemetry Collector, the
# modules, the APM data source and, optionally, the OpenTelemetry Demo store as a test application.
#
#   sudo ./scripts/setup.sh check              # prerequisites only, changes nothing
#   sudo ./scripts/setup.sh install            # ClickHouse + Collector + modules + data source + smoke test
#   sudo ./scripts/setup.sh demo [IP]          # test store in Docker; IP = address you open it with in the browser
#   sudo ./scripts/setup.sh demo stop          # stop the test store (start it again with demo [IP])
#   sudo ./scripts/setup.sh shop [IP|stop]     # Fault Shop: a small store whose failures you switch on by hand
#   sudo ./scripts/setup.sh verify [namespace] # compare Zabbix with ClickHouse (default: opentelemetry-demo)
#   sudo ./scripts/setup.sh status
#   sudo ./scripts/setup.sh uninstall [--purge] [--yes]   # --purge also removes ClickHouse and ALL its data
#
# Every command can be run again. Optional environment (sudo VAR=value ./scripts/setup.sh ...):
#   ZABBIX_API_TOKEN   Super admin API token; asked once and kept in /root/.zabbix/setup.env
#   ZABBIX_URL         frontend URL as seen from this server (default: detected, e.g. http://127.0.0.1)
#   ZABBIX_UI          frontend directory, the one with zabbix.php (default: detected)
#   CH_ADMIN_USER, CH_ADMIN_PASSWORD   admin of an existing ClickHouse (default: user "default", no password)
#   OTELCOL_VERSION    otelcol-contrib release to install (default below)
#   DEMO_DIR, DEMO_PORT, DEMO_REPO     test store (default: /opt/opentelemetry-demo, 18080)
#   SHOP_PORT          port of the Fault Shop (default: 18090)
set -euo pipefail

OTELCOL_VERSION="${OTELCOL_VERSION:-0.164.0}"
DEMO_DIR="${DEMO_DIR:-/opt/opentelemetry-demo}"
DEMO_PORT="${DEMO_PORT:-18080}"
DEMO_REPO="${DEMO_REPO:-https://github.com/SimonLexRS/opentelemetry-demo.git}"
DEMO_NAMESPACE=opentelemetry-demo
SHOP_PORT="${SHOP_PORT:-18090}"
SHOP_NAMESPACE=fault-shop
MODULE_IDS="apmoverview apmservicehealth apmservicemap"

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
STATE=/root/.zabbix
OTEL_ETC=/etc/otelcol-contrib
OTEL_DROPIN=/etc/systemd/system/otelcol-contrib.service.d/zabbix-apm-docker.conf
SMOKE_SERVICE=apm-setup-check
FAILED=0

step() { printf '\n== %s\n' "$*"; }
ok() { printf '  \342\234\224 %s\n' "$*"; }
warn() { printf '  ! %s\n' "$*"; }
bad() { printf '  \342\234\230 %s\n' "$*"; FAILED=1; }
die() { printf '  \342\234\230 %s\n' "$*" >&2; exit 1; }

# wait_for <seconds> <command...>: retry until the command succeeds.
wait_for() {
	local limit=$1 waited=0
	shift

	until "$@" >/dev/null 2>&1; do
		waited=$((waited + 2))
		[ "$waited" -ge "$limit" ] && return 1
		sleep 2
	done
}

listening() { ss -Hltn "sport = :$2" 2>/dev/null | awk '{print $4}' | grep -q "^$1:"; }
port_owner() { ss -Hltnp "sport = :$1" 2>/dev/null | sed -n 's/.*users:(("\([^"]*\)".*/\1/p' | sort -u | tr '\n' ' '; }
mem_mib() { awk '/^MemTotal:/ {print int($2 / 1024)}' /proc/meminfo; }
disk_free_gb() { df -BG --output=avail "$1" 2>/dev/null | tail -1 | tr -dc '0-9'; }

# --- Zabbix API -------------------------------------------------------------------------------------------------------

# api <method> <json params>: prints the result as JSON; fails with the API error on stderr.
api() {
	ZBX_URL="$ZABBIX_URL" ZBX_TOKEN="${ZABBIX_API_TOKEN:-}" python3 - "$1" "$2" <<'PY'
import json, os, sys, urllib.request

method, params = sys.argv[1], json.loads(sys.argv[2])
headers = {'Content-Type': 'application/json-rpc'}

if method != 'apiinfo.version' and os.environ['ZBX_TOKEN']:
    headers['Authorization'] = 'Bearer ' + os.environ['ZBX_TOKEN']

body = json.dumps({'jsonrpc': '2.0', 'method': method, 'params': params, 'id': 1}).encode()

try:
    with urllib.request.urlopen(urllib.request.Request(os.environ['ZBX_URL'].rstrip('/') + '/api_jsonrpc.php', body, headers), timeout=60) as response:
        result = json.load(response)
except (OSError, ValueError) as error:
    sys.exit(f'{method}: {error}')

if 'error' in result:
    sys.exit(f'{method}: {result["error"].get("data") or result["error"].get("message")}')

print(json.dumps(result['result']))
PY
}

load_state() {
	local saved_url='' saved_token=''

	if [ -r "$STATE/setup.env" ]; then
		saved_url="$(sed -n 's/^ZABBIX_URL=//p' "$STATE/setup.env")"
		saved_token="$(sed -n 's/^ZABBIX_API_TOKEN=//p' "$STATE/setup.env")"
	fi

	ZABBIX_URL="${ZABBIX_URL:-$saved_url}"
	ZABBIX_API_TOKEN="${ZABBIX_API_TOKEN:-$saved_token}"
}

save_state() {
	install -d -o root -g root -m 700 "$STATE"
	(umask 077; printf 'ZABBIX_URL=%s\nZABBIX_API_TOKEN=%s\n' "$ZABBIX_URL" "$ZABBIX_API_TOKEN" > "$STATE/setup.env")
}

# Sets ZABBIX_URL to the first address that answers as a Zabbix API.
find_api() {
	local url

	for url in ${ZABBIX_URL:+"$ZABBIX_URL"} http://127.0.0.1 http://127.0.0.1/zabbix http://127.0.0.1:8080 https://127.0.0.1 https://127.0.0.1/zabbix; do
		if API_VERSION="$(ZABBIX_URL="$url" api apiinfo.version '{}' 2>/dev/null | tr -d '"')" && [ -n "$API_VERSION" ]; then
			ZABBIX_URL="$url"
			return 0
		fi
	done

	return 1
}

ask_token() {
	[ -n "${ZABBIX_API_TOKEN:-}" ] && return 0
	{ : < /dev/tty; } 2>/dev/null || return 1

	printf 'Zabbix API token of a Super admin (User settings -> API tokens), input hidden: ' > /dev/tty
	read -rs ZABBIX_API_TOKEN < /dev/tty
	printf '\n' > /dev/tty
	[ -n "$ZABBIX_API_TOKEN" ]
}

# --- ClickHouse -------------------------------------------------------------------------------------------------------

ch_admin() {
	local args=()

	[ -n "${CH_ADMIN_USER:-}" ] && args+=(--user "$CH_ADMIN_USER")
	[ -n "${CH_ADMIN_PASSWORD:-}" ] && args+=(--password "$CH_ADMIN_PASSWORD")
	clickhouse-client "${args[@]}" "$@"
}

ch_reader() { clickhouse-client --user zabbix_ro --password "$(cat "$STATE/ch_reader")" "$@"; }
ch_has_table() { ch_admin -q 'SHOW TABLES FROM zabbix' | grep -qx "$1"; }
ch_has_rows() { [ "$(ch_reader -q "$1" 2>/dev/null || echo 0)" -gt 0 ]; }

# --- check ------------------------------------------------------------------------------------------------------------

check() {
	local arch mem disk port owner version busy=0

	step 'Prerequisites'

	command -v apt-get >/dev/null || die 'This installer supports Ubuntu and Debian (apt). Other systems: docs/installation.md.'
	command -v systemctl >/dev/null || die 'systemd is required.'

	arch="$(dpkg --print-architecture)"
	case "$arch" in
		amd64 | arm64) ok "System: $(. /etc/os-release; echo "$PRETTY_NAME"), $arch" ;;
		*) bad "Architecture $arch: ClickHouse and the Collector are published for amd64 and arm64 only." ;;
	esac

	for tool in python3 curl; do
		command -v "$tool" >/dev/null || die "$tool is missing: sudo apt-get install -y python3 curl"
	done

	python3 -c 'import sys; sys.exit(sys.version_info < (3, 10))' || bad "Python $(python3 -V | awk '{print $2}'): the scripts need 3.10 or later."

	# Zabbix reads ClickHouse over HTTP with the PHP curl extension.
	if [ -d /etc/php ] && ! compgen -G '/etc/php/*/*/conf.d/*curl*' >/dev/null; then
		bad 'PHP curl extension missing: sudo apt-get install -y php-curl, then restart php-fpm or Apache.'
	fi

	if ZABBIX_UI="$(sh "$REPO/scripts/install.sh" --where 2>&1)"; then
		version="$(sed -n "s/.*'ZABBIX_VERSION',[[:space:]]*'\([^']*\)'.*/\1/p" "$ZABBIX_UI/include/defines.inc.php" 2>/dev/null)"
		case "$version" in
			[89].* | [1-9][0-9].*) ok "Zabbix frontend $version in $ZABBIX_UI" ;;
			*) bad "Zabbix frontend ${version:-unknown} in $ZABBIX_UI: APM needs Zabbix 8.0 or later." ;;
		esac
		export ZABBIX_UI
	else
		bad "$ZABBIX_UI"
	fi

	if find_api; then
		ok "Zabbix API $API_VERSION at $ZABBIX_URL"

		if ! ask_token; then
			bad 'No API token: create one in User settings -> API tokens (user with the Super admin role) and set ZABBIX_API_TOKEN.'
		elif api module.get '{"output":["id"],"limit":1}' >/dev/null 2>"$TMP/api-error"; then
			ok 'API token accepted (Super admin)'
			save_state
		else
			bad "API token rejected ($(cat "$TMP/api-error")). It must belong to a Super admin and not be expired."
			ZABBIX_API_TOKEN=''
		fi
	else
		bad 'Zabbix API not found. Set ZABBIX_URL to the address of the frontend, e.g. sudo ZABBIX_URL=http://127.0.0.1/zabbix ./scripts/setup.sh check'
	fi

	mem="$(mem_mib)"
	if [ "$mem" -lt 3500 ]; then
		warn "RAM: $mem MiB. ClickHouse next to Zabbix needs about 4 GiB; expect slow or failing queries."
	elif [ "$mem" -lt 9000 ]; then
		ok "RAM: $mem MiB (enough for APM; the test store needs about 10 GiB)"
	else
		ok "RAM: $mem MiB"
	fi

	disk="$(disk_free_gb /var/lib)"
	if [ "${disk:-0}" -lt 5 ]; then
		bad "Disk: ${disk:-0} GB free in /var/lib; at least 5 GB are needed (20 GB with the test store)."
	elif [ "$disk" -lt 20 ]; then
		ok "Disk: $disk GB free (enough for APM; the test store needs about 20 GB)"
	else
		ok "Disk: $disk GB free"
	fi

	for port in 8123 9000 4317 4318; do
		owner="$(port_owner "$port")"
		case "$port:$owner" in
			*: | 8123:clickhouse* | 9000:clickhouse* | 4317:otelcol* | 4318:otelcol*) ;;
			*) bad "Port $port is in use by: $owner. Stop that service or move it."; busy=1 ;;
		esac
	done
	[ "$busy" -eq 1 ] || ok 'Ports 8123, 9000, 4317 and 4318 are free or already ours'

	if curl -fsS -m 15 -o /dev/null https://packages.clickhouse.com/deb/dists/stable/InRelease; then
		ok 'Internet access'
	else
		bad 'No access to https://packages.clickhouse.com (needed to download packages). Check DNS, proxy or firewall.'
	fi

	[ "$FAILED" -eq 0 ] || die 'Fix the items marked above and run the command again.'
}

# --- install ----------------------------------------------------------------------------------------------------------

# Quiet install; the output is shown only if it fails.
apt_install() {
	DEBIAN_FRONTEND=noninteractive NEEDRESTART_SUSPEND=1 apt-get install -y -qq "$@" >"$TMP/apt.log" 2>&1 \
		|| { tail -20 "$TMP/apt.log" >&2; die "apt-get install $* failed (output above)."; }
}

install_packages() {
	local missing=() package

	for package in "$@"; do
		dpkg -s "$package" >/dev/null 2>&1 || missing+=("$package")
	done

	[ "${#missing[@]}" -eq 0 ] && return 0

	apt-get update -qq
	apt_install "${missing[@]}"
}

install_clickhouse() {
	local arch writer reader user fresh=0

	step 'ClickHouse'

	if ! command -v clickhouse-server >/dev/null; then
		arch="$(dpkg --print-architecture)"
		curl -fsSL https://packages.clickhouse.com/rpm/lts/repodata/repomd.xml.key | gpg --dearmor --yes -o /usr/share/keyrings/clickhouse-keyring.gpg
		echo "deb [signed-by=/usr/share/keyrings/clickhouse-keyring.gpg arch=$arch] https://packages.clickhouse.com/deb stable main" > /etc/apt/sources.list.d/clickhouse.list
		apt-get update -qq
		apt_install clickhouse-server clickhouse-client
		fresh=1
	fi

	# Only on the ClickHouse installed here: an existing one keeps its own tuning.
	if [ "$fresh" -eq 1 ]; then
		install -m 644 "$REPO/examples/clickhouse-low-memory.xml" /etc/clickhouse-server/config.d/low-memory.xml
	fi

	systemctl enable -q clickhouse-server 2>/dev/null
	if [ "$fresh" -eq 1 ]; then
		systemctl restart clickhouse-server
	else
		systemctl start clickhouse-server
	fi

	wait_for 90 ch_admin -q 'SELECT 1' \
		|| die 'ClickHouse does not answer. Log: sudo journalctl -u clickhouse-server -n 30 --no-pager. With an existing ClickHouse, set CH_ADMIN_USER and CH_ADMIN_PASSWORD.'
	ok "ClickHouse $(ch_admin -q 'SELECT version()') is running"

	install -d -o root -g root -m 700 "$STATE"
	for user in ch_writer ch_reader; do
		[ -s "$STATE/$user" ] || (umask 077; openssl rand -hex 16 > "$STATE/$user")
	done
	writer="$(cat "$STATE/ch_writer")"
	reader="$(cat "$STATE/ch_reader")"

	{
		sed -e "s/<writer-password>/$writer/; s/<reader-password>/$reader/" "$REPO/examples/clickhouse-users.sql"
		# The users may exist from an earlier installation with other passwords.
		echo "ALTER USER otel_writer IDENTIFIED WITH sha256_password BY '$writer';"
		echo "ALTER USER zabbix_ro IDENTIFIED WITH sha256_password BY '$reader';"
	} | ch_admin --multiquery \
		|| die 'Cannot create the ClickHouse users. The admin user needs access management (CH_ADMIN_USER, CH_ADMIN_PASSWORD).'

	ch_reader -q 'SHOW DATABASES' | grep -qx zabbix || die 'User zabbix_ro cannot read database "zabbix".'
	ok "Users otel_writer and zabbix_ro (passwords in $STATE, readable by root only)"
}

install_collector() {
	local arch file url tmp_config

	step 'OpenTelemetry Collector'

	if ! command -v otelcol-contrib >/dev/null; then
		arch="$(dpkg --print-architecture)"
		file="otelcol-contrib_${OTELCOL_VERSION}_linux_${arch}.deb"
		url="https://github.com/open-telemetry/opentelemetry-collector-releases/releases/download/v$OTELCOL_VERSION/$file"

		echo "  Downloading $file (about 105 MB)..."
		curl -fL --retry 5 --retry-delay 5 -C - -sS -o "$TMP/$file" "$url" || die "Download failed: $url"
		(cd "$TMP" && echo "$(curl -fsSL --retry 5 "$url.sha256" | awk '{print $1}')  $file" | sha256sum -c --status) \
			|| die "Checksum of $file does not match; nothing installed. Run the command again."
		dpkg -i "$TMP/$file" >"$TMP/dpkg.log" 2>&1 || { tail -20 "$TMP/dpkg.log" >&2; die "Could not install $file (output above)."; }
		rm -f "$TMP/$file"
	fi

	# The package starts with a sample configuration open on every interface: replace it, localhost only.
	tmp_config="$TMP/config.yaml"
	sed -e 's#endpoint: 0.0.0.0:4317.*#endpoint: 127.0.0.1:4317#; s#endpoint: 0.0.0.0:4318#endpoint: 127.0.0.1:4318#' \
		-e "s/<clickhouse-writer-user>/otel_writer/; s/<clickhouse-writer-password>/$(cat "$STATE/ch_writer")/" \
		"$REPO/examples/otelcol-config.yaml" > "$tmp_config"

	if [ -f "$OTEL_ETC/config.yaml" ] && ! cmp -s "$tmp_config" "$OTEL_ETC/config.yaml" && ! grep -q 'otel_metrics_exponential_histogram' "$OTEL_ETC/config.yaml"; then
		cp -a "$OTEL_ETC/config.yaml" "$OTEL_ETC/config.yaml.before-zabbix-apm"
	fi
	install -o root -g otelcol-contrib -m 640 "$tmp_config" "$OTEL_ETC/config.yaml"

	otelcol-contrib validate --config="$OTEL_ETC/config.yaml" >/dev/null 2>"$TMP/otel-error" || die "Invalid Collector configuration: $(tail -3 "$TMP/otel-error")"
	systemctl enable -q otelcol-contrib 2>/dev/null
	systemctl restart otelcol-contrib

	if ! wait_for 90 listening 127.0.0.1 4317 || ! wait_for 90 ch_has_table otel_traces; then
		journalctl -u otelcol-contrib -n 15 --no-pager >&2 || true
		die 'The Collector did not start or did not create its tables in ClickHouse (log above).'
	fi

	ok "otelcol-contrib $(otelcol-contrib --version | awk '{print $NF}') receives OTLP on 127.0.0.1:4317 (gRPC) and :4318 (HTTP)"
	ok "Tables in ClickHouse: $(ch_admin -q "SELECT count() FROM system.tables WHERE database = 'zabbix'")"
}

install_modules() {
	step 'Zabbix modules'

	sh "$REPO/scripts/install.sh" | sed -n 's/^Installed/  \xe2\x9c\x94 Installed/p; s/^Backup/  Backup/p'
	ZABBIX_URL="$ZABBIX_URL" ZABBIX_API_TOKEN="$ZABBIX_API_TOKEN" python3 "$REPO/scripts/enable-modules.py" | sed 's/^/  \xe2\x9c\x94 /'
}

install_data_source() {
	step 'APM data source'

	api settings.update "{\"apm_global_db\":{\"status\":1,\"url\":\"http://127.0.0.1:8123\",\"db\":\"zabbix\",\"authentication_type\":0,\"username\":\"zabbix_ro\",\"password\":\"$(cat "$STATE/ch_reader")\"}}" >/dev/null
	ok 'Administration -> Data source -> APM: http://127.0.0.1:8123, database zabbix, user zabbix_ro'
}

smoke_test() {
	step 'Smoke test'

	python3 - "$SMOKE_SERVICE" <<'PY' || die 'The Collector rejected a test trace on http://127.0.0.1:4318.'
import json, os, sys, time, urllib.request

now = time.time_ns()
span = {'traceId': os.urandom(16).hex(), 'spanId': os.urandom(8).hex(), 'name': 'GET /setup-check', 'kind': 2,
        'startTimeUnixNano': str(now - 50_000_000), 'endTimeUnixNano': str(now), 'status': {'code': 1}}
resource = [{'key': key, 'value': {'stringValue': sys.argv[1]}} for key in ('service.name', 'service.namespace')]
body = {'resourceSpans': [{'resource': {'attributes': resource}, 'scopeSpans': [{'scope': {'name': 'setup.sh'}, 'spans': [span]}]}]}
request = urllib.request.Request('http://127.0.0.1:4318/v1/traces', json.dumps(body).encode(), {'Content-Type': 'application/json'})
urllib.request.urlopen(request, timeout=20).read()
PY

	wait_for 60 ch_has_rows "SELECT count() FROM zabbix.otel_traces WHERE ServiceName = '$SMOKE_SERVICE'" \
		|| die 'The test trace did not reach ClickHouse. Log: sudo journalctl -u otelcol-contrib -n 30 --no-pager'
	ok 'A test trace went Collector -> ClickHouse and Zabbix'"'"'s read-only user can see it'

	# Remove it, so that it does not show up as an application.
	ch_admin -q "ALTER TABLE zabbix.otel_traces DELETE WHERE ServiceName = '$SMOKE_SERVICE'" 2>/dev/null || true
}

install_all() {
	check
	install_packages curl gnupg openssl python3 git ca-certificates
	install_clickhouse
	install_collector
	install_modules
	install_data_source
	smoke_test

	printf '\nAPM is ready: open Zabbix -> APM -> Overview.\n'
	printf 'Next: sudo ./scripts/setup.sh demo   (test store), or connect your application: docs/add-application.md\n'
}

# --- demo -------------------------------------------------------------------------------------------------------------

# Docker, and the Collector listening on its bridge too: where the containers of a test store send their telemetry.
docker_bridge() {
	local bridge_ip

	docker compose version >/dev/null 2>&1 || install_packages docker.io docker-compose-v2
	systemctl enable -q --now docker 2>/dev/null
	wait_for 60 ip -4 address show docker0 || die 'Docker did not create its bridge (docker0). Log: sudo journalctl -u docker -n 30 --no-pager'
	bridge_ip="$(ip -4 -o address show docker0 | awk '{print $4}' | cut -d/ -f1)"
	ok "Docker $(docker version --format '{{.Server.Version}}'), bridge $bridge_ip"

	step 'Collector: also listen on the Docker bridge'

	# A container cannot reach 127.0.0.1 of the server; the bridge address is not reachable from the network.
	sed "s/172\.17\.0\.1/$bridge_ip/" "$REPO/examples/stacks/opentelemetry-demo/otelcol-docker-bridge.yaml" > "$OTEL_ETC/docker-bridge.yaml"
	chmod 644 "$OTEL_ETC/docker-bridge.yaml"
	mkdir -p "$(dirname "$OTEL_DROPIN")"
	cat > "$OTEL_DROPIN" <<EOF
# Written by zabbix-apm-dashboard scripts/setup.sh
[Unit]
After=docker.service
Wants=docker.service

[Service]
ExecStart=
ExecStart=/usr/bin/otelcol-contrib --config=$OTEL_ETC/config.yaml --config=$OTEL_ETC/docker-bridge.yaml
Restart=always
RestartSec=5
EOF
	systemctl daemon-reload
	systemctl restart otelcol-contrib

	if command -v ufw >/dev/null && ufw status | grep -q '^Status: active'; then
		ufw allow in on docker0 to "$bridge_ip" port 4317:4318 proto tcp comment 'zabbix-apm OTLP from Docker' >/dev/null
		ufw route allow in on docker0 >/dev/null 2>&1 || true
	fi

	if ! wait_for 60 listening "$bridge_ip" 4317 || ! listening 127.0.0.1 4317; then
		journalctl -u otelcol-contrib -n 15 --no-pager >&2 || true
		die 'The Collector did not open its ports (log above).'
	fi
	ok "Collector listens on 127.0.0.1 and $bridge_ip only"
}

compose() {
	(cd "$DEMO_DIR" && docker compose -f compose.yaml -f compose.full.yaml -f compose.zabbix-lite.yaml \
		--env-file .env --env-file .env.override --env-file .env.zabbix "$@")
}

demo_http_ok() { [ "$(curl -s -m 10 -o /dev/null -w '%{http_code}' "http://127.0.0.1:$DEMO_PORT/")" = 200 ]; }

demo() {
	local public_ip="${1:-}" mem attempt

	if [ "$public_ip" = stop ]; then
		[ -f "$DEMO_DIR/.env.zabbix" ] || die 'The test store is not installed.'
		compose stop >/dev/null 2>&1
		ok 'Test store stopped. Start it again with: sudo ./scripts/setup.sh demo <IP>'
		return 0
	fi

	load_state
	[ -s "$STATE/ch_reader" ] && systemctl is-active -q otelcol-contrib || die 'Run first: sudo ./scripts/setup.sh install'
	find_api && [ -n "${ZABBIX_API_TOKEN:-}" ] || die 'Zabbix API or token not available. Run: sudo ./scripts/setup.sh check'

	step 'Test store: prerequisites'

	mem="$(mem_mib)"
	if [ "$mem" -lt 9000 ] && [ -z "${DEMO_FORCE:-}" ]; then
		die "RAM: $mem MiB. The store needs a server with about 10 GiB. Connect your own application instead: docs/add-application.md (or force with DEMO_FORCE=1)."
	fi

	if [ -z "$public_ip" ]; then
		public_ip="$(ip -4 route get 1.1.1.1 2>/dev/null | sed -n 's/.* src \([0-9.]*\).*/\1/p')"
		[ -n "$public_ip" ] || die 'Give the address you open this server with: sudo ./scripts/setup.sh demo <IP>'
		warn "Using $public_ip as the address of the store. If you browse to another one: sudo ./scripts/setup.sh demo <IP>"
	fi

	install_packages git
	docker_bridge

	step 'Test store (OpenTelemetry Demo)'

	[ -d "$DEMO_DIR/.git" ] || git clone -q --depth 1 "$DEMO_REPO" "$DEMO_DIR"
	cp "$REPO/examples/stacks/opentelemetry-demo/compose.zabbix-lite.yaml" "$REPO/examples/stacks/opentelemetry-demo/otelcol-config-zabbix.yml" "$DEMO_DIR/"
	cat > "$DEMO_DIR/.env.zabbix" <<EOF
ZABBIX_APM_ENDPOINT=host.docker.internal:4317
ZABBIX_APM_NAMESPACE=$DEMO_NAMESPACE
ZABBIX_APM_INSECURE=true
DEMO_HOST_PORT=$DEMO_PORT
DEMO_PUBLIC_HOST=$public_ip
EOF

	echo '  Downloading the images (8.6 GB the first time, be patient)...'
	attempt=1
	until compose pull --quiet 2>"$TMP/pull-error"; do
		[ "$attempt" -ge 4 ] && { tail -5 "$TMP/pull-error" >&2; die 'Could not download the images (error above). Run the command again: it resumes.'; }
		attempt=$((attempt + 1))
		sleep 10
	done

	compose up -d --no-build --quiet-pull 2>"$TMP/up-error" || { tail -15 "$TMP/up-error" >&2; die 'docker compose up failed (error above).'; }
	ok "$(compose ps -q | wc -l) containers started"

	wait_for 300 demo_http_ok || die "The store does not answer on port $DEMO_PORT. See: sudo docker ps -a"
	ok "Store: http://$public_ip:$DEMO_PORT/"

	step 'Application in Zabbix'

	ZABBIX_URL="$ZABBIX_URL" ZABBIX_API_TOKEN="$ZABBIX_API_TOKEN" \
		python3 "$REPO/scripts/zabbix-apm-app.py" add "$DEMO_NAMESPACE" --name 'OpenTelemetry Demo' --timeshift 60s | sed 's/^/  /'

	wait_for 300 ch_has_rows "SELECT count() FROM zabbix.otel_traces WHERE ResourceAttributes['service.namespace'] = '$DEMO_NAMESPACE'" \
		|| die 'No traces from the store in ClickHouse yet. Logs: sudo docker logs otel-collector --tail 30 ; sudo journalctl -u otelcol-contrib -n 30 --no-pager'
	ok 'Traces from the store are arriving in ClickHouse'

	printf '\nOpen Zabbix -> APM -> Overview -> %s (the service map fills in within a minute).\n' "$DEMO_NAMESPACE"
	printf 'Browse the store at http://%s:%s/ and look at APM -> Real user monitoring.\n' "$public_ip" "$DEMO_PORT"
	printf 'In 10 minutes, compare Zabbix with ClickHouse: sudo ./scripts/setup.sh verify\n'
}

# --- shop -------------------------------------------------------------------------------------------------------------

shop_compose() { docker compose -p "$SHOP_NAMESPACE" -f "$REPO/examples/stacks/fault-shop/compose.yaml" "$@"; }
shop_installed() { command -v docker >/dev/null && [ -n "$(shop_compose ps -aq 2>/dev/null)" ]; }
shop_http_ok() { [ "$(curl -s -m 10 -o /dev/null -w '%{http_code}' "http://127.0.0.1:$SHOP_PORT/api/products")" = 200 ]; }

shop() {
	local address="${1:-}"

	if [ "$address" = stop ]; then
		shop_installed || die 'The Fault Shop is not installed.'
		shop_compose stop >/dev/null 2>&1
		ok 'Fault Shop stopped. Start it again with: sudo ./scripts/setup.sh shop'
		return 0
	fi

	load_state
	[ -s "$STATE/ch_reader" ] && systemctl is-active -q otelcol-contrib || die 'Run first: sudo ./scripts/setup.sh install'
	find_api && [ -n "${ZABBIX_API_TOKEN:-}" ] || die 'Zabbix API or token not available. Run: sudo ./scripts/setup.sh check'

	step 'Fault Shop: prerequisites'
	docker_bridge

	step 'Fault Shop'

	echo '  Building the images (2 to 5 minutes the first time)...'
	SHOP_PORT="$SHOP_PORT" shop_compose up -d --build --quiet-pull >"$TMP/shop-up" 2>&1 \
		|| { tail -15 "$TMP/shop-up" >&2; die 'docker compose up failed (error above).'; }
	ok "$(shop_compose ps -q | wc -l) containers started"

	wait_for 120 shop_http_ok || die "The shop does not answer on port $SHOP_PORT. See: sudo docker ps -a"
	[ -n "$address" ] || address="$(ip -4 route get 1.1.1.1 2>/dev/null | sed -n 's/.* src \([0-9.]*\).*/\1/p')"
	ok "Store: http://${address:-<this server>}:$SHOP_PORT/"

	step 'Application in Zabbix'

	ZABBIX_URL="$ZABBIX_URL" ZABBIX_API_TOKEN="$ZABBIX_API_TOKEN" \
		python3 "$REPO/scripts/zabbix-apm-app.py" add "$SHOP_NAMESPACE" --name 'Fault Shop' --timeshift 60s | sed 's/^/  /'

	wait_for 120 ch_has_rows "SELECT count() FROM zabbix.otel_traces WHERE ResourceAttributes['service.namespace'] = '$SHOP_NAMESPACE' AND Timestamp > now() - INTERVAL 5 MINUTE" \
		|| die 'No traces from the shop in ClickHouse yet. Logs: sudo docker logs fault-shop-shop-1 --tail 30 ; sudo journalctl -u otelcol-contrib -n 30 --no-pager'
	ok 'Traces from the shop are arriving in ClickHouse'

	printf '\nFault panel: http://%s:%s/faults.html  (switch a failure on, then look at it in Zabbix)\n' "${address:-<this server>}" "$SHOP_PORT"
	printf 'Open Zabbix -> APM -> Overview -> %s. Guide: examples/stacks/fault-shop/README.md\n' "$SHOP_NAMESPACE"
}

# --- verify / status --------------------------------------------------------------------------------------------------

verify() {
	local namespace="${1:-$DEMO_NAMESPACE}"

	load_state
	find_api && [ -n "${ZABBIX_API_TOKEN:-}" ] || die 'Zabbix API or token not available. Run: sudo ./scripts/setup.sh check'
	[ -s "$STATE/ch_reader" ] || die 'Run first: sudo ./scripts/setup.sh install'

	export ZABBIX_URL ZABBIX_API_TOKEN CH_URL=http://127.0.0.1:8123 CH_USER=zabbix_ro
	CH_PASSWORD="$(cat "$STATE/ch_reader")"
	export CH_PASSWORD

	step "Items in Zabbix against ClickHouse ($namespace)"
	python3 "$REPO/scripts/verify-app.py" "$namespace" || FAILED=1
	step "Real user monitoring ($namespace)"
	python3 "$REPO/scripts/test-rum.py" "$namespace" || FAILED=1

	[ "$FAILED" -eq 0 ] || die 'Some checks failed. Values need 10 minutes of data: wait and run it again.'
}

status() {
	local service enabled

	load_state
	step 'Status'

	for service in clickhouse-server otelcol-contrib; do
		if systemctl is-active -q "$service"; then ok "$service: active"; else bad "$service: $(systemctl is-active "$service" || true)"; fi
	done

	ok "OTLP listening on: $(ss -Hltn 'sport = :4317' | awk '{print $4}' | tr '\n' ' ')"

	if [ -s "$STATE/ch_reader" ] && systemctl is-active -q clickhouse-server; then
		echo '  Spans in the last 15 minutes, by application:'
		ch_reader -q "SELECT '    ' || ResourceAttributes['service.namespace'] AS app, count() FROM zabbix.otel_traces WHERE Timestamp > now() - INTERVAL 15 MINUTE GROUP BY app ORDER BY app FORMAT TSV" 2>/dev/null || bad 'zabbix_ro cannot query zabbix.otel_traces'
	fi

	if find_api && [ -n "${ZABBIX_API_TOKEN:-}" ]; then
		enabled="$(api module.get '{"output":["id","status"]}' | python3 -c "
import json, sys
wanted = sys.argv[1].split()
print(sum(1 for m in json.load(sys.stdin) if m['id'] in wanted and str(m['status']) == '1'))" "$MODULE_IDS")"
		if [ "$enabled" = 3 ]; then ok "Zabbix $API_VERSION at $ZABBIX_URL: 3 modules enabled"; else bad "Modules enabled: $enabled of 3. Run: sudo ./scripts/setup.sh install"; fi
	else
		warn 'Zabbix API or token not available: modules not checked'
	fi

	if [ -f "$DEMO_DIR/.env.zabbix" ]; then
		if demo_http_ok; then ok "Test store: http://$(sed -n 's/^DEMO_PUBLIC_HOST=//p' "$DEMO_DIR/.env.zabbix"):$DEMO_PORT/"; else bad "Test store: no answer on port $DEMO_PORT"; fi
	fi

	if shop_installed; then
		if shop_http_ok; then ok "Fault Shop: port $SHOP_PORT, panel at /faults.html"; else bad "Fault Shop: no answer on port $SHOP_PORT (stopped? sudo ./scripts/setup.sh shop)"; fi
	fi

	[ "$FAILED" -eq 0 ] || exit 1
}

# --- uninstall --------------------------------------------------------------------------------------------------------

uninstall() {
	local purge=0 yes=0 arg ids

	for arg in "$@"; do
		case "$arg" in
			--purge) purge=1 ;;
			--yes) yes=1 ;;
			*) die "Unknown option: $arg" ;;
		esac
	done

	if [ "$purge" -eq 1 ] && [ "$yes" -eq 0 ]; then
		printf 'This removes ClickHouse and ALL its databases from this server. Type "yes" to continue: '
		read -r arg
		[ "$arg" = yes ] || die 'Cancelled.'
	fi

	load_state

	step 'Test store'
	if [ -f "$DEMO_DIR/.env.zabbix" ] && command -v docker >/dev/null; then
		compose down -v --remove-orphans >/dev/null 2>&1 || warn "docker compose down failed in $DEMO_DIR"
		rm -f "$DEMO_DIR/.env.zabbix"
		ok "Stopped and removed (its images stay: sudo docker image prune -a)"
	else
		ok 'Not installed'
	fi

	if shop_installed; then
		shop_compose down -v --remove-orphans >/dev/null 2>&1 || warn 'docker compose down failed for the Fault Shop'
		ok 'Fault Shop stopped and removed'
	fi

	step 'Zabbix'
	if command -v python3 >/dev/null && find_api && [ -n "${ZABBIX_API_TOKEN:-}" ]; then
		ids="$(api host.get "{\"output\":[\"hostid\"],\"filter\":{\"host\":[\"apm-$DEMO_NAMESPACE\",\"apm-$SHOP_NAMESPACE\"]}}" | python3 -c 'import json, sys; print(json.dumps([h["hostid"] for h in json.load(sys.stdin)]))')"
		[ "$ids" = '[]' ] || { api host.delete "$ids" >/dev/null && ok "Hosts of the test stores deleted"; }

		api settings.update '{"apm_global_db":{"status":0}}' >/dev/null 2>&1 \
			&& ok 'APM data source disabled' || warn 'Disable it in Administration -> Data source -> APM'

		ids="$(api module.get '{"output":["moduleid","id"]}' | python3 -c "
import json, sys
wanted = sys.argv[1].split()
print(json.dumps([m['moduleid'] for m in json.load(sys.stdin) if m['id'] in wanted]))" "$MODULE_IDS")"
		[ "$ids" = '[]' ] || { api module.delete "$ids" >/dev/null && ok 'Modules unregistered'; }
		warn 'Hosts and templates of your own applications are kept.'
	else
		warn 'Zabbix API or token not available: remove the modules, the APM data source and the host by hand.'
	fi
	sh "$REPO/scripts/install.sh" --uninstall 2>&1 | sed 's/^/  /' || true

	step 'OpenTelemetry Collector'
	systemctl disable -q --now otelcol-contrib 2>/dev/null || true
	if command -v ufw >/dev/null; then
		ufw status numbered 2>/dev/null | grep -q 'zabbix-apm OTLP' && yes | ufw delete "$(ufw status numbered | sed -n 's/^\[ *\([0-9]*\)\].*zabbix-apm OTLP.*/\1/p' | head -1)" >/dev/null 2>&1 || true
	fi
	DEBIAN_FRONTEND=noninteractive apt-get purge -y -qq otelcol-contrib >/dev/null 2>&1 || true
	rm -rf "$OTEL_ETC" "$OTEL_DROPIN" /etc/systemd/system/otelcol-contrib.service.d/docker.conf
	rmdir /etc/systemd/system/otelcol-contrib.service.d 2>/dev/null || true
	systemctl daemon-reload
	ok 'Removed'

	step 'ClickHouse'
	if [ "$purge" -eq 1 ]; then
		systemctl disable -q --now clickhouse-server 2>/dev/null || true
		DEBIAN_FRONTEND=noninteractive apt-get purge -y -qq clickhouse-server clickhouse-client clickhouse-common-static >/dev/null 2>&1 || true
		rm -rf /var/lib/clickhouse /var/log/clickhouse-server /etc/clickhouse-server /etc/clickhouse-client \
			/etc/apt/sources.list.d/clickhouse.list /usr/share/keyrings/clickhouse-keyring.gpg
		rm -f "$STATE/ch_writer" "$STATE/ch_reader" "$STATE/setup.env"
		rmdir "$STATE" 2>/dev/null || true
		ok 'Removed with its data, users and saved passwords'
	else
		ok 'Kept, with its data (remove it too with: uninstall --purge)'
	fi
}

# --- main -------------------------------------------------------------------------------------------------------------

command="${1:-}"
[ "$#" -gt 0 ] && shift

case "$command" in
	check | install | demo | shop | verify | status | uninstall) ;;
	*) sed -n '2,12p' "$0" | sed 's/^# \{0,1\}//'; exit 2 ;;
esac

[ "$(id -u)" -eq 0 ] || { echo "Run it with sudo: sudo $0 $command" >&2; exit 1; }

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
load_state

case "$command" in
	check) check; printf '\nAll good. Next: sudo ./scripts/setup.sh install\n' ;;
	install) install_all ;;
	demo) demo "$@" ;;
	shop) shop "$@" ;;
	verify) verify "$@" ;;
	status) status ;;
	uninstall) uninstall "$@" ;;
esac
