# OpenTelemetry Demo: una aplicación con su propio collector

[English](README.md)

El [OpenTelemetry Demo](https://github.com/open-telemetry/opentelemetry-demo) (Astronomy Shop) es una tienda de unos 20
servicios en Go, Java, .NET, Python, Node.js, Ruby, PHP, C++, Rust y Elixir. Ya viene instrumentada y lo envía todo a su
propio OpenTelemetry Collector. Es la forma habitual de un sistema real (Docker Compose, Kubernetes): en vez de
reconfigurar cada servicio, el collector de la aplicación exporta también a Zabbix APM.

```
20 servicios ──▶ collector del demo ──┬──▶ Jaeger, Prometheus, OpenSearch (los del demo, sin cambios)
                                      └──▶ otlp_grpc/zabbix ──▶ collector de Zabbix APM ──▶ ClickHouse
```

| Archivo | Para qué |
|---|---|
| [otelcol-config-zabbix.yml](otelcol-config-zabbix.yml) | Exportador `otlp_grpc/zabbix` y tres pipelines (`traces/zabbix`, `metrics/zabbix`, `logs/zabbix`) que reciben el OTLP de la aplicación. El processor `resource/zabbix` añade `service.namespace` a los servicios que no lo ponen |
| [compose.zabbix.yaml](compose.zabbix.yaml) | Carga ese archivo en el collector del demo y le pasa las variables |

Se usan pipelines propios en lugar de añadir el exportador a los del demo por dos motivos: entre archivos de
configuración, el collector reemplaza (no combina) las listas de exportadores, y así las métricas de infraestructura
(`docker_stats`, `host_metrics`) se quedan en local.

## Pasos

1. Clona el demo y copia los dos archivos en su raíz:

   ```sh
   git clone https://github.com/open-telemetry/opentelemetry-demo.git && cd opentelemetry-demo
   cp <zabbix-apm-dashboard>/examples/stacks/opentelemetry-demo/{compose.zabbix.yaml,otelcol-config-zabbix.yml} .
   ```

2. Añade a `.env.override`:

   ```sh
   ZABBIX_APM_ENDPOINT=<collector>:4317    # el collector de Zabbix APM (paso 2 de installation.es.md)
   ZABBIX_APM_NAMESPACE=opentelemetry-demo
   ZABBIX_APM_INSECURE=true                # gRPC en texto plano: solo dentro de una red privada/VPN; false = TLS
   ```

3. Arranca el demo con el archivo como último `-f` (imágenes publicadas, no se compila nada):

   ```sh
   F="-f compose.yaml -f compose.full.yaml -f compose.observability.yaml -f compose.zabbix.yaml --env-file .env --env-file .env.override"
   docker compose $F pull && docker compose $F up -d --no-build
   ```

4. Crea el host en Zabbix y comprueba:

   ```sh
   python3 scripts/zabbix-apm-app.py add opentelemetry-demo --name "OpenTelemetry Demo"
   python3 scripts/verify-app.py opentelemetry-demo        # a los 3-4 minutos
   ```

**Comprobaciones:**
- `docker logs otel-collector | grep "Everything is ready"`;
- una pestaña **opentelemetry-demo** en **APM → Overview** con unos 19 servicios (el generador de carga arranca solo);
- `verify-app.py` termina con `all checks passed`.

La tienda escucha en el puerto 8080 (`ENVOY_PORT`). Si está ocupado en tu máquina, publica otro puerto del host en un
override adicional (`ports: !override ["18080:8080"]`).

![Tienda del OpenTelemetry Demo](../../../docs/screenshots/otel-demo-store.png)

## El mismo patrón en otros sitios

- **Cualquier aplicación con Docker Compose**: añade un servicio `otel-collector` con una configuración como esta
  (receptor `otlp`, processor `resource` con tu namespace, exportador `otlp_grpc/zabbix`) y apunta los servicios a él.
- **Kubernetes**: la misma configuración en el Helm chart del OpenTelemetry Collector o en el Operator (recurso
  `OpenTelemetryCollector`); los servicios siguen enviando al collector del clúster.
- Carga: con su carga por defecto (5 usuarios), este demo envía unos 7.000 spans/min. Dimensiona para eso el collector de
  Zabbix y ClickHouse, o muestrea en el collector de la aplicación (`probabilistic_sampler` en `traces/zabbix`).
