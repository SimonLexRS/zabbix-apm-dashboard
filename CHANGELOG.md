# Changelog

## 1.5.0

- New template **OpenTelemetry application by OTLP** (`templates/otel_application_otlp.json`): Zabbix's official
  "Generic OpenTelemetry by OTLP" limited to one application with `{$OTLP.NAMESPACE}`, generated from the exported
  official template by `scripts/build-otlp-app-template.py` (checked in CI). It also fixes the official template on
  8.0.0rc3: results read as rows, and duration items no longer group by `Duration` (they reported the shortest span).
- `scripts/zabbix-apm-app.py add <namespace>`: idempotent onboarding of an application through the Zabbix API (template
  import, host group, host `apm-<namespace>` with its macro); `list` shows the monitored applications.
- Guide to connect any application end to end: [docs/add-application.md](docs/add-application.md) (and Spanish).
  `service.namespace` is now a documented requirement.

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
