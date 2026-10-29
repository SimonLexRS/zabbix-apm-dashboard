# Changelog

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
- Docs: "Who sees what" in [docs/install-module.md](docs/install-module.md#who-sees-what), with the APM filter of a user
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
