# Instalación

[English](installation.md)

Una vez por Zabbix: almacenamiento, collector, fuente de datos, módulos y template. Después, cada aplicación se conecta
con [add-application.es.md](add-application.es.md). Cada paso termina con una comprobación; no sigas hasta que pase.

```
aplicaciones ──OTLP (4317 gRPC / 4318 HTTP)──▶ OpenTelemetry Collector ──▶ ClickHouse (base zabbix, tablas otel_*)
                                                                                ▲
     Zabbix server: items Telemetry query (template) ───────────────────────────┤
     Frontend de Zabbix: menú APM + estos módulos ──────────────────────────────┘
```

Probado con: Zabbix 8.0.0rc3 (server, frontend, MariaDB), ClickHouse 26.9 y otelcol-contrib 0.162 sobre Ubuntu 22.04,
con PHP 8.3; ver [verification.es.md](verification.es.md). Sirve cualquier distribución de máquinas: ClickHouse y el
collector pueden ir en el propio Zabbix server (como en la prueba) o en otras máquinas.

## 1. ClickHouse

Instala ClickHouse ([guía oficial](https://clickhouse.com/docs/install)) y crea dos usuarios: uno de escritura para el
collector y otro de solo lectura para Zabbix. Pon las dos contraseñas en una copia del archivo, nunca en el
repositorio:

```sh
cp examples/clickhouse-users.sql /tmp/users.sql   # reemplaza <writer-password> y <reader-password>
clickhouse-client --multiquery < /tmp/users.sql && rm /tmp/users.sql
```

En una VM pequeña compartida con Zabbix (pocos GB de RAM), copia además
[examples/clickhouse-low-memory.xml](../examples/clickhouse-low-memory.xml) a `/etc/clickhouse-server/config.d/` y
reinicia ClickHouse ([por qué](troubleshooting.md#memory_limit_exceeded)).

**Comprobación:** `clickhouse-client --user zabbix_ro --password -q 'SHOW DATABASES'` muestra `zabbix`.

## 2. OpenTelemetry Collector

Instala **otelcol-contrib** (la distribución contrib incluye el exportador `clickhouse`) desde sus
[releases](https://github.com/open-telemetry/opentelemetry-collector-releases/releases) (`.deb`/`.rpm`), copia
[examples/otelcol-config.yaml](../examples/otelcol-config.yaml) a `/etc/otelcol-contrib/config.yaml` y ajusta:

- el usuario y la contraseña de escritura del paso 1, en el exportador `clickhouse`;
- la dirección en la que escuchan los receptores: la interfaz a la que llegan tus aplicaciones (red privada, VPN o
  Tailscale). `0.0.0.0` expone OTLP en todas las interfaces: solo detrás de un firewall, y con TLS si atraviesa una red
  no confiable.

```sh
sudo systemctl restart otelcol-contrib
```

En el primer arranque, el exportador crea las tablas (`create_schema: true`). La telemetría se guarda 72 h (`ttl`).

**Comprobación:**

```sh
systemctl is-active otelcol-contrib                         # active
ss -ltn | grep -E ':4317|:4318'                             # escuchando
clickhouse-client -q 'SHOW TABLES FROM zabbix'              # otel_traces, otel_logs, otel_metrics_*
```

Un Zabbix proxy compilado con APM (`StartAPMCollectors=1`, `TelemetryProvider=clickhouse;…`) puede ocupar el lugar del
collector; el resto de la guía es igual.

## 3. Fuente de datos APM de Zabbix

**Administration → Data source → APM**: la URL HTTP de ClickHouse tal como la ven el Zabbix server y el frontend (p. ej.
`http://127.0.0.1:8123`), la base `zabbix` y el usuario de solo lectura (`zabbix_ro`). Pulsa **Test** y guarda.

Las páginas APM, estos módulos y los items Telemetry query del template usan esta fuente de datos. Si los items del
template dicen que la fuente de datos no está configurada, define también `TelemetryProvider` en la configuración del
server ([documentación de Zabbix](https://www.zabbix.com/documentation/8.0/en/manual/concepts/server/server_params#telemetryprovider)).

**Comprobación:** **APM → Traces** abre sin errores (vacío hasta que una aplicación envíe datos).

## 4. Módulos del frontend

Pantalla a pantalla, con capturas: [install-module.es.md](install-module.es.md).

```sh
git clone https://github.com/SimonLexRS/zabbix-apm-dashboard.git
cd zabbix-apm-dashboard
sudo ./scripts/install.sh            # encuentra /usr/share/zabbix/ui; ZABBIX_UI=/ruta para otro directorio
```

El script comprueba la sintaxis PHP, respalda la versión anterior en `/var/backups/zabbix-apm-dashboard-<fecha>` y copia
`modules/*` a `<frontend>/modules`. Después, en **Administration → General → Modules → Scan directory**, habilita:

- **APM Overview**: menú **APM → Overview**;
- **APM service map** y **APM service health**: widgets para dashboards (el mapa necesita APM Overview).

Los usuarios necesitan, en su rol, el elemento de interfaz **APM → Traces** y los tres módulos en *Access to modules*. Para actualizar, ejecuta el
script de nuevo. Para desinstalar: `sudo ./scripts/install.sh --uninstall`.

**Comprobación:** **APM → Overview → Estado** muestra la fuente de datos como OK.

## 5. Template

Importa **OpenTelemetry application by OTLP** ([templates/otel_application_otlp.json](../templates/otel_application_otlp.json))
desde **Data collection → Templates → Import**, o deja que `scripts/zabbix-apm-app.py add` lo importe al crear el primer
host de aplicación. Cómo funciona y cómo ajustarlo: [apm-template.es.md](apm-template.es.md).

Opcional, para alertas por servicio y por endpoint: **APM services by ClickHouse HTTP v2**
([templates/apm_services_clickhouse_http.yaml](../templates/apm_services_clickhouse_http.yaml)), configurado con macros:

| Macro | Valor |
|---|---|
| `{$CH.URL}` | URL HTTP de ClickHouse accesible desde el Zabbix server o proxy, p. ej. `http://127.0.0.1:8123/` |
| `{$CH.USER}` / `{$CH.PASSWORD}` | el usuario de solo lectura (la contraseña como macro secreto) |
| `{$APM.SERVICE.MATCHES}` | servicios que monitorea este host, p. ej. `^checkout-` (por defecto: todos) |
| `{$APM.MIN.REQUESTS}` | peticiones en 5 min antes de evaluar los triggers de error/p95/Apdex (por defecto 20) |
| `{$APM.ERROR.WARN}` / `{$APM.ERROR.MAX}`, `{$APM.P95.WARN}` / `{$APM.P95.MAX}`, `{$APM.APDEX.MIN}` | umbrales; todos aceptan contexto de servicio, p. ej. `{$APM.P95.MAX:"checkout-api"}` |

## 6. Comprobación de punta a punta (prueba de humo)

Sin escribir código, con [telemetrygen](https://github.com/open-telemetry/opentelemetry-collector-contrib/tree/main/cmd/telemetrygen)
(Docker), un host de aplicación y el script de verificación:

```sh
export ZABBIX_URL=http://<zabbix>/zabbix ZABBIX_API_TOKEN=<token>   # User settings → API tokens (Super admin)
python3 scripts/zabbix-apm-app.py add smoke-test

docker run --rm ghcr.io/open-telemetry/opentelemetry-collector-contrib/telemetrygen:latest traces \
  --otlp-endpoint <collector>:4317 --otlp-insecure --duration 5m --rate 5 --child-spans 2 \
  --service smoke-api --otlp-attributes 'service.namespace="smoke-test"'

# a los 3-4 minutos, desde una máquina que llegue a Zabbix (y a ClickHouse, para la comparación):
CH_URL=http://<clickhouse>:8123 CH_USER=zabbix_ro CH_PASSWORD=<reader-password> \
  python3 scripts/verify-app.py smoke-test
```

Resultado esperado: una pestaña **smoke-test** en **APM → Overview**, y `verify-app.py` termina con `all checks passed`.
Después borra el host `apm-smoke-test` (sus datos caducan con el TTL).

Siguiente: conecta tus aplicaciones con [add-application.es.md](add-application.es.md).
