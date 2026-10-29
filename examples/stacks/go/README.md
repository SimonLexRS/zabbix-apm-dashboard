# Go: instrument a service for Zabbix APM

[Español](README.es.md)

A minimal, runnable service with the same instrumentation pattern as a production Go API: traces, metrics and logs over
OTLP/gRPC, configured only with the standard `OTEL_*` variables. Copy [telemetry/telemetry.go](telemetry/telemetry.go)
into your project and follow the three steps; [main.go](main.go) shows them in context.

| What | Package | Gives |
|---|---|---|
| Providers and OTLP exporters | `telemetry.Setup` (this example) | traces, metrics (30 s) and logs to the collector; no-op without an endpoint |
| HTTP server | `go.opentelemetry.io/contrib/instrumentation/net/http/otelhttp` | one server span per request, named after the route (`GET /orders/{id}`), status Error on 5xx |
| HTTP client | `otelhttp.NewTransport` | client spans and W3C trace context to the called service (service map arrows) |
| Logs | `telemetry.SlogHandler` (`otelslog` bridge) | every `slog` record as an OTLP log, with the trace and span ids |
| Runtime | `contrib/instrumentation/runtime` | goroutines, GC and memory metrics |
| PostgreSQL (pgx) | `github.com/exaring/otelpgx` | client spans per statement with `db.system.name` (database node on the map) |

## 1. Dependencies

```sh
go get go.opentelemetry.io/otel@v1.47.0 go.opentelemetry.io/otel/sdk@v1.47.0 \
  go.opentelemetry.io/otel/sdk/metric@v1.47.0 go.opentelemetry.io/otel/sdk/log@v1.47.0 \
  go.opentelemetry.io/otel/exporters/otlp/otlptrace/otlptracegrpc@v1.47.0 \
  go.opentelemetry.io/otel/exporters/otlp/otlpmetric/otlpmetricgrpc@v1.47.0 \
  go.opentelemetry.io/otel/exporters/otlp/otlplog/otlploggrpc@v0.23.0 \
  go.opentelemetry.io/contrib/instrumentation/net/http/otelhttp@v0.72.0 \
  go.opentelemetry.io/contrib/instrumentation/runtime@v0.72.0 \
  go.opentelemetry.io/contrib/bridges/otelslog@v0.21.0
```

(The versions of [go.mod](go.mod), tested with Go 1.27; newer releases of the same packages work the same way.)

## 2. Code

```go
shutdown, err := telemetry.Setup(ctx, "checkout-api")        // default service name; OTEL_SERVICE_NAME wins
if err != nil { log.Fatal(err) }
defer shutdown(context.Background())                         // flush on exit
slog.SetDefault(slog.New(telemetry.SlogHandler(slog.NewJSONHandler(os.Stdout, nil))))

handler := otelhttp.NewHandler(mux, "http")                  // wrap the whole router once
client := &http.Client{Transport: otelhttp.NewTransport(http.DefaultTransport)}

// PostgreSQL with pgx:
// cfg.ConnConfig.Tracer = otelpgx.NewTracer()
```

Use `slog.InfoContext(r.Context(), …)` / `ErrorContext` inside handlers so the log records carry the trace id.

## 3. Configuration (environment)

```sh
OTEL_EXPORTER_OTLP_ENDPOINT=http://<collector>:4317     # http:// = plain-text gRPC (VPN/private network)
OTEL_SERVICE_NAME=checkout-api
OTEL_RESOURCE_ATTRIBUTES=service.namespace=shop,deployment.environment.name=production
# optional: OTEL_TRACES_SAMPLER=parentbased_traceidratio OTEL_TRACES_SAMPLER_ARG=0.2
```

Then create the application host: `python3 scripts/zabbix-apm-app.py add shop` ([add-application.md](../../../docs/add-application.md)).

## Run the example

```sh
docker build -t apm-go-example .
docker run -d --name apm-go-example -p 8080:8080 \
  -e OTEL_EXPORTER_OTLP_ENDPOINT=http://<collector>:4317 \
  -e OTEL_SERVICE_NAME=go-example-api \
  -e OTEL_RESOURCE_ATTRIBUTES=service.namespace=go-example,deployment.environment.name=test \
  -e SELF_LOAD=1 apm-go-example
```

`SELF_LOAD=1` calls `/hello`, `/slow` (200–800 ms) and `/fail` (HTTP 500) four times per second through the
instrumented client. In Zabbix: a **go-example** tab in **APM → Overview** within a minute; after
`zabbix-apm-app.py add go-example`, the host **apm-go-example** with an error rate around 17 % (one call in six fails)
and `verify-app.py go-example` → `all checks passed` ([verification.md](../../../docs/verification.md)).

