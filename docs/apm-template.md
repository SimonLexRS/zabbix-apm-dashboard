# The APM template: OpenTelemetry application by OTLP

[Español](apm-template.es.md)

[templates/otel_application_otlp.json](../templates/otel_application_otlp.json) is Zabbix's official
**Generic OpenTelemetry by OTLP** (Zabbix 8.0) limited to **one application**. Link it to one host per application and
set `{$OTLP.NAMESPACE}` on the host; every item reads only the telemetry whose resource attribute `service.namespace` has
that value. `scripts/zabbix-apm-app.py add <namespace>` does both.

## Why a derived template

| Official template | This template |
|---|---|
| No application filter: a host measures **all** the telemetry in ClickHouse | Condition `ResourceAttributes['service.namespace'] = {$OTLP.NAMESPACE}` on every Telemetry query item |
| On 8.0.0rc3, 10 of 16 items unsupported: `cannot extract value from json by path "$.columns.count"` | Results normalized (see below): all 16 supported |
| Duration items grouped by `Duration`: they reported the **shortest** span of the minute | Average of the minute, matching ClickHouse |

Both coexist (different names and UUIDs). The comparison with real data is in [verification.md](verification.md).

## What it measures

Every minute (`{$OTLP.FREQUENCY}`), over the 60 s (`{$OTLP.GRANULARITY}`) that end 10 s before the collection
(`{$OTLP.TIMESHIFT}`), so the collector's batches have arrived:

| Item | Meaning |
|---|---|
| Span: Ok / Error / Unset count | Spans per status. OpenTelemetry instrumentations leave successful spans as **Unset** (Ok is only set explicitly) and mark failures as **Error**, so *Unset* is the normal traffic. |
| Span: Ok / Error / Unset duration | Average duration of those spans, in seconds. *Unset duration* is the typical latency of the application. |
| Span: Error percentage | Error / (Ok + Error + Unset) × 100. |
| Logs: Info / Warning / Error / Fatal entries count | Log records per severity (from `Get logs`, one query). |
| Metrics: Count, Gauge count, Histogram count | Metric data points received: shows that metrics are arriving. |
| System: Uptime | `system.uptime` metric, if the application sends it. |

Counts are stored only when they change, with a 1 h heartbeat (the official template's *discard unchanged*): an
application with steady traffic shows few points in *Latest data*; graphs still draw the steady value.

## Triggers

| Trigger | Expression | Default |
|---|---|---|
| High span error rate | error percentage > `{$SPAN.ERROR.WARN}` | 5 (%) |
| Span duration is too big | `min(Ok duration, 5m)` > `{$SPAN.OK.DURATION.WARN}` | 0.5 s |
| Unset span duration is too big | `min(Unset duration, 5m)` > `{$SPAN.UNSET.DURATION.WARN}` | 1 s |
| Warning / Error / Fatal severity level entries in logs | count > `{$LOG.SEVERITY.WARN}` / `{$LOG.SEVERITY.ERROR}` / `{$LOG.SEVERITY.FATAL}` | 10 / 5 / 0 |

Tune them per application with host macros (e.g. `{$SPAN.UNSET.DURATION.WARN}` = `0.3` on a fast API). The interval
macros accept a signal context: `{$OTLP.FREQUENCY:traces}`, `:logs`, `:metrics`, `:gauge`, `:histogram`.

The template also brings a host dashboard (**Overview**: uptime, error %, duration by status, status distribution,
metric amount, problems, log severities): **Monitoring → Hosts → apm-&lt;app&gt; → Dashboards**.

## Generation and Zabbix upgrades

The template is generated, never edited by hand:

```
templates/upstream/generic_opentelemetry_by_otlp.json   (export of the official template from Zabbix)
        │  scripts/build-otlp-app-template.py
        ▼
templates/otel_application_otlp.json
```

The generator: adds the namespace condition to every Telemetry query item and the `{$OTLP.NAMESPACE}` macro; inserts a
JavaScript step that turns row results (`[{"count":55}]`, 8.0.0rc3) into the `{"columns":{"count":55}}` shape the
official paths read, and lets the other shape pass unchanged; makes "no rows" a 0 for counts and no value for
averages; drops raw columns that group the aggregated column; and gives deterministic version 4 UUIDs. The CI fails if
the committed template is not the generated one (`--check`).

After upgrading Zabbix, export the official template again (**Data collection → Templates →** select
*Generic OpenTelemetry by OTLP* **→ Export → JSON**), replace the upstream file and run:

```sh
python3 scripts/build-otlp-app-template.py
python3 scripts/zabbix-apm-app.py add <namespace>   # re-imports the template; repeat per application or just once
python3 scripts/verify-app.py <namespace>
```

If a Zabbix version does not resolve user macros in Telemetry query conditions, generate one template per application
with the value written in: `python3 scripts/build-otlp-app-template.py --namespace <namespace>`.
