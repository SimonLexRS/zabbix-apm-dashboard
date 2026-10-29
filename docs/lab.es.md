# Laboratorio: todo en tu máquina en minutos

[English](lab.md)

Un entorno desechable para probar el proyecto completo antes de instalarlo en tu Zabbix, o para trabajar en un fork.
Incluye Zabbix 8.0 (server, frontend y PostgreSQL), ClickHouse, un OpenTelemetry Collector, los tres módulos y un
servicio Go instrumentado. Todo corre en Docker: no se instala nada en el equipo y todo se borra con un comando.

```
go-example (SDK Go) ──OTLP──▶ otel-collector ──▶ clickhouse ◀── zabbix-web (+ módulos) ◀── tú, http://localhost:8088
                                                     ▲
                                                zabbix-server (items del template)
```

Requisitos:
- Docker con Compose v2;
- Python 3.10+ (solo la biblioteca estándar);
- unos 3 GB de RAM libres;
- los puertos 8088, 8089, 18123, 14317 y 14318 libres en 127.0.0.1 (se pueden cambiar en `.env`).

## Arrancar

```sh
git clone https://github.com/SimonLexRS/zabbix-apm-dashboard.git     # o tu fork
cd zabbix-apm-dashboard/examples/lab
cp .env.example .env
docker compose up -d --build
python3 ../../scripts/lab-bootstrap.py
```

El primer arranque descarga las imágenes y crea la base de datos de Zabbix (2-5 minutos). `lab-bootstrap.py` espera a
que termine, habilita los módulos, configura la fuente de datos APM y crea el host del ejemplo Go.

Abre **http://localhost:8088** (usuario `Admin`, contraseña `zabbix`, los valores por defecto de un Zabbix nuevo) →
**APM → Overview** → pestaña **go-example**. Verás el mapa de servicios con el flujo en vivo, los servicios, los errores
y **Cómo funciona**, con las comprobaciones de la aplicación. En **Monitoring → Hosts → apm-go-example** están los items
y triggers del template.

A los 3-4 minutos, compruébalo de punta a punta (desde la raíz del repositorio):

```sh
ZABBIX_URL=http://127.0.0.1:8088 ZABBIX_USER=Admin ZABBIX_PASSWORD=zabbix \
CH_URL=http://127.0.0.1:18123 CH_USER=otel CH_PASSWORD=lab-clickhouse \
python3 scripts/verify-app.py go-example
```

Resultado esperado: `all checks passed`.

**Monitorización de usuarios reales**: abre **http://localhost:8089**, una pequeña página instrumentada con el SDK web
de OpenTelemetry (el snippet de la guía de Real user monitoring, en [examples/lab/web](../examples/lab/web)), y pulsa
sus botones. En menos de un minuto, **APM → Real user monitoring → go-example** muestra la sesión: la carga de la
página, cada clic y cada petición, hasta `go-example-api` (la petición que falla, falla ahí).

En Windows usa `python` en lugar de `python3`; en PowerShell, define antes las variables:

```powershell
$env:ZABBIX_URL="http://127.0.0.1:8088"; $env:ZABBIX_USER="Admin"; $env:ZABBIX_PASSWORD="zabbix"
$env:CH_URL="http://127.0.0.1:18123"; $env:CH_USER="otel"; $env:CH_PASSWORD="lab-clickhouse"
python scripts/verify-app.py go-example
```

## Conectar tu propia aplicación al laboratorio

El collector del laboratorio escucha en el equipo en `127.0.0.1:14317` (gRPC) y `127.0.0.1:14318` (HTTP):

```sh
export OTEL_EXPORTER_OTLP_ENDPOINT=http://127.0.0.1:14317
export OTEL_SERVICE_NAME=my-api
export OTEL_RESOURCE_ATTRIBUTES=service.namespace=my-app
# arranca tu aplicación (ejemplos por lenguaje: examples/stacks, o APM → Overview → Cómo funciona)

ZABBIX_URL=http://127.0.0.1:8088 ZABBIX_USER=Admin ZABBIX_PASSWORD=zabbix \
python3 scripts/zabbix-apm-app.py add my-app
```

Si añades un contenedor propio a `compose.yaml`, llega al collector como `otel-collector:4317`; si corre fuera, como
`host.docker.internal:14317`. Por ejemplo:

```sh
docker run -d --name my-api --add-host host.docker.internal:host-gateway \
  -e OTEL_EXPORTER_OTLP_ENDPOINT=http://host.docker.internal:14317 \
  -e OTEL_SERVICE_NAME=my-api -e OTEL_RESOURCE_ATTRIBUTES=service.namespace=my-app -e SELF_LOAD=1 \
  zabbix-apm-lab-go-example
```

Al terminar, `docker rm -f my-api`.

## Parar y borrar

```sh
docker compose stop          # conserva los datos
docker compose down -v       # borra contenedores y datos
```

## Qué es solo del laboratorio

- `.env` (ignorado por git) guarda contraseñas de ejemplo para contenedores que solo escuchan en 127.0.0.1.
- El laboratorio inicia sesión con el usuario `Admin` por defecto.

Para una instalación real sigue [installation.es.md](installation.es.md): tus propias contraseñas, un usuario de
ClickHouse de solo lectura para Zabbix, un API token en lugar de una contraseña y un collector al que solo lleguen tus
aplicaciones.

El laboratorio usa las imágenes Docker de 8.0 (etiqueta `alpine-trunk`, la línea 8.0 mientras 8.0 está en release
candidates). Para fijar otra build, define `ZABBIX_SERVER_IMAGE` y `ZABBIX_WEB_IMAGE` en `.env`.
