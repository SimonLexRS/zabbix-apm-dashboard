# Installation

[Español](installation.es.md)

Once per Zabbix: storage, collector, data source, modules and template. Then each application is connected with
[add-application.md](add-application.md). Every step ends with a check; do not go on until it passes.

```
applications ──OTLP (4317 gRPC / 4318 HTTP)──▶ OpenTelemetry Collector ──▶ ClickHouse (database zabbix, tables otel_*)
                                                                                 ▲
     Zabbix server: Telemetry query items (template) ────────────────────────────┤
     Zabbix frontend: APM menu + these modules ──────────────────────────────────┘
```

Tested with: Zabbix 8.0.0rc3 (server, frontend, MariaDB), ClickHouse 26.9, otelcol-contrib 0.162 on Ubuntu 22.04, PHP
8.3 — see [verification.md](verification.md). Any host layout works: ClickHouse and the collector can run on the
Zabbix server (as tested) or on other machines.

## 1. ClickHouse

Install ClickHouse ([official guide](https://clickhouse.com/docs/install)) and create two users: a writer for the
collector and a read-only user for Zabbix. Edit the two passwords in a copy of the file, never in the repository:

```sh
cp examples/clickhouse-users.sql /tmp/users.sql   # replace <writer-password> and <reader-password>
clickhouse-client --multiquery < /tmp/users.sql && rm /tmp/users.sql
```

On a small VM shared with Zabbix (a few GB of RAM), also copy
[examples/clickhouse-low-memory.xml](../examples/clickhouse-low-memory.xml) to `/etc/clickhouse-server/config.d/` and
restart ClickHouse ([why](troubleshooting.md#memory_limit_exceeded)).

**Check:** `clickhouse-client --user zabbix_ro --password -q 'SHOW DATABASES'` lists `zabbix`.

## 2. OpenTelemetry Collector

Install **otelcol-contrib** (the contrib distribution includes the `clickhouse` exporter) from the
[releases](https://github.com/open-telemetry/opentelemetry-collector-releases/releases) (`.deb`/`.rpm`), copy
[examples/otelcol-config.yaml](../examples/otelcol-config.yaml) to `/etc/otelcol-contrib/config.yaml` and set:

- the writer user and password of step 1 in the `clickhouse` exporter;
- the address the receivers listen on: the interface your applications reach (private network, VPN or Tailscale).
  `0.0.0.0` exposes OTLP on every interface: only behind a firewall, and with TLS if it crosses an untrusted network.

```sh
sudo systemctl restart otelcol-contrib
```

On its first start the exporter creates the tables (`create_schema: true`); telemetry is kept 72 h (`ttl`).

**Check:**

```sh
systemctl is-active otelcol-contrib                         # active
ss -ltn | grep -E ':4317|:4318'                             # listening
clickhouse-client -q 'SHOW TABLES FROM zabbix'              # otel_traces, otel_logs, otel_metrics_*
```

A Zabbix proxy built with APM (`StartAPMCollectors=1`, `TelemetryProvider=clickhouse;…`) can take the collector's place;
the rest of this guide is the same.

## 3. Zabbix APM data source

**Administration → Data source → APM**: ClickHouse HTTP URL as seen from the Zabbix server and frontend (e.g.
`http://127.0.0.1:8123`), database `zabbix`, the read-only user (`zabbix_ro`). Use **Test** and save.

The APM pages, these modules and the Telemetry query items of the template all use this data source. If the template
items say the data source is not configured, set `TelemetryProvider` in the server configuration as well
([Zabbix documentation](https://www.zabbix.com/documentation/8.0/en/manual/concepts/server/server_params#telemetryprovider)).

**Check:** **APM → Traces** opens without errors (it is empty until an application sends data).

## 4. Frontend modules

```sh
git clone https://github.com/SimonLexRS/zabbix-apm-dashboard.git
cd zabbix-apm-dashboard
sudo ./scripts/install.sh            # finds /usr/share/zabbix/ui; ZABBIX_UI=/path overrides
```

The script checks the PHP syntax, backs up a previous version to `/var/backups/zabbix-apm-dashboard-<date>` and copies
`modules/*` into `<frontend>/modules`. Then **Administration → General → Modules → Scan directory** and enable:

- **APM Overview**: menu **APM → Overview**;
- **APM service map** and **APM service health**: dashboard widgets (the map needs APM Overview).

Users need access to the **APM → Traces** UI element (user role). Upgrades: run the script again. Removal:
`sudo ./scripts/install.sh --uninstall`.

**Check:** **APM → Overview → Status** shows the data source as OK.

## 5. Template

Import **OpenTelemetry application by OTLP** ([templates/otel_application_otlp.json](../templates/otel_application_otlp.json)):
**Data collection → Templates → Import**, or let `scripts/zabbix-apm-app.py add` import it when it creates the first
application host. How it works and how to tune it: [apm-template.md](apm-template.md).

Optional, for alerts per service and per endpoint: **APM services by ClickHouse HTTP v2**
([templates/apm_services_clickhouse_http.yaml](../templates/apm_services_clickhouse_http.yaml)), configured with macros:

| Macro | Value |
|---|---|
| `{$CH.URL}` | ClickHouse HTTP URL reachable from the Zabbix server or proxy, e.g. `http://127.0.0.1:8123/` |
| `{$CH.USER}` / `{$CH.PASSWORD}` | the read-only user (password as a secret macro) |
| `{$APM.SERVICE.MATCHES}` | services to monitor on this host, e.g. `^checkout-` (default: all) |
| `{$APM.MIN.REQUESTS}` | requests in 5 min before error/p95/Apdex triggers evaluate (default 20) |
| `{$APM.ERROR.WARN}` / `{$APM.ERROR.MAX}`, `{$APM.P95.WARN}` / `{$APM.P95.MAX}`, `{$APM.APDEX.MIN}` | thresholds; all accept a service context, e.g. `{$APM.P95.MAX:"checkout-api"}` |

## 6. End-to-end check (smoke test)

Without writing any code, with [telemetrygen](https://github.com/open-telemetry/opentelemetry-collector-contrib/tree/main/cmd/telemetrygen)
(Docker), an application host and the verification script:

```sh
export ZABBIX_URL=http://<zabbix>/zabbix ZABBIX_API_TOKEN=<token>   # User settings → API tokens (Super admin)
python3 scripts/zabbix-apm-app.py add smoke-test

docker run --rm ghcr.io/open-telemetry/opentelemetry-collector-contrib/telemetrygen:latest traces \
  --otlp-endpoint <collector>:4317 --otlp-insecure --duration 5m --rate 5 --child-spans 2 \
  --service smoke-api --otlp-attributes 'service.namespace="smoke-test"'

# after 3-4 minutes, from a machine that reaches Zabbix (and ClickHouse, for the comparison):
CH_URL=http://<clickhouse>:8123 CH_USER=zabbix_ro CH_PASSWORD=<reader-password> \
  python3 scripts/verify-app.py smoke-test
```

Expected: a **smoke-test** tab in **APM → Overview**, and `verify-app.py` ending with `all checks passed`. Then
delete the host `apm-smoke-test` (its data expires with the TTL).

Next: connect your applications — [add-application.md](add-application.md).
