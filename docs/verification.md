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
3. **Values match ClickHouse, per application.** `verify-app.py` compares each item value of the last 10 minutes with
   `count()` and `avg(Duration)` computed directly in ClickHouse for the namespace, over exactly the seconds that value
   covers, and checks that they do **not** match the total of all applications. Output with the live applications
   (`smoke-test` passed the same checks with the previous version of the script; its data has expired):

```text
$ python3 scripts/verify-app.py opentelemetry-demo
OK   host apm-opentelemetry-demo exists
OK   host is enabled
OK   template "OpenTelemetry application by OTLP" is linked
OK   {$OTLP.NAMESPACE} = 'opentelemetry-demo'
OK   16 items, unsupported: none
OK   span items receive values (newest 4 s ago, heartbeat 3600 s)
OK   Ok    spans per 60 s, 10 values: Zabbix 62.4, ClickHouse 62.4 for opentelemetry-demo (62.4 all applications)
OK   Ok    average duration: Zabbix 0.0000 s, ClickHouse 0.0000 s
OK   Error spans per 60 s, 10 values: Zabbix 4.1, ClickHouse 4.1 for opentelemetry-demo (71.3 all applications)
OK   Error filtered by namespace (not the total of all applications)
OK   Error average duration: Zabbix 3.9671 s, ClickHouse 3.9671 s
OK   Unset spans per 60 s, 10 values: Zabbix 6,760.8, ClickHouse 6,761.4 for opentelemetry-demo (7,188.4 all applications)
OK   Unset average duration: Zabbix 0.0474 s, ClickHouse 0.0513 s
---- all checks passed

$ python3 scripts/verify-app.py flowspock
OK   host apm-flowspock exists
OK   host is enabled
OK   template "OpenTelemetry application by OTLP" is linked
OK   {$OTLP.NAMESPACE} = 'flowspock'
OK   16 items, unsupported: none
OK   span items receive values (newest 200 s ago, heartbeat 3600 s)
OK   Ok    spans per 60 s, 10 values: Zabbix 0.0, ClickHouse 0.0 for flowspock (62.1 all applications)
OK   Error spans per 60 s, 10 values: Zabbix 0.0, ClickHouse 0.0 for flowspock (72.8 all applications)
OK   Unset spans per 60 s, 10 values: Zabbix 56.2, ClickHouse 56.2 for flowspock (7,120.8 all applications)
OK   Unset filtered by namespace (not the total of all applications)
OK   Unset average duration: Zabbix 0.0034 s, ClickHouse 0.0034 s
---- all checks passed

$ python3 scripts/verify-app.py go-example
OK   host apm-go-example exists
OK   host is enabled
OK   template "OpenTelemetry application by OTLP" is linked
OK   {$OTLP.NAMESPACE} = 'go-example'
OK   16 items, unsupported: none
OK   span items receive values (newest 48 s ago, heartbeat 3600 s)
OK   Ok    spans per 60 s, 10 values: Zabbix 0.0, ClickHouse 0.0 for go-example (60.7 all applications)
OK   Error spans per 60 s, 10 values: Zabbix 68.4, ClickHouse 68.4 for go-example (72.6 all applications)
OK   Error average duration: Zabbix 0.0003 s, ClickHouse 0.0003 s
OK   Unset spans per 60 s, 10 values: Zabbix 369.4, ClickHouse 369.4 for go-example (7,180.5 all applications)
OK   Unset filtered by namespace (not the total of all applications)
OK   Unset average duration: Zabbix 0.0916 s, ClickHouse 0.0916 s
---- all checks passed
```

4. **Onboarding is reproducible and idempotent.** All the hosts were created by `zabbix-apm-app.py add` (template
   import, host group, host and macro); running it again updates instead of duplicating.
5. **The OpenTelemetry Demo add-on works as documented.** The demo was restarted with
   [compose.zabbix.yaml](../examples/stacks/opentelemetry-demo/compose.zabbix.yaml) and
   [otelcol-config-zabbix.yml](../examples/stacks/opentelemetry-demo/otelcol-config-zabbix.yml) copied unchanged:
   14 services in the first 40 seconds, all with the namespace (including `frontend-web`, which does not set it).
6. **The frontend modules**: PHP lint, `php tests/run.php` (patterns, service map, application selection, translation
   coverage) and installation with `scripts/install.sh` on the tested Zabbix; one tab per application in APM Overview.

7. **A second Zabbix, from a clean clone.** The repository cloned into an empty folder and [lab.md](lab.md) followed
   as written: Zabbix 8.0.0rc3 from the official Docker images (PostgreSQL, PHP 8.5), ClickHouse 26.9, otelcol-contrib
   0.162. The modules were enabled and the data source set through the screens of
   [install-module.md](install-module.md) (its screenshots come from this run), then `lab-bootstrap.py` ran over that
   without changing anything. `go-example` passed `verify-app.py`, and so did a second application connected from
   outside the compose file as described in "Connect your own application to the lab":

```text
$ python3 scripts/verify-app.py my-app
OK   host apm-my-app exists
OK   host is enabled
OK   template "OpenTelemetry application by OTLP" is linked
OK   {$OTLP.NAMESPACE} = 'my-app'
OK   16 items, unsupported: none
OK   span items receive values (newest 25 s ago, heartbeat 3600 s)
OK   Ok    spans per 60 s, 10 values: Zabbix 0.0, ClickHouse 0.0 for my-app (0.0 all applications)
OK   Error spans per 60 s, 10 values: Zabbix 72.0, ClickHouse 72.0 for my-app (141.8 all applications)
OK   Error filtered by namespace (not the total of all applications)
OK   Error average duration: Zabbix 0.0003 s, ClickHouse 0.0003 s
OK   Unset spans per 60 s, 10 values: Zabbix 366.8, ClickHouse 366.8 for my-app (729.0 all applications)
OK   Unset filtered by namespace (not the total of all applications)
OK   Unset average duration: Zabbix 0.0881 s, ClickHouse 0.0881 s
---- all checks passed
```

8. **Roles and user groups.** In the same lab, with `go-example` and `my-app` sending, three users opened every tab
   of APM Overview (`apm.overview.data`, the 8 tabs) and both widgets with an empty *Application*:

   | User | Setting | Application tabs | `my-app` data anywhere | Logs figures and links |
   |---|---|---|---|---|
   | Admin | Super admin | go-example, my-app | yes | yes |
   | go-viewer | User role; user group with APM filter `service.namespace = go-example` (All) | go-example only, also when the URL asks for `filter_app=my-app` | no (page and both widgets) | yes |
   | nolog-viewer | role without *APM → Logs*; no APM filter | go-example, my-app | yes | no |

   No PHP warnings and no ClickHouse errors in any tab. `php tests/run.php` checks the condition built from the
   filters (values always passed as query parameters).

9. **Real user monitoring.** On the tested Zabbix, with the OpenTelemetry Demo frontend sending:
   - **Figures:** the Overview showed 913 sessions, 912 page loads, 1,341 actions and 20,069 browser requests for the last hour. A direct ClickHouse count one second later gave 913, 913, 1,344 and 20,093; the difference is spans still arriving at the edge of the window.
   - **How it works checks:** 4,944 of 4,944 request traces continued in a backend service.
   - **Session timeline:** each request showed its backend path, e.g. `GET /api/cart → frontend-proxy → frontend → cart → flagd`.
   - **Lab (PostgreSQL, PHP 8.5):** browser spans of two applications were sent, `web-a` and `web-b`. A user whose group has the APM filter `service.namespace = web-a` saw only the `web-a` tab and its session, also when the URL asked for `web-b`. Super admin saw both. No PHP warnings.

## How to read the checks

- A value collected at time T counts the spans of [T − 70 s, T − 10 s) (`{$OTLP.GRANULARITY}` 1m,
  `{$OTLP.TIMESHIFT}` 10s; the script reads both macros from the host). The collection second depends on the item, not
  on the start of the minute, so the script compares each value with exactly those seconds in ClickHouse.
- The template stores counts only when they change (heartbeat 1 h): the script carries the last value forward, as
  Zabbix does.
- Tolerance 20 %: only spans that reach ClickHouse after the 10 s time shift make a difference.

## Repeat it in your environment

```sh
export ZABBIX_URL=http://<zabbix>/zabbix ZABBIX_API_TOKEN=<token>
export CH_URL=http://<clickhouse>:8123 CH_USER=zabbix_ro CH_PASSWORD=<reader-password>   # optional
python3 scripts/verify-app.py <namespace>
```

Without `CH_URL` it checks the Zabbix side only (host, template, macro, supported items, fresh values). Exit code 0
when every check passes, so it can run in CI or from cron.
