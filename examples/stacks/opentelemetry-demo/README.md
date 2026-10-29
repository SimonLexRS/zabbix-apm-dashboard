# OpenTelemetry Demo: an application with its own collector

[Español](README.es.md)

The [OpenTelemetry Demo](https://github.com/open-telemetry/opentelemetry-demo) (Astronomy Shop) is a store of ~20
services in Go, Java, .NET, Python, Node.js, Ruby, PHP, C++, Rust and Elixir, already instrumented, that send everything
to their own OpenTelemetry Collector. That is the usual shape of a real system (Docker Compose, Kubernetes): instead of
re-pointing every service, the application's collector also exports to Zabbix APM.

```
20 services ──▶ demo collector ──┬──▶ Jaeger, Prometheus, OpenSearch (the demo's own, unchanged)
                                 └──▶ otlp_grpc/zabbix ──▶ Zabbix APM collector ──▶ ClickHouse
```

| File | Purpose |
|---|---|
| [otelcol-config-zabbix.yml](otelcol-config-zabbix.yml) | Exporter `otlp_grpc/zabbix` and three pipelines (`traces/zabbix`, `metrics/zabbix`, `logs/zabbix`) that receive the application OTLP; processor `resource/zabbix` adds `service.namespace` to the services that do not set it |
| [compose.zabbix.yaml](compose.zabbix.yaml) | Loads that file into the demo collector and passes the variables |

Own pipelines instead of adding the exporter to the demo's: the collector replaces (does not merge) exporter lists
between configuration files, and infrastructure metrics (`docker_stats`, `host_metrics`) stay local.

## Steps

1. Clone the demo and copy the two files to its root:

   ```sh
   git clone https://github.com/open-telemetry/opentelemetry-demo.git && cd opentelemetry-demo
   cp <zabbix-apm-dashboard>/examples/stacks/opentelemetry-demo/{compose.zabbix.yaml,otelcol-config-zabbix.yml} .
   ```

2. Add to `.env.override`:

   ```sh
   ZABBIX_APM_ENDPOINT=<collector>:4317    # the Zabbix APM collector (installation.md step 2)
   ZABBIX_APM_NAMESPACE=opentelemetry-demo
   ZABBIX_APM_INSECURE=true                # plain-text gRPC: only inside a private network/VPN; false = TLS
   ```

3. Start the demo with the file as the last `-f` (published images, nothing is built):

   ```sh
   F="-f compose.yaml -f compose.full.yaml -f compose.observability.yaml -f compose.zabbix.yaml --env-file .env --env-file .env.override"
   docker compose $F pull && docker compose $F up -d --no-build
   ```

4. Zabbix host and check:

   ```sh
   python3 scripts/zabbix-apm-app.py add opentelemetry-demo --name "OpenTelemetry Demo"
   python3 scripts/verify-app.py opentelemetry-demo        # after 3-4 minutes
   ```

**Checks:** `docker logs otel-collector | grep "Everything is ready"`; an **opentelemetry-demo** tab in
**APM → Overview** with ~19 services (the load generator starts by itself); `verify-app.py` → `all checks passed`.

The store is on port 8080 (`ENVOY_PORT`); if it is taken on your machine, publish another host port in a further
override (`ports: !override ["18080:8080"]`).

![OpenTelemetry Demo service map](../../../docs/screenshots/otel-demo-service-map.png)

## Same pattern elsewhere

- **Any Docker Compose application**: add an `otel-collector` service with a configuration like this one (receiver
  `otlp`, processor `resource` with your namespace, exporter `otlp_grpc/zabbix`) and point the services to it.
- **Kubernetes**: the same configuration in the OpenTelemetry Collector Helm chart or Operator (`OpenTelemetryCollector`
  resource); the services keep sending to the in-cluster collector.
- Load: this demo sends ~7,000 spans/min with its default load (5 users). Size the Zabbix collector and ClickHouse for
  it, or sample in the application collector (`probabilistic_sampler` in `traces/zabbix`).
