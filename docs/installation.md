# Installation

From an application instrumented with OpenTelemetry to the APM pages, widgets and alerts in Zabbix 8.0.

```
application ──OTLP──▶ OpenTelemetry Collector ──▶ ClickHouse (zabbix.otel_*) ◀── Zabbix frontend (APM, these modules)
                       (or Zabbix proxy APM)                                  ◀── template items (ClickHouse HTTP)
```

## 1. ClickHouse

Install ClickHouse (https://clickhouse.com/docs/install) and create a writer for the collector and a read-only user for
Zabbix, replacing the passwords:

```sh
clickhouse-client --multiquery < examples/clickhouse-users.sql
```

On a small VM shared with Zabbix, add [examples/clickhouse-low-memory.xml](../examples/clickhouse-low-memory.xml) to
`/etc/clickhouse-server/config.d/` and restart ClickHouse (see [troubleshooting](troubleshooting.md#memory_limit_exceeded)).

## 2. OpenTelemetry Collector

Install `otelcol-contrib` and use [examples/otelcol-config.yaml](../examples/otelcol-config.yaml) as
`/etc/otelcol-contrib/config.yaml`, with the writer user of step 1. On the first start the `clickhouse` exporter creates
`zabbix.otel_traces`, `otel_logs` and `otel_metrics_*` (`create_schema: true`, kept for `ttl: 72h`).

A Zabbix proxy built with APM (`StartAPMCollectors=1`, `TelemetryProvider=clickhouse;…`) can take the collector's place.

## 3. Instrument the applications

Any OpenTelemetry SDK or auto-instrumentation works; point it to the collector:

```sh
OTEL_SERVICE_NAME=checkout
OTEL_EXPORTER_OTLP_ENDPOINT=http://<collector>:4317
OTEL_EXPORTER_OTLP_PROTOCOL=grpc
```

What the views use:
- server spans (`SpanKind = Server`) with a route in the span name (e.g. `GET /orders/{id}`) for endpoints;
- client spans with `db.system.name` or `db.system` for databases, and `server.address` / `url.full` for external calls;
- trace context propagation between services (W3C `traceparent`, the SDK default) for service-to-service arrows;
- optional: Web Vitals from browsers as log records ([rum.md](rum.md)).

## 4. Zabbix data source

In **Administration → Data source → APM**: ClickHouse URL (e.g. `http://127.0.0.1:8123`), database `zabbix` and the
read-only user. **APM → Traces** must list traces before going on.

## 5. Modules

```sh
git clone https://github.com/SimonLexRS/zabbix-apm-dashboard.git
cd zabbix-apm-dashboard
sudo ./scripts/install.sh            # finds /usr/share/zabbix/ui; ZABBIX_UI=/path overrides
```

The script checks the PHP syntax, backs up a previous version to `/var/backups/zabbix-apm-dashboard-<date>` and copies
`modules/*` into `<frontend>/modules`. Then **Administration → General → Modules → Scan directory** and enable:

- **APM Overview**: menu **APM → Overview**;
- **APM service map** and **APM service health**: widgets for dashboards (the map needs APM Overview).

Users need access to the **APM → Traces** UI element (user role). Upgrades: run the script again; no new scan is needed
unless the release notes say so. Removal: `sudo ./scripts/install.sh --uninstall`.

## 6. Template (alerts and history)

**Data collection → Templates → Import** `templates/apm_services_clickhouse_http.yaml`, link
**APM services by ClickHouse HTTP v2** to a host and set its macros:

| Macro | Value |
|---|---|
| `{$CH.URL}` | ClickHouse HTTP URL reachable from the Zabbix server or proxy, e.g. `http://127.0.0.1:8123/` |
| `{$CH.USER}` / `{$CH.PASSWORD}` | the read-only user (password as a secret macro) |
| `{$APM.SERVICE.MATCHES}` | services to monitor on this host, e.g. `^checkout-` (default: all) |
| `{$APM.MIN.REQUESTS}` | requests in 5 min before error/p95/Apdex triggers evaluate (default 20) |
| `{$APM.ERROR.WARN}` / `{$APM.ERROR.MAX}`, `{$APM.P95.WARN}` / `{$APM.P95.MAX}`, `{$APM.APDEX.MIN}` | thresholds; all accept a service context, e.g. `{$APM.P95.MAX:"checkout-api"}` |

Three HTTP items query ClickHouse once a minute; services, endpoints and connections are discovered from them.

## 7. Check

- **APM → Overview → Status**: data source "OK" and traces "Receiving".
- **Service map**: users → your services → databases.
- Template host: no unsupported items after two minutes; `APM <service>: Health` = Healthy.
