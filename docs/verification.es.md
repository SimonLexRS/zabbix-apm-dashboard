# Verificación

[English](verification.md)

Qué se comprobó, cómo y con qué resultado, para que "funciona" sea un hecho reproducible y no una afirmación. Repítelo
en tu entorno con `scripts/verify-app.py` (última sección).

## Entorno probado (2026-10-29)

| Componente | Versión |
|---|---|
| Zabbix server y frontend (MariaDB) | 8.0.0rc3, Ubuntu 22.04, PHP 8.3 |
| ClickHouse | 26.9 |
| OpenTelemetry Collector (lado Zabbix) | otelcol-contrib 0.162, exportador `clickhouse` |
| OpenTelemetry Collector (OpenTelemetry Demo) | otelcol-contrib 0.160 |
| Ejemplo Go | Go 1.27, OpenTelemetry Go 1.47 |

Cuatro aplicaciones, cada una con una forma distinta de enviar telemetría:

| Aplicación (`service.namespace`) | Stack | Camino hasta el collector de Zabbix |
|---|---|---|
| `opentelemetry-demo` | [OpenTelemetry Demo](../examples/stacks/opentelemetry-demo/README.es.md): ~20 servicios en 10 lenguajes, Docker Desktop | collector de la aplicación → OTLP/gRPC por VPN (Tailscale) |
| `flowspock` | API Go y aplicación web en producción, Docker en un VPS remoto | SDK → OTLP/gRPC por VPN (Tailscale) |
| `go-example` | [examples/stacks/go](../examples/stacks/go/README.es.md), Docker | SDK → OTLP/gRPC |
| `smoke-test` | `telemetrygen` ([paso 6 de installation.es.md](installation.es.md#6-comprobación-de-punta-a-punta-prueba-de-humo)) | OTLP/gRPC, sin código |

## Qué se verificó

1. **El template mide una sola aplicación.** Los items Telemetry query llevan la condición
   `ResourceAttributes['service.namespace'] = {$OTLP.NAMESPACE}` y el macro de usuario del host se resuelve. Con la
   misma consulta y en el mismo minuto, dio 7.138 spans con el macro en `opentelemetry-demo` y 7.433 para todas las
   aplicaciones; en ClickHouse, unos 7.230 y 7.290 por minuto.
2. **El template oficial no funciona tal cual en 8.0.0rc3.** Probé "Generic OpenTelemetry by OTLP" sin cambios en un
   host de prueba:
   - 10 de sus 16 items no estaban soportados, con el error `cannot extract value from json by path "$.columns.count"`:
     el server devuelve filas (`[{"clock":…,"count":55}]`).
   - Sus items de duración incluían `Duration` como columna en bruto, lo que agrupa el resultado: publicaban el span
     más corto del minuto (0 s en lugar de 0,045 s en los spans Unset).

   El template generado corrige las dos cosas: los 16 items funcionan y sus valores son iguales a los de ClickHouse.
3. **Los valores coinciden con ClickHouse, por aplicación.** `verify-app.py` compara cada valor de los items de los
   últimos 10 minutos con `count()` y `avg(Duration)` calculados directamente en ClickHouse para el namespace, sobre
   exactamente los segundos que cubre ese valor. También comprueba que **no** coinciden con el total de todas las
   aplicaciones. Salida con las aplicaciones en marcha (`smoke-test` pasó las mismas comprobaciones con la versión
   anterior del script; sus datos ya caducaron):

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

4. **El alta es reproducible e idempotente.** Todos los hosts los creó `zabbix-apm-app.py add` (import del template,
   grupo, host y macro). Ejecutarlo otra vez actualiza en lugar de duplicar.
5. **El add-on del OpenTelemetry Demo funciona tal como se documenta.** Reinicié el demo con
   [compose.zabbix.yaml](../examples/stacks/opentelemetry-demo/compose.zabbix.yaml) y
   [otelcol-config-zabbix.yml](../examples/stacks/opentelemetry-demo/otelcol-config-zabbix.yml) copiados sin cambios:
   llegaron 14 servicios en los primeros 40 segundos, todos con el namespace (también `frontend-web`, que no lo pone).
6. **Los módulos del frontend.** Pasan el lint de PHP y `php tests/run.php` (patrones, mapa de servicios, selección de
   aplicación y cobertura de traducciones), y se instalaron con `scripts/install.sh` en el Zabbix probado. APM Overview
   muestra una pestaña por aplicación.

7. **Un segundo Zabbix, desde un clon limpio.** Cloné el repositorio en una carpeta vacía y seguí [lab.es.md](lab.es.md)
   al pie de la letra: Zabbix 8.0.0rc3 de las imágenes Docker oficiales (PostgreSQL, PHP 8.5), ClickHouse 26.9 y
   otelcol-contrib 0.162. Habilité los módulos y configuré la fuente de datos con las pantallas de
   [install-module.es.md](install-module.es.md) (sus capturas salen de esta prueba); después `lab-bootstrap.py` se
   ejecutó encima sin cambiar nada. `go-example` pasó `verify-app.py`, igual que una segunda aplicación conectada desde
   fuera del compose como explica "Conectar tu propia aplicación al laboratorio":

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

8. **Roles y grupos de usuarios.** En el mismo laboratorio, con `go-example` y `my-app` enviando datos, tres usuarios
   abrieron todas las pestañas de APM Overview (`apm.overview.data`, las 8) y los dos widgets con *Application* vacío:

   | Usuario | Ajuste | Pestañas de aplicación | Datos de `my-app` en algún sitio | Cifras y enlaces de logs |
   |---|---|---|---|---|
   | Admin | Super admin | go-example, my-app | sí | sí |
   | go-viewer | User role; grupo con APM filter `service.namespace = go-example` (All) | solo go-example, también si la URL pide `filter_app=my-app` | no (ni en la página ni en los widgets) | sí |
   | nolog-viewer | rol sin *APM → Logs*; sin APM filter | go-example, my-app | sí | no |

   Sin avisos de PHP ni errores de ClickHouse en ninguna pestaña. `php tests/run.php` comprueba la condición que se
   construye con los filtros (los valores van siempre como parámetros de la consulta).

## Cómo leer las comprobaciones

- Un valor recogido en el instante T cuenta los spans de [T − 70 s, T − 10 s) (`{$OTLP.GRANULARITY}` 1m,
  `{$OTLP.TIMESHIFT}` 10s; el script lee ambas macros del host). El segundo de la recogida depende del item, no del
  inicio del minuto, así que el script compara cada valor con exactamente esos segundos en ClickHouse.
- El template solo guarda los conteos cuando cambian (latido de 1 h); el script arrastra el último valor, como hace
  Zabbix.
- Tolerancia del 20 %: solo los spans que llegan a ClickHouse después de los 10 s de margen producen diferencias.

## Repetirlo en tu entorno

```sh
export ZABBIX_URL=http://<zabbix>/zabbix ZABBIX_API_TOKEN=<token>
export CH_URL=http://<clickhouse>:8123 CH_USER=zabbix_ro CH_PASSWORD=<reader-password>   # opcional
python3 scripts/verify-app.py <namespace>
```

Sin `CH_URL` comprueba solo el lado de Zabbix (host, template, macro, items soportados y valores recientes). Devuelve
código 0 si todas las comprobaciones pasan, así que puede ejecutarse en una CI o desde cron.
