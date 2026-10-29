# Lab: everything on your machine in minutes

[Español](lab.es.md)

A disposable environment to try the whole project before installing it in your Zabbix, or to work on a fork:
Zabbix 8.0 (server, frontend, PostgreSQL), ClickHouse, an OpenTelemetry Collector, the three modules and an instrumented
Go service. Everything runs in Docker; nothing is installed on the host and everything is removed with one command.

```
go-example (Go SDK) ──OTLP──▶ otel-collector ──▶ clickhouse ◀── zabbix-web (+ modules) ◀── you, http://localhost:8088
                                                     ▲
                                                zabbix-server (template items)
```

Requirements: Docker with Compose v2, Python 3.10+ (only the standard library), about 3 GB of free RAM, and ports
8088, 18123, 14317 and 14318 free on 127.0.0.1 (they can be changed in `.env`).

## Start

```sh
git clone https://github.com/SimonLexRS/zabbix-apm-dashboard.git     # or your fork
cd zabbix-apm-dashboard/examples/lab
cp .env.example .env
docker compose up -d --build
python3 ../../scripts/lab-bootstrap.py
```

The first start downloads the images and creates the Zabbix database (2-5 minutes); `lab-bootstrap.py` waits for it,
then enables the modules, sets the APM data source and creates the host of the Go example.

Open **http://localhost:8088** (user `Admin`, password `zabbix`, the defaults of a new Zabbix) → **APM → Overview** →
tab **go-example**: service map with the live flow, services, errors, and **How it works** with the checks of the
application. **Monitoring → Hosts → apm-go-example** has the template items and triggers.

After 3-4 minutes, check it end to end (from the root of the repository):

```sh
ZABBIX_URL=http://127.0.0.1:8088 ZABBIX_USER=Admin ZABBIX_PASSWORD=zabbix \
CH_URL=http://127.0.0.1:18123 CH_USER=otel CH_PASSWORD=lab-clickhouse \
python3 scripts/verify-app.py go-example
```

Expected: `all checks passed`.

On Windows, use `python` instead of `python3`, and in PowerShell set the variables first:

```powershell
$env:ZABBIX_URL="http://127.0.0.1:8088"; $env:ZABBIX_USER="Admin"; $env:ZABBIX_PASSWORD="zabbix"
$env:CH_URL="http://127.0.0.1:18123"; $env:CH_USER="otel"; $env:CH_PASSWORD="lab-clickhouse"
python scripts/verify-app.py go-example
```

## Connect your own application to the lab

The collector of the lab listens on the host at `127.0.0.1:14317` (gRPC) and `127.0.0.1:14318` (HTTP):

```sh
export OTEL_EXPORTER_OTLP_ENDPOINT=http://127.0.0.1:14317
export OTEL_SERVICE_NAME=my-api
export OTEL_RESOURCE_ATTRIBUTES=service.namespace=my-app
# start your application (examples per language: examples/stacks, or APM → Overview → How it works)

ZABBIX_URL=http://127.0.0.1:8088 ZABBIX_USER=Admin ZABBIX_PASSWORD=zabbix \
python3 scripts/zabbix-apm-app.py add my-app
```

A container of your own reaches it as `otel-collector:4317` if you add it to `compose.yaml`, or as
`host.docker.internal:14317` if it runs outside it, for example:

```sh
docker run -d --name my-api --add-host host.docker.internal:host-gateway \
  -e OTEL_EXPORTER_OTLP_ENDPOINT=http://host.docker.internal:14317 \
  -e OTEL_SERVICE_NAME=my-api -e OTEL_RESOURCE_ATTRIBUTES=service.namespace=my-app -e SELF_LOAD=1 \
  zabbix-apm-lab-go-example
```

Then `docker rm -f my-api` when you are done.

## Stop and remove

```sh
docker compose stop          # keep the data
docker compose down -v       # remove containers and data
```

## What is lab-only

`.env` (git-ignored) holds example passwords for containers that only listen on 127.0.0.1, and the lab signs in with
the default `Admin` user. For a real installation follow [installation.md](installation.md): your own passwords, a
read-only ClickHouse user for Zabbix, an API token instead of a password, and a collector reachable only by your
applications.

The lab uses the 8.0 Docker images (`alpine-trunk` tag, the 8.0 line while 8.0 is in release candidates). To pin another
build, set `ZABBIX_SERVER_IMAGE` and `ZABBIX_WEB_IMAGE` in `.env`.
