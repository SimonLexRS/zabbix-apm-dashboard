# Go: instrumentar un servicio para Zabbix APM

[English](README.md)

Un servicio mínimo y ejecutable con el mismo patrón de instrumentación que una API Go en producción: trazas, métricas y
logs por OTLP/gRPC, configurado solo con las variables estándar `OTEL_*`. Copia
[telemetry/telemetry.go](telemetry/telemetry.go) a tu proyecto y sigue los tres pasos; [main.go](main.go) los muestra en
contexto.

| Qué | Paquete | Aporta |
|---|---|---|
| Proveedores y exportadores OTLP | `telemetry.Setup` (este ejemplo) | trazas, métricas (cada 30 s) y logs hacia el collector; no hace nada sin endpoint |
| Servidor HTTP | `go.opentelemetry.io/contrib/instrumentation/net/http/otelhttp` | un span de servidor por petición, con el nombre de la ruta (`GET /orders/{id}`) y estado Error en los 5xx |
| Cliente HTTP | `otelhttp.NewTransport` | spans de cliente y contexto W3C hacia el servicio llamado (flechas del mapa) |
| Logs | `telemetry.SlogHandler` (puente `otelslog`) | cada registro de `slog` como log OTLP, con los ids de traza y span |
| Runtime | `contrib/instrumentation/runtime` | métricas de goroutines, GC y memoria |
| PostgreSQL (pgx) | `github.com/exaring/otelpgx` | spans de cliente por sentencia con `db.system.name` (nodo de base de datos en el mapa) |

## 1. Dependencias

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

Son las versiones de [go.mod](go.mod), probadas con Go 1.27. Las versiones más nuevas de los mismos paquetes funcionan
igual.

## 2. Código

```go
shutdown, err := telemetry.Setup(ctx, "checkout-api")        // nombre por defecto; OTEL_SERVICE_NAME manda
if err != nil { log.Fatal(err) }
defer shutdown(context.Background())                         // vacía lo pendiente al salir
slog.SetDefault(slog.New(telemetry.SlogHandler(slog.NewJSONHandler(os.Stdout, nil))))

handler := otelhttp.NewHandler(mux, "http")                  // envuelve el router entero, una vez
client := &http.Client{Transport: otelhttp.NewTransport(http.DefaultTransport)}

// PostgreSQL con pgx:
// cfg.ConnConfig.Tracer = otelpgx.NewTracer()
```

Usa `slog.InfoContext(r.Context(), …)` / `ErrorContext` dentro de los handlers para que los logs lleven el id de traza.

## 3. Configuración (entorno)

```sh
OTEL_EXPORTER_OTLP_ENDPOINT=http://<collector>:4317     # http:// = gRPC en texto plano (VPN/red privada)
OTEL_SERVICE_NAME=checkout-api
OTEL_RESOURCE_ATTRIBUTES=service.namespace=shop,deployment.environment.name=production
# opcional: OTEL_TRACES_SAMPLER=parentbased_traceidratio OTEL_TRACES_SAMPLER_ARG=0.2
```

Después crea el host de la aplicación con `python3 scripts/zabbix-apm-app.py add shop`
([add-application.es.md](../../../docs/add-application.es.md)).

## Ejecutar el ejemplo

```sh
docker build -t apm-go-example .
docker run -d --name apm-go-example -p 8080:8080 \
  -e OTEL_EXPORTER_OTLP_ENDPOINT=http://<collector>:4317 \
  -e OTEL_SERVICE_NAME=go-example-api \
  -e OTEL_RESOURCE_ATTRIBUTES=service.namespace=go-example,deployment.environment.name=test \
  -e SELF_LOAD=1 apm-go-example
```

`SELF_LOAD=1` llama cuatro veces por segundo, a través del cliente instrumentado, a `/hello`, a `/slow` (200–800 ms) y
a `/fail` (HTTP 500). Qué verás en Zabbix:
- en menos de un minuto, una pestaña **go-example** en **APM → Overview**;
- tras `zabbix-apm-app.py add go-example`, el host **apm-go-example**, con una tasa de error en torno al 17 % (una
  llamada de cada seis falla);
- y `verify-app.py go-example` terminando con `all checks passed` ([verification.es.md](../../../docs/verification.es.md)).

