# Install the modules in Zabbix

[Español](install-module.es.md)

The repository has three Zabbix frontend modules. They are PHP folders that Zabbix loads from `<frontend>/modules`:

| Folder | Module | Type |
|---|---|---|
| `modules/ApmOverview` | **APM Overview** | Page **APM → Overview** (required by the map widget) |
| `modules/ApmServiceMap` | **APM service map** | Dashboard widget |
| `modules/ApmServiceHealth` | **APM service health** | Dashboard widget |

Requirements: Zabbix **8.0** (tested with 8.0.0rc3 packages and the 8.0 Docker images) with PHP 8.2 or newer. The
APM data source (ClickHouse) is configured in step 3; until then the modules install fine but show "not configured".

![Modules enabled](screenshots/install-modules-enabled.png)

## 1. Put the folders in the frontend

**Packages** (Ubuntu/Debian/RHEL: the frontend is `/usr/share/zabbix/ui` or `/usr/share/zabbix`):

```sh
git clone https://github.com/SimonLexRS/zabbix-apm-dashboard.git
cd zabbix-apm-dashboard
sudo ./scripts/install.sh            # finds the frontend; ZABBIX_UI=/path/to/frontend overrides
```

The script checks the PHP syntax, backs up a previous version to `/var/backups/zabbix-apm-dashboard-<date>` and copies the
three folders with root ownership and read-only permissions.

**Docker** (official `zabbix/zabbix-web-*` images, frontend in `/usr/share/zabbix`): mount the folders read-only, as
the lab does ([examples/lab/compose.yaml](../examples/lab/compose.yaml)):

```yaml
  zabbix-web:
    volumes:
      - ./zabbix-apm-dashboard/modules/ApmOverview:/usr/share/zabbix/modules/ApmOverview:ro
      - ./zabbix-apm-dashboard/modules/ApmServiceHealth:/usr/share/zabbix/modules/ApmServiceHealth:ro
      - ./zabbix-apm-dashboard/modules/ApmServiceMap:/usr/share/zabbix/modules/ApmServiceMap:ro
```

**Check:** `ls <frontend>/modules` lists `ApmOverview`, `ApmServiceHealth` and `ApmServiceMap`.

## 2. Register and enable them

**Administration → General → Modules → Scan directory**. The three modules appear as *Disabled*:

![Scan directory](screenshots/install-modules-scan.png)

Click **Disabled** in the *Status* column of each one (or select the three and **Enable**). They become *Enabled*.

By API instead (Super admin), for automation: `module.create` with `id` = `apmoverview` / `apmservicehealth` /
`apmservicemap`, `relative_path` = `modules/<Folder>`, `status` = 1 — what
[scripts/lab-bootstrap.py](../scripts/lab-bootstrap.py) does.

## 3. Point Zabbix to ClickHouse

**Administration → Data source → APM**: check *Enable global data source*, then:

| Field | Value |
|---|---|
| URL | ClickHouse HTTP URL **as seen from the Zabbix server and frontend**, e.g. `http://127.0.0.1:8123` or `http://clickhouse:8123` in Docker |
| Authentication | Username and password: the read-only user ([installation.md](installation.md#1-clickhouse)) |
| Database | `zabbix` |

**Test**, then **Update**.

![APM data source](screenshots/install-data-source.png)

The native APM pages, these modules and the Telemetry query items of the template use this data source. By API:
`settings.update` with `apm_global_db` (`status` 1, `url`, `db`, `username`, `password`).

## 4. Open it

**APM → Overview**. The *Status* tab shows the data source and whether traces, logs and metrics are arriving; there is
one tab per application (`service.namespace`) once data comes in.

![APM Overview](screenshots/install-apm-overview.png)

Who can see it: users whose **user role** (**Users → User roles → <role>**) allows both:

- the UI element **APM → Traces** (*Access to UI elements*);
- **APM Overview**, **APM service health** and **APM service map** (*Access to modules*). New modules are allowed by
  default; check it if the role was customised.

Super admins can always.

![User role](screenshots/install-user-role.png)

## 5. Widgets on a dashboard

Edit a dashboard → **Add widget** → type **APM service map** or **APM service health**. Both have an *Application* field
(`service.namespace`; empty = all), a service filter, thresholds and the time period.

![Add the widget](screenshots/install-widget.png)

## Update and remove

- **Update**: `git pull` and run `sudo ./scripts/install.sh` again (Docker: `git pull`; the mounted folders change
  in place). Reload the page. No new scan is needed unless the release notes say so.
- **Remove**: disable the three modules in **Administration → General → Modules**, then
  `sudo ./scripts/install.sh --uninstall` (Docker: remove the volumes from the compose file).

## Troubleshooting

| Symptom | Fix |
|---|---|
| *Scan directory* does not list them | Wrong folder: they must be directly in `<frontend>/modules/<Folder>` with `manifest.json` inside |
| "The APM data source is not configured" | Step 3 |
| "Cannot query the APM data source" | URL or user from the point of view of the Zabbix **server/frontend** (in Docker, the service name, not `localhost`) |
| No *APM → Overview* menu, or "Access denied" | Role without **APM → Traces** or without the modules in *Access to modules* (step 4) |
| A widget says it needs APM Overview | Enable the **APM Overview** module too |

More: [troubleshooting.md](troubleshooting.md). Next: connect an application — [add-application.md](add-application.md).
