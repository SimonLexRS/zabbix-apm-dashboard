# Changelog

## 2.5.0

APM and Real user monitoring in one path, in both directions, and errors that know when they are solved.

**From the browser to the backend and back**

- **Trace panel.** Every trace link of the module opens the trace in a side panel instead of leaving the page: the
  spans from the browser to the databases, a switch to see only the ones that failed, the services of the trace with
  the ones that failed marked, its log records, the Zabbix host of the application (problems, latest data), and
  **where it came from**: the browser session, the user, the page and the browser, with a link to the session timeline
  at that request and to the page in Real user monitoring. The native trace viewer is one click away.
- **Real user monitoring → Errors** (new tab): errors of the page itself (uncaught exceptions, rejected promises) and
  requests that fail, grouped, with the page, the sessions affected and the trace down to the backend service that
  failed. The browser guide now captures those errors and adds `view.id` and `view.path` to every span.
- **Session timeline:** each page load opens into a waterfall of everything that page loaded and asked for; each
  request shows how much of its time was the backend and how much the network; page errors are rows of the timeline.
  A link can open a session at one request (`session`, `trace`). Sessions can be searched by ID.
- **APM overview:** errors and traces that started in a browser say so, with the number of sessions affected.

**Errors with a state**

- Error groups are now issues: one per service, operation, kind of exception and message, with the numbers and ids of
  the message replaced by `?`, and counted once, at the deepest span that failed (before, the same failure appeared
  once per service it crossed, and many groups had no message: it now comes from the exception event when the span
  has no status message). Errors that only exist in client spans are included.
- Each group is **New**, **Active**, **Came back** or **Resolved**, worked out from when it happened: nothing is
  stored and nobody has to close anything. Resolved groups are dimmed, or hidden with the filter.
- The **service map** and *Needs attention* take their colors from the last minutes of the period (filter: *"Now"
  window*, 15 by default), so a problem that was fixed stops being red within minutes instead of staying until it
  leaves the period. The tooltips show both figures.
- **Template** `OpenTelemetry application by OTLP`: problems close by themselves. Log counts per severity, span
  counts, durations and the error percentage become 0 when nothing arrives (they kept their last value, or became
  unsupported, and the problem stayed open for ever); a severity range adds up all its rows; new trigger
  *No spans received* (`{$SPAN.NODATA}`, 10m); every trigger links to the APM overview of its application. Re-import
  it with `zabbix-apm-app.py add <application>`.

**Fixed**

- A service that is only called by other services (most of them) showed empty tables when picked on the map: Services,
  Origin of slowness, Apdex and the histograms only counted the first span of each trace. They now count the requests
  each service receives, whoever sends them; the health widget does the same.
- Consumers, timers and other background work that start their own trace were drawn as traffic from *Users*, and
  counted as incoming traffic.
- A service with a few requests of its own lost the calls it received from other services in its node figures.
- Streams and long polls (spans of 5 minutes or more, filter *Open connections from*) no longer decide the slowest
  service, the p95, the Apdex or the time breakdown; they are still counted.
- External hosts without dots in their name (`http://payments:8080`, as inside Docker or Kubernetes) appeared as
  *(unknown)*: the host is now read from the URL, the Envoy cluster, `peer.service`, `rpc.service` and the peer
  address. Queues (Kafka, RabbitMQ...) are drawn as queues, not as databases.
- The service picked on the map was saved as the page filter, so it followed you to every tab and to other browser
  tabs. It now lasts for that view only; the filter form is the only thing that saves.
- A fixed period is not refreshed (it could not change) and says so; a block whose refresh failed is shown as old.
- The filter of the map with `?` or `[` in a name; `=name` in the health widget; the latest trace of a group of failing
  requests is now one that failed; the map and the error list say when they were cut by their limits.
- `web-vitals.js` sends `service.namespace` (its Web Vitals never reached the page of the application);
  `zabbix-apm-app.py` and `verify-app.py` close the session they open; `verify-app.py` accepts `1.5m`.
- `test-rum.py --send-error <OTLP URL>` sends a browser session that fails, to see all of the above without breaking
  anything.

Not part of OpenTelemetry data, so not here: session replay, code profiling, network performance data and mobile
SDKs. Errors are not assigned or closed by hand: their state follows the data.

## 2.4.0

- **Installer** `scripts/setup.sh` for Ubuntu and Debian: `check` (prerequisites, changes nothing), `install`
  (ClickHouse, Collector, modules, APM data source and a smoke test), `demo <IP>` (the OpenTelemetry Demo store in
  Docker and its host), `verify`, `status` and `uninstall [--purge]`. Every command can be run again; it waits for each
  service instead of sleeping, detects the frontend, the API address and the Docker bridge, and loads the Collector's
  Docker configuration from a systemd drop-in, so the Collector no longer stops with Docker.
- The step-by-step guide is now those commands, with the prerequisites in one table (it was 440 lines of commands).
- `install.sh --where` prints the frontend directory it would use.

## 2.3.1

- **Example applications** ([examples/demo-apps](examples/demo-apps/README.md)): the Go example, the store and the hotel
  chain in one `compose.yaml`, sending to the Collector of Zabbix on the host and restarting with it, with a guide of
  what each one shows, how to integrate them (hosts, KPIs, dashboards), what you see, how to compare the figures and
  how to take each part to your own application.
- `verify-app.py <app> --kpi` checks the business KPIs: template and macro, both queries answering, items discovered
  and `today` of every metric and value against ClickHouse. It no longer fails on hosts with secret macros.
- `kpi-dashboard`: the widgets match the visible host name (the pie charts showed *No data*), the stores or hotels are
  ordered from largest to smallest with their share in a widget of their own, and the trend per hour adds up the
  values per minute, so it has data from the first hour.
- Template 1.2: discovery runs at least every 15 minutes (it was 1 hour), so an update of the template reaches the
  discovered items soon.
- `kpi-demo.py` takes `KPI_DEMO_APP` and `OTLP_ENDPOINT` from the environment and stops cleanly with `docker stop`.

## 2.3.0

- **Totals to compare**, from ClickHouse, so they are complete as soon as the items exist (before, `today` and
  `yesterday` added up the history of Zabbix and only counted since the item was created): `last hour`, `last 24 hours`,
  `today`, `yesterday` (with 400 days of history: one total per day) and, for histograms, the sum and average of today
  and yesterday. New master item `apm.kpi.totals.get` (every `{$APM.KPI.TOTALS.INTERVAL}`, 2m); days in
  `{$APM.KPI.TIMEZONE}`. The items keep their keys, so their history stays.
- `total (counter)`: the value the counter of the application reports, to compare with the application itself.
- `share today` and `rank today` for each value of an attribute: the % and the position of each store or hotel.
- `last 7 days`, from the `per hour` values.
- **Availability:** the gauges (rooms of each hotel, rooms free, occupancy, stock, products out of stock) are discovered
  like the rest, as current values; a percentage is averaged in the total of the metric instead of added up. The guide
  explains how to send them, and `kpi-demo.py` sends them for its hotels and stores.
- `zabbix-apm-app.py kpi-dashboard <app>`: dashboard to compare the values of each attribute (pie chart of today, top
  items with today, share, rank and yesterday, graph per hour), and the current levels.
- The Business KPIs tab shows the share of each value next to it.

## 2.2.0

- **Business KPIs:** the business metrics that an application sends over OpenTelemetry (orders by store, reservations by
  hotel, payments by currency) become visible and Zabbix items ([docs/business-kpis.md](docs/business-kpis.md)).
  - New tab **APM → Overview → Business KPIs**: each counter, up-down counter, gauge and histogram of the application,
    except the instrumentation ones, with its total of the period, per minute, trend and breakdown by attribute.
  - New template **APM business metrics by ClickHouse HTTP**: one query per minute discovers every metric and every
    value of its attributes, with items per minute, last hour, today and yesterday (histograms: sum and average), tagged
    `component: kpi` so that the assistant of zabbix-mcp finds them. Cumulative counters are measured by differences,
    with restarts and several instances; gauges ignore instances that stopped.
  - `scripts/zabbix-apm-app.py add <app> --kpi` links it and sets its macros (ClickHouse from `CH_URL`, `CH_USER`,
    `CH_PASSWORD`, the password as a secret macro); `list` shows which hosts have it.
  - `scripts/kpi-demo.py`: a store and a hotel chain with business metrics and a span per event, to try it.
  - The Go example counts orders and revenue by store (`POST /order?store=Centro`).
- `scripts/zabbix-apm-app.py`: `--timeshift` was read but never set; secret macros of the host keep their value on
  updates.

- **Installation guide rewritten:** shorter, with how APM works, what the modules add and a check per step.
- `scripts/enable-modules.py` registers and enables the modules through the API, with an API token.
- `scripts/install.sh` finds the frontend in more paths and asks for `ZABBIX_UI` when it finds several.
- Fixed `examples/clickhouse-users.sql`: the writer user needs `CREATE DATABASE`, or the collector does not start.
- OpenTelemetry Demo guide tested step by step on Ubuntu, next to Zabbix: `compose.zabbix-lite.yaml` (without Jaeger, Grafana, Prometheus and OpenSearch), `otelcol-docker-bridge.yaml`, and the problems found.
- **Step-by-step guide for Ubuntu**, run from scratch: [docs/ubuntu-step-by-step.md](docs/ubuntu-step-by-step.md).
- Real user monitoring guide: how it works, how to implement it and how to test it, with `scripts/test-rum.py`.
- `scripts/zabbix-apm-app.py add --timeshift` sets `{$OTLP.TIMESHIFT}`, needed when spans cross two collectors.

## 2.1.1

- Real user monitoring:
  - **A request fails on a 5xx or when there is no response.** A 4xx (e.g. 401 before signing in) is counted apart and shown in amber, and the timeline no longer blames the backend service that answered it.
  - **One action per click.** The user interaction instrumentation adds a nested span per event listener; only the root one counts. With React, every click used to count several times.

## 2.1.0

- Real user monitoring lists every application again; the ones without browser telemetry are muted and offer
  **Connect the browser**.
- How it works is an interactive guide with the application filled in. There are three methods with the same tracing
  code:
  - without a build step: one `<script>` block that loads the official OpenTelemetry web SDK from a CDN, with pinned
    versions;
  - npm / bundler;
  - Next.js (`instrumentation-client.ts`).

  It also covers how the browser reaches the collector (CORS or a path of the site) and how to link the requests to
  the backend, with live checks below it.
- The tracing code keeps the default resource of the SDK, so the spans carry `telemetry.sdk.language = webjs`.
- Lab: `web-example` (http://localhost:8089), a page instrumented with that snippet, calling the Go example through
  the same origin. Its sessions reach `go-example-api` in the timeline.
- Session timeline: at the same instant, the action comes before the request it made.

## 2.0.0

- New page **APM → Real user monitoring**, built from the spans of the OpenTelemetry web SDK
  ([docs/rum.md](docs/rum.md)). One tab per application with browser telemetry, real time, roles and APM filter
  respected.
  - **Overview**: sessions, users, page loads, user actions, browser requests and failures, first content visible, Core
    Web Vitals, activity per minute, slowest pages, requests that fail, browsers and operating systems.
  - **Pages**: load p75/p95 and first content visible per page (ids folded into `:id`), and the load waterfall of the
    typical and the slowest load of a page.
  - **Resources**: resources by kind and host with their network phases; browser requests by method, path and status
    with their latest trace.
  - **Sessions**: the most recent sessions, and the timeline of a session in a side panel: page loads, actions and
    requests, each request with the backend services of its trace, the one that failed and a link to the trace.
  - **How it works**: how to instrument a web application, with live checks.
- APM Overview: an application never chosen no longer opens the tab of the spans without a namespace.
- User experience links to the new page.

## 1.9.0

- The modules respect Zabbix permissions the way the native APM pages do:
  - the **APM filter** of the user's groups (Users → User groups → APM filter) now limits the application tabs, every
    tab of APM Overview, both widgets (also with an empty *Application*) and the spans of slow traces; Super admins are
    not filtered;
  - logs and metrics figures and links are shown only to roles with *APM → Logs* / *APM → Metrics*.

  Before, any user with *APM → Traces* saw the telemetry of every application.
- Docs: "Who sees what" in [docs/install-module.md](docs/install-module.md#step-4-see-data-and-give-access), with the APM filter of a user
  group.

## 1.8.0

- Lab (`examples/lab`, `scripts/lab-bootstrap.py`, [docs/lab.md](docs/lab.md)): Zabbix 8.0, ClickHouse, a collector, the
  modules and the instrumented Go example in Docker, configured through the API in one command. Used to check that the
  documentation works from a clean clone on a Zabbix other than the one it was written on.
- [docs/install-module.md](docs/install-module.md): installing the modules screen by screen, with screenshots
  (packages and Docker, Scan directory, data source, role, widgets, update and removal).
- `zabbix-apm-app.py` and `verify-app.py` also accept `ZABBIX_USER` / `ZABBIX_PASSWORD` instead of an API token.
- `verify-app.py` compares each item value with exactly the seconds it covers (60 s ending 10 s before the collection,
  read from `{$OTLP.GRANULARITY}` and `{$OTLP.TIMESHIFT}`) instead of calendar minutes. Values now match ClickHouse
  exactly, and an application that has just started no longer fails the check.
- Docs: the user role also needs the three modules under *Access to modules*; how a container outside the lab reaches
  its collector.
- PHP 8.5: the deprecation notice of `curl_close()` raised by the ClickHouse client of the Zabbix frontend
  (`ApmDbClickHouse`) is no longer shown on the module pages and widgets.
- README: how to fork and replicate the project.

## 1.7.0

- Service map (page and widget) redrawn to be read at a glance: readable size (it scrolls instead of shrinking the text),
  fewer crossing arrows (barycenter ordering), arrows colored by health and as thick as their share of the traffic,
  moving particles showing the live flow (faster = more calls, red where there are errors; they stop with Pause and with
  reduced motion), labels only on problem connections, nodes with a health bar, a kind icon and the guilty figure in
  red. Hovering a service or an arrow lights its path and shows its figures.
- Above the map, the state of the system now: incoming traffic, error rate, slowest service, healthy / warning /
  problem services, and the services and connections that need attention, most severe first.

## 1.6.0

- APM Overview, **How it works** is now an interactive guide to connect an application: fill in the application
  (`service.namespace`), the service and the collector, pick the language or environment (Go, Python, Java, Node.js,
  .NET, PHP, Ruby, Docker Compose, Kubernetes, or a test without code with telemetrygen) and copy each step, already
  filled in. Below it, live checks of the selected application: traces, logs and metrics received in the last 15
  minutes, its services, services sent without `service.namespace`, and its Zabbix host with the template and macro.
- The data flow and configuration reference of that tab cover both paths (OpenTelemetry Collector or Zabbix proxy).

## 1.5.0

- New template **OpenTelemetry application by OTLP** (`templates/otel_application_otlp.json`): Zabbix's official
  "Generic OpenTelemetry by OTLP" limited to one application with `{$OTLP.NAMESPACE}`, generated from the exported
  official template by `scripts/build-otlp-app-template.py` (checked in CI). It also fixes the official template on
  8.0.0rc3: results read as rows, and duration items no longer group by `Duration` (they reported the shortest span).
- `scripts/zabbix-apm-app.py add <namespace>`: idempotent onboarding of an application through the Zabbix API (template
  import, host group, host `apm-<namespace>` with its macro); `list` shows the monitored applications.
- `scripts/verify-app.py <namespace>`: end-to-end check of an application (host, template, macro, supported items,
  fresh values) and, with ClickHouse access, comparison of the item values with ClickHouse for that namespace.
- Step-by-step guides in English and Spanish: [installation](docs/installation.md) (with a check per step and a
  `telemetrygen` smoke test), [add-application](docs/add-application.md), [apm-template](docs/apm-template.md) and
  [verification](docs/verification.md) (tested environment and results). `service.namespace` is now a documented
  requirement.
- Instrumentation per stack in `examples/stacks/`: a runnable Go service (built in CI), Java/Python/Node.js/.NET and
  Kubernetes without code changes, and the OpenTelemetry Demo with its own collector exporting to Zabbix.

## 1.4.0

- APM Overview: one tab per application (`service.namespace`) above the filter, so applications are never mixed. The
  selected application is remembered and applies to every tab, the real-time refresh and the service suggestions;
  changing it clears the service filter. Spans without a namespace get their own "No application" tab, shown last.
- APM service health and APM service map widgets: new "Application" field (`service.namespace`); empty = all.

## 1.3.1

- Zabbix 8.0.0rc3 support: ClickHouse query parameters are passed with their type (`[type, value]`), as rc3 requires.
  On rc3, APM Overview failed with HTTP 500 and the service map and service health widgets did not load.
- APM Overview: changing the time period no longer fails with `Incorrect value "web.apm.overview.filter" for "idx"
  field`, and the active filter tab is remembered.

## 1.3.0

First public release.

- **APM Overview**
  - Tabs: Status, Service map, Services, Endpoints, Errors, User experience, Origin of slowness, How it works.
  - Only the visible tab is queried, on display and every 10 s / 30 s (or paused).
  - If one query of a tab fails, only its section says so.
  - Service filter by substring, wildcard (`demo-*`) or exact name (`=api`), with suggestions of the services seen in
    the last 24 h. On the map the filter highlights instead of hiding.
  - English and Spanish, following the language of the Zabbix user.
- **APM service map** widget: the service map of APM Overview on dashboards.
- **APM service health** widget: the service name opens the service in APM Overview (traces stay one click away); English and Spanish.
- **Template "APM services by ClickHouse HTTP v2" 2.1**:
  - databases detected by `db.system.name` or `db.system`;
  - error, p95 and Apdex triggers need `{$APM.MIN.REQUESTS}`;
  - `{$APM.SERVICE.MATCHES}` / `{$APM.SERVICE.NOT_MATCHES}` filter what is discovered.
- Install script, examples (collector, ClickHouse users, low-memory ClickHouse, Web Vitals), tests and CI.
