# Añadir una aplicación a Zabbix APM

[English](add-application.md)

Cualquier aplicación instrumentada con OpenTelemetry se monitorea en cuatro pasos. Nada en Zabbix ni en estos módulos
es específico de una aplicación: todo se organiza por el atributo de recurso **`service.namespace`**.

```
aplicación (SDK OTel) ──OTLP──▶ collector ──▶ ClickHouse zabbix.otel_* ──▶ menú APM, APM Overview (pestaña por app),
                                                                         host "apm-<app>" (template, triggers)
```

| Término | Qué es | Ejemplo |
|---|---|---|
| Aplicación | `service.namespace`: agrupa los servicios de un sistema | `flowspock` |
| Servicio | `service.name`: un proceso desplegable | `flowspock-api` |
| Entorno (opcional) | `deployment.environment.name` | `production` |

Los mismos pasos, rellenados para tu aplicación y con comprobaciones en vivo, están en Zabbix: **APM → Overview → Cómo funciona**.

Requisitos (una vez por Zabbix): [installation.es.md](installation.es.md) (ClickHouse, collector, fuente de datos,
módulos, template).

## 1. Instrumentar la aplicación

Todos los servicios de la aplicación envían el mismo `service.namespace`. Con cualquier SDK o auto-instrumentación de
OpenTelemetry bastan variables de entorno estándar:

```sh
OTEL_SERVICE_NAME=flowspock-api
OTEL_RESOURCE_ATTRIBUTES=service.namespace=flowspock,deployment.environment.name=production
OTEL_EXPORTER_OTLP_ENDPOINT=http://<collector>:4317
OTEL_EXPORTER_OTLP_PROTOCOL=grpc        # o http/protobuf con el puerto 4318
```

Por stack, con el código o los comandos: [Go](../examples/stacks/go/README.es.md) (SDK, ejemplo ejecutable),
[Java, Python, Node.js, .NET, Kubernetes](../examples/stacks/zero-code/README.es.md) (sin cambios de código),
[una aplicación con su propio collector](../examples/stacks/opentelemetry-demo/README.es.md) (OpenTelemetry Demo).

Reglas del namespace: en minúsculas, estable y sin espacios (será el nombre de la pestaña y del host). Los spans sin
namespace aparecen en la pestaña "Sin aplicación".

## 2. Llegar al collector

El collector de [examples/otelcol-config.yaml](../examples/otelcol-config.yaml) recibe OTLP en 4317 (gRPC) y 4318
(HTTP). Elige el camino según dónde corre la aplicación:

- **Directo**: la aplicación envía a la dirección del collector (misma red, VPN o Tailscale). Abre solo el puerto que
  uses, y nunca hacia internet sin TLS.
- **A través del collector propio de la aplicación** (Kubernetes, Docker Compose, varios servicios): ese collector
  reenvía al de Zabbix y puede añadir el namespace a los servicios que no lo ponen:

  ```yaml
  processors:
    resource/app:
      attributes:
        - key: service.namespace
          value: flowspock
          action: insert          # solo donde falta
  exporters:
    otlp_grpc/zabbix:             # se llama "otlp" en versiones antiguas del collector
      endpoint: ${env:ZABBIX_APM_ENDPOINT}   # p. ej. zabbix-apm.example.com:4317
      tls:
        insecure: true            # solo dentro de una VPN/Tailscale; si no, configura TLS
  service:
    pipelines:
      traces/zabbix:
        receivers: [otlp]
        processors: [resource/app, batch]
        exporters: [otlp_grpc/zabbix]
      # igual para metrics/zabbix y logs/zabbix
  ```

  Ejemplo completo y probado: [examples/stacks/opentelemetry-demo](../examples/stacks/opentelemetry-demo/README.es.md).

Comprobación: en menos de un minuto, **APM → Overview** muestra una pestaña con el namespace.

## 3. Crear el host de la aplicación en Zabbix

Un host por aplicación, con **OpenTelemetry application by OTLP**
([templates/otel_application_otlp.json](../templates/otel_application_otlp.json)): el template oficial de Zabbix
"Generic OpenTelemetry by OTLP" limitado a una aplicación con el macro `{$OTLP.NAMESPACE}`.

Con el script (idempotente: importa o actualiza el template, y crea el grupo y el host):

```sh
export ZABBIX_URL=http://<zabbix>/zabbix
export ZABBIX_API_TOKEN=<token>     # User settings → API tokens, Super admin
python3 scripts/zabbix-apm-app.py add flowspock --name "Flowspock"
python3 scripts/zabbix-apm-app.py list
```

O a mano: **Data collection → Templates → Import** del archivo JSON; crea el host `apm-flowspock` (no necesita
interfaz) en un grupo como `Applications/APM`, vincula el template y pon `{$OTLP.NAMESPACE}` = `flowspock`.

Cada minuto, y solo para esa aplicación, el host obtiene: spans por estado (Ok / Error / Unset) con su duración media,
porcentaje de error, entradas de log por severidad y número de métricas recibidas. Además incluye estos triggers:

| Trigger | Macro (por defecto) |
|---|---|
| Tasa de error de spans alta | `{$SPAN.ERROR.WARN}` (5 %) |
| Duración de spans Ok / Unset demasiado alta | `{$SPAN.OK.DURATION.WARN}` (0,5 s), `{$SPAN.UNSET.DURATION.WARN}` (1 s) |
| Entradas Warning / Error / Fatal en logs | `{$LOG.SEVERITY.WARN}` (10), `{$LOG.SEVERITY.ERROR}` (5), `{$LOG.SEVERITY.FATAL}` (0) |

Ajusta los macros en el host de cada aplicación. Para alertas por servicio y por endpoint añade
**APM services by ClickHouse HTTP v2** ([installation.md](installation.md#6-template-alerts-and-history)) con
`{$APM.SERVICE.MATCHES}` limitado a los servicios de la aplicación.

Compruébalo de punta a punta (a los 3-4 minutos; ver [verification.es.md](verification.es.md)):

```sh
python3 scripts/verify-app.py flowspock          # añade CH_URL/CH_USER/CH_PASSWORD para comparar con ClickHouse
```

Detalles del template y cómo ajustarlo: [apm-template.es.md](apm-template.es.md).

## 4. Verla

- **APM → Overview**: elige la pestaña de la aplicación (mapa de servicios, servicios, endpoints, errores, experiencia
  del usuario, origen de la lentitud).
- Dashboards: los widgets **APM service health** y **APM service map** tienen un campo **Aplicación**.
- **Monitoring → Hosts → apm-&lt;app&gt;**: últimos datos, gráficos y problemas del template.

## Problemas frecuentes

| Síntoma | Causa |
|---|---|
| No aparece la pestaña de la aplicación | Falta `service.namespace` (está en "Sin aplicación"), o no hay datos de los últimos 7 días |
| No llega ningún dato | Endpoint, puerto o protocolo (gRPC 4317 frente a HTTP 4318), o el camino de red hasta el collector |
| Items del host "not supported" por la fuente de datos | **Administration → Data source → APM** sin configurar, o ClickHouse no accesible desde el server |
| El host da 0 pero la pestaña tiene datos | `{$OTLP.NAMESPACE}` no coincide con el namespace enviado (distingue mayúsculas) |

## Actualizar el template

El template se genera a partir del propio "Generic OpenTelemetry by OTLP" de Zabbix, exportado desde Zabbix
(`templates/upstream/`). Tras actualizar Zabbix, vuelve a exportarlo (**Data collection → Templates → Export**, JSON),
sustituye el archivo de `upstream` y ejecuta:

```sh
python3 scripts/build-otlp-app-template.py
python3 scripts/zabbix-apm-app.py add <cada aplicación>   # reimporta el template
```

El generador también corrige dos fallos del template que trae la 8.0.0rc3: los resultados llegan como filas
(`[{"count": …}]`), pero sus items leen `$.columns.<alias>`; y los items de duración agrupaban por `Duration`, así que
publicaban el span más corto en lugar de la media.
