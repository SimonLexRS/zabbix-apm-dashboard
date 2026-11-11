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
| An interval without data keeps the last value or leaves the item unsupported: problems never close | No data is a 0, so problems close by themselves; **No spans received** tells that the application went silent |

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
| Metrics: Count, Gauge count, Histogram count | Metric data points received: shows that metrics are arriving. Their values (orders, reservations...) are the [business KPIs](business-kpis.md), with their own template. |
| System: Uptime | `system.uptime` metric, if the application sends it. |

**An interval without data is a 0**, not a missing value: span counts, span durations, the error percentage (0 % with
no spans, instead of a division by zero) and log counts all go back to 0 when the application stops sending or stops
failing. That is what closes the problems. A duration of 0 therefore means "no spans of that status" (normal for *Ok*).
Log counts add up every `SeverityNumber` of their range (e.g. 17–20 for Error) in the newest interval.

Log and metric counts are stored only when they change, with a 1 h heartbeat (the official template's *discard
unchanged*); graphs still draw the steady value. Span counts store every value, because *No spans received* looks at a
period.

## Triggers

| Trigger | Expression | Default |
|---|---|---|
| High span error rate | error percentage > `{$SPAN.ERROR.WARN}` | 5 (%) |
| Span duration is too big | `min(Ok duration, 5m)` > `{$SPAN.OK.DURATION.WARN}` | 0.5 s |
| Unset span duration is too big | `min(Unset duration, 5m)` > `{$SPAN.UNSET.DURATION.WARN}` | 1 s |
| Warning / Error / Fatal severity level entries in logs | count > `{$LOG.SEVERITY.WARN}` / `{$LOG.SEVERITY.ERROR}` / `{$LOG.SEVERITY.FATAL}` | 10 / 5 / 0 |
| No spans received (Average) | the three span counts were 0 during `{$SPAN.NODATA}`, or the counts are not collected (`nodata`) | 10m |

Every problem closes by itself when its condition ends. *No spans received* opens when the application (or the
collector) stops sending and closes with the first interval that has spans again; while it is open the other values
are 0, so their problems are closed.

Each trigger has a link, **APM overview**, in the menu of the problem (click the problem name in **Monitoring →
Problems**): it opens the module on that application (`filter_app={$OTLP.NAMESPACE}`), on the **Errors** tab, on
**Slowness** for the duration triggers and on **Status** for *No spans received*.

Tune them per application with host macros (e.g. `{$SPAN.UNSET.DURATION.WARN}` = `0.3` on a fast API). The interval
macros accept a signal context: `{$OTLP.FREQUENCY:traces}`, `:logs`, `:metrics`, `:gauge`, `:histogram`.

The template also brings a host dashboard (**Overview**: uptime, error %, duration by status, status distribution,
metric amount, problems, log severities): **Monitoring → Hosts → apm-&lt;app&gt; → Dashboards**.

## Real user monitoring: the browser

The official template does not look at the browser. This one adds it, for applications whose pages send spans with the
OpenTelemetry web SDK (`telemetry.sdk.language = webjs`, see [rum.md](rum.md)); without them nothing is discovered.

| Discovery rule | Discovers | Items per row |
|---|---|---|
| Browser: pages | Service and page, from the page loads (`documentLoad`) | Page loads; errors of the page itself |
| Browser: requests | Service, host and path, from the requests of the browser (fetch, XMLHttpRequest) | Requests; failed requests |

| Trigger (Average) | Expression | Default |
|---|---|---|
| Browser: errors on the page *path* of *service* | `sum(errors, 5m)` > `{$BROWSER.ERRORS.MAX:"<path>"}` | 2 |
| Browser: requests to *host/path* fail in *service* | `sum(failed requests, 5m)` > `{$BROWSER.REQUESTS.FAILED.MAX:"<path>"}` | 3 |

- **Four Telemetry query items** read the browser spans grouped by service and URL (`url.full`, or `http.url` for
  requests): page loads, page errors (failed spans that are not requests, the rule the module uses), requests and
  failed requests. A JavaScript step turns the URLs into the pages the module shows: no query string, and the variable
  segments as `:id` (`/product/42` and `/product/57` are one page). At most 100 paths per query are kept, the busiest.
- Pages and requests are discovered **from all the traffic, not from the failures**: each has its count at 0 before it
  fails, and a minute without failures is a 0, so the problems close by themselves.
- Each trigger carries the tag **`service` = the browser service**, so the module shows the problem on that service
  and on **Real user monitoring → Errors**, and a link, **APM real user monitoring**, in the menu of the problem.
- The threshold takes the path as context: `{$BROWSER.ERRORS.MAX:"/checkout"}` = `0` opens on the first error there.
- Items of a page without visits stay enabled and are removed after 7 days.

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
official paths read, taking the newest interval when a result brings several, and lets the other shape pass unchanged;
makes "no rows" a 0 for counts and span durations (no value for uptime); adds up the log records of each severity range
instead of reading its first row; rewrites the error percentage so that it cannot divide by zero; adds *No spans
received*, `{$SPAN.NODATA}` and the trigger links; adds the browser items, discovery rules and
triggers (real user monitoring); drops raw columns that group the aggregated column; and gives
deterministic version 4 UUIDs. The CI fails if the committed template is not the generated one (`--check`).

After upgrading Zabbix, export the official template again (**Data collection → Templates →** select
*Generic OpenTelemetry by OTLP* **→ Export → JSON**), replace the upstream file and run:

```sh
python3 scripts/build-otlp-app-template.py
python3 scripts/zabbix-apm-app.py add <namespace>   # re-imports the template; repeat per application or just once
python3 scripts/verify-app.py <namespace>
```

If a Zabbix version does not resolve user macros in Telemetry query conditions, generate one template per application
with the value written in: `python3 scripts/build-otlp-app-template.py --namespace <namespace>`.
