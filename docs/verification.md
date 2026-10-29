# Verification

[Español](verification.es.md)

What was checked, how, and the result, so that "it works" is a reproducible fact and not a claim. Repeat it in your
environment with `scripts/verify-app.py` (last section).

## Tested environment (2026-10-29)

| Component | Version |
|---|---|
| Zabbix server, frontend (MariaDB) | 8.0.0rc3, Ubuntu 22.04, PHP 8.3 |
| ClickHouse | 26.9 |
| OpenTelemetry Collector (Zabbix side) | otelcol-contrib 0.162, `clickhouse` exporter |
| OpenTelemetry Collector (OpenTelemetry Demo) | otelcol-contrib 0.160 |
| Go example | Go 1.27, OpenTelemetry Go 1.47 |

Four applications, each a different way of sending telemetry:

| Application (`service.namespace`) | Stack | Path to the Zabbix collector |
|---|---|---|
| `opentelemetry-demo` | [OpenTelemetry Demo](../examples/stacks/opentelemetry-demo/README.md): ~20 services in 10 languages, Docker Desktop | application collector → OTLP/gRPC over a VPN (Tailscale) |
| `flowspock` | Go API and web application in production, Docker on a remote VPS | SDK → OTLP/gRPC over a VPN (Tailscale) |
| `go-example` | [examples/stacks/go](../examples/stacks/go/README.md), Docker | SDK → OTLP/gRPC |
| `smoke-test` | `telemetrygen` ([installation.md step 6](installation.md#6-end-to-end-check-smoke-test)) | OTLP/gRPC, no code |

## What was verified

1. **The template measures one application.** Telemetry query items carry the condition
   `ResourceAttributes['service.namespace'] = {$OTLP.NAMESPACE}` and the host's user macro is resolved: same query, same
   minute, 7,138 spans with the macro set to `opentelemetry-demo` and 7,433 for all applications (ClickHouse: about
   7,230 and 7,290 per minute).
2. **The official template does not work as is on 8.0.0rc3.** "Generic OpenTelemetry by OTLP" linked unchanged to a
   test host: 10 of 16 items unsupported with `cannot extract value from json by path "$.columns.count"` (the server
   returns rows, `[{"clock":…,"count":55}]`). Its duration items also had `Duration` as a raw column, which groups the
   result, so they reported the shortest span of the minute (0 s instead of 0.045 s for Unset spans). The generated
   template fixes both: 16/16 items supported, values equal to ClickHouse.
3. **Values match ClickHouse, per application.** `verify-app.py` compares, minute by minute over the last 10 minutes,
   the item values with `count()` and `avg(Duration)` computed directly in ClickHouse for the namespace, and checks that
   they do **not** match the total of all applications. Output for the four applications:

```text
$ python3 scripts/verify-app.py opentelemetry-demo
OK   host apm-opentelemetry-demo exists
OK   host is enabled
OK   template "OpenTelemetry application by OTLP" is linked
OK   {$OTLP.NAMESPACE} = 'opentelemetry-demo'
OK   16 items, unsupported: none
OK   span items receive values (newest 36 s ago, heartbeat 3600 s)
OK   Ok    spans/min over 10 min: Zabbix 59.5, ClickHouse 59.0 for opentelemetry-demo (59.0 all applications)
OK   Ok    average duration: Zabbix 0.0000 s, ClickHouse 0.0000 s
OK   Error spans/min over 10 min: Zabbix 4.2, ClickHouse 4.2 for opentelemetry-demo (79.0 all applications)
OK   Error filtered by namespace (not the total of all applications)
OK   Error average duration: Zabbix 4.0572 s, ClickHouse 4.0572 s
OK   Unset spans/min over 10 min: Zabbix 6,703.3, ClickHouse 6,697.0 for opentelemetry-demo (7,229.7 all applications)
OK   Unset average duration: Zabbix 0.0468 s, ClickHouse 0.0503 s
---- all checks passed

$ python3 scripts/verify-app.py flowspock
OK   host apm-flowspock exists
OK   host is enabled
OK   template "OpenTelemetry application by OTLP" is linked
OK   {$OTLP.NAMESPACE} = 'flowspock'
OK   16 items, unsupported: none
OK   span items receive values (newest 112 s ago, heartbeat 3600 s)
OK   Ok    spans/min over 10 min: Zabbix 0.0, ClickHouse 0.0 for flowspock (59.0 all applications)
OK   Error spans/min over 10 min: Zabbix 0.0, ClickHouse 0.0 for flowspock (79.0 all applications)
OK   Unset spans/min over 10 min: Zabbix 63.5, ClickHouse 63.5 for flowspock (7,229.7 all applications)
OK   Unset filtered by namespace (not the total of all applications)
OK   Unset average duration: Zabbix 0.0036 s, ClickHouse 0.0036 s
---- all checks passed

$ python3 scripts/verify-app.py go-example
OK   host apm-go-example exists
OK   host is enabled
OK   template "OpenTelemetry application by OTLP" is linked
OK   {$OTLP.NAMESPACE} = 'go-example'
OK   16 items, unsupported: none
OK   span items receive values (newest 21 s ago, heartbeat 3600 s)
OK   Ok    spans/min over 10 min: Zabbix 0.0, ClickHouse 0.0 for go-example (59.0 all applications)
OK   Error spans/min over 10 min: Zabbix 75.6, ClickHouse 74.8 for go-example (79.0 all applications)
OK   Error average duration: Zabbix 0.0003 s, ClickHouse 0.0003 s
OK   Unset spans/min over 10 min: Zabbix 356.6, ClickHouse 357.0 for go-example (7,229.7 all applications)
OK   Unset filtered by namespace (not the total of all applications)
OK   Unset average duration: Zabbix 0.1024 s, ClickHouse 0.1033 s
---- all checks passed

$ python3 scripts/verify-app.py smoke-test
OK   host apm-smoke-test exists
OK   host is enabled
OK   template "OpenTelemetry application by OTLP" is linked
OK   {$OTLP.NAMESPACE} = 'smoke-test'
OK   16 items, unsupported: none
OK   span items receive values (newest 125 s ago, heartbeat 3600 s)
OK   Ok    spans/min over 4 min: Zabbix 0.0, ClickHouse 0.0 for smoke-test (58.0 all applications)
OK   Error spans/min over 4 min: Zabbix 0.0, ClickHouse 0.0 for smoke-test (69.5 all applications)
OK   Unset spans/min over 4 min: Zabbix 283.5, ClickHouse 251.2 for smoke-test (7,452.2 all applications)
OK   Unset filtered by namespace (not the total of all applications)
OK   Unset average duration: Zabbix 0.0002 s, ClickHouse 0.0002 s
---- all checks passed
```

4. **Onboarding is reproducible and idempotent.** All four hosts were created by `zabbix-apm-app.py add` (template
   import, host group, host and macro); running it again updates instead of duplicating.
5. **The OpenTelemetry Demo add-on works as documented.** The demo was restarted with
   [compose.zabbix.yaml](../examples/stacks/opentelemetry-demo/compose.zabbix.yaml) and
   [otelcol-config-zabbix.yml](../examples/stacks/opentelemetry-demo/otelcol-config-zabbix.yml) copied unchanged:
   14 services in the first 40 seconds, all with the namespace (including `frontend-web`, which does not set it).
6. **The frontend modules**: PHP lint, `php tests/run.php` (patterns, service map, application selection, translation
   coverage) and installation with `scripts/install.sh` on the tested Zabbix; one tab per application in APM Overview.

## How to read the checks

- A value collected at minute T measures minute T−1 (`{$OTLP.GRANULARITY}` 1m, `{$OTLP.TIMESHIFT}` 10s); the script
  aligns both series that way.
- The template stores counts only when they change (heartbeat 1 h): the script carries the last value forward, as
  Zabbix does.
- Tolerance 20 %: the first minutes of a new host and spans that arrive after the 10 s time shift make small
  differences (smoke-test above: 4 minutes of data).

## Repeat it in your environment

```sh
export ZABBIX_URL=http://<zabbix>/zabbix ZABBIX_API_TOKEN=<token>
export CH_URL=http://<clickhouse>:8123 CH_USER=zabbix_ro CH_PASSWORD=<reader-password>   # optional
python3 scripts/verify-app.py <namespace>
```

Without `CH_URL` it checks the Zabbix side only (host, template, macro, supported items, fresh values). Exit code 0
when every check passes, so it can run in CI or from cron.
