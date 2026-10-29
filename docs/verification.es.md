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
3. **Los valores coinciden con ClickHouse, por aplicación.** `verify-app.py` compara minuto a minuto, durante los
   últimos 10 minutos, los valores de los items con `count()` y `avg(Duration)` calculados directamente en ClickHouse
   para el namespace. También comprueba que **no** coinciden con el total de todas las aplicaciones. Salida de las
   cuatro aplicaciones:

```text
$ python3 scripts/verify-app.py opentelemetry-demo
OK   host apm-opentelemetry-demo exists
OK   host is enabled
OK   template "OpenTelemetry application by OTLP" is linked
OK   {$OTLP.NAMESPACE} = 'opentelemetry-demo'
OK   16 items, unsupported: none
OK   span items receive values (newest 36 s ago, heartbeat 3600 s)
OK   Ok    spans/min over 10 min: Zabbix 59.5, ClickHouse 59.0 for opentelemetry-demo (59.0 all applications)
OK   Ok    average duration: Zabbix 0.0000 s, ClickHouse 0.0000 s
OK   Error spans/min over 10 min: Zabbix 4.2, ClickHouse 4.2 for opentelemetry-demo (79.0 all applications)
OK   Error filtered by namespace (not the total of all applications)
OK   Error average duration: Zabbix 4.0572 s, ClickHouse 4.0572 s
OK   Unset spans/min over 10 min: Zabbix 6,703.3, ClickHouse 6,697.0 for opentelemetry-demo (7,229.7 all applications)
OK   Unset average duration: Zabbix 0.0468 s, ClickHouse 0.0503 s
---- all checks passed

$ python3 scripts/verify-app.py flowspock
OK   host apm-flowspock exists
OK   host is enabled
OK   template "OpenTelemetry application by OTLP" is linked
OK   {$OTLP.NAMESPACE} = 'flowspock'
OK   16 items, unsupported: none
OK   span items receive values (newest 112 s ago, heartbeat 3600 s)
OK   Ok    spans/min over 10 min: Zabbix 0.0, ClickHouse 0.0 for flowspock (59.0 all applications)
OK   Error spans/min over 10 min: Zabbix 0.0, ClickHouse 0.0 for flowspock (79.0 all applications)
OK   Unset spans/min over 10 min: Zabbix 63.5, ClickHouse 63.5 for flowspock (7,229.7 all applications)
OK   Unset filtered by namespace (not the total of all applications)
OK   Unset average duration: Zabbix 0.0036 s, ClickHouse 0.0036 s
---- all checks passed

$ python3 scripts/verify-app.py go-example
OK   host apm-go-example exists
OK   host is enabled
OK   template "OpenTelemetry application by OTLP" is linked
OK   {$OTLP.NAMESPACE} = 'go-example'
OK   16 items, unsupported: none
OK   span items receive values (newest 21 s ago, heartbeat 3600 s)
OK   Ok    spans/min over 10 min: Zabbix 0.0, ClickHouse 0.0 for go-example (59.0 all applications)
OK   Error spans/min over 10 min: Zabbix 75.6, ClickHouse 74.8 for go-example (79.0 all applications)
OK   Error average duration: Zabbix 0.0003 s, ClickHouse 0.0003 s
OK   Unset spans/min over 10 min: Zabbix 356.6, ClickHouse 357.0 for go-example (7,229.7 all applications)
OK   Unset filtered by namespace (not the total of all applications)
OK   Unset average duration: Zabbix 0.1024 s, ClickHouse 0.1033 s
---- all checks passed

$ python3 scripts/verify-app.py smoke-test
OK   host apm-smoke-test exists
OK   host is enabled
OK   template "OpenTelemetry application by OTLP" is linked
OK   {$OTLP.NAMESPACE} = 'smoke-test'
OK   16 items, unsupported: none
OK   span items receive values (newest 125 s ago, heartbeat 3600 s)
OK   Ok    spans/min over 4 min: Zabbix 0.0, ClickHouse 0.0 for smoke-test (58.0 all applications)
OK   Error spans/min over 4 min: Zabbix 0.0, ClickHouse 0.0 for smoke-test (69.5 all applications)
OK   Unset spans/min over 4 min: Zabbix 283.5, ClickHouse 251.2 for smoke-test (7,452.2 all applications)
OK   Unset filtered by namespace (not the total of all applications)
OK   Unset average duration: Zabbix 0.0002 s, ClickHouse 0.0002 s
---- all checks passed
```

4. **El alta es reproducible e idempotente.** Los cuatro hosts los creó `zabbix-apm-app.py add` (import del template,
   grupo, host y macro). Ejecutarlo otra vez actualiza en lugar de duplicar.
5. **El add-on del OpenTelemetry Demo funciona tal como se documenta.** Reinicié el demo con
   [compose.zabbix.yaml](../examples/stacks/opentelemetry-demo/compose.zabbix.yaml) y
   [otelcol-config-zabbix.yml](../examples/stacks/opentelemetry-demo/otelcol-config-zabbix.yml) copiados sin cambios:
   llegaron 14 servicios en los primeros 40 segundos, todos con el namespace (también `frontend-web`, que no lo pone).
6. **Los módulos del frontend.** Pasan el lint de PHP y `php tests/run.php` (patrones, mapa de servicios, selección de
   aplicación y cobertura de traducciones), y se instalaron con `scripts/install.sh` en el Zabbix probado. APM Overview
   muestra una pestaña por aplicación.

## Cómo leer las comprobaciones

- Un valor recogido en el minuto T mide el minuto T−1 (`{$OTLP.GRANULARITY}` 1m, `{$OTLP.TIMESHIFT}` 10s); el script
  alinea así las dos series.
- El template solo guarda los conteos cuando cambian (latido de 1 h); el script arrastra el último valor, como hace
  Zabbix.
- Tolerancia del 20 %: los primeros minutos de un host nuevo y los spans que llegan después de los 10 s de margen
  producen pequeñas diferencias (en smoke-test, arriba, solo había 4 minutos de datos).

## Repetirlo en tu entorno

```sh
export ZABBIX_URL=http://<zabbix>/zabbix ZABBIX_API_TOKEN=<token>
export CH_URL=http://<clickhouse>:8123 CH_USER=zabbix_ro CH_PASSWORD=<reader-password>   # opcional
python3 scripts/verify-app.py <namespace>
```

Sin `CH_URL` comprueba solo el lado de Zabbix (host, template, macro, items soportados y valores recientes). Devuelve
código 0 si todas las comprobaciones pasan, así que puede ejecutarse en una CI o desde cron.
