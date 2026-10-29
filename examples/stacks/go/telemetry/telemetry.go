// Package telemetry sends traces, metrics and logs over OTLP/gRPC when OTEL_EXPORTER_OTLP_ENDPOINT is set; without
// it everything stays a no-op, so the application runs the same with or without APM.
//
// Configuration is only the standard OpenTelemetry environment:
//
//	OTEL_EXPORTER_OTLP_ENDPOINT=http://collector:4317   (http:// = plain-text gRPC)
//	OTEL_SERVICE_NAME=checkout-api                      (overrides the name passed to Setup)
//	OTEL_RESOURCE_ATTRIBUTES=service.namespace=shop,deployment.environment.name=production
package telemetry

import (
	"context"
	"errors"
	"log/slog"
	"os"
	"runtime/debug"
	"time"

	"go.opentelemetry.io/contrib/bridges/otelslog"
	"go.opentelemetry.io/contrib/instrumentation/runtime"
	"go.opentelemetry.io/otel"
	"go.opentelemetry.io/otel/exporters/otlp/otlplog/otlploggrpc"
	"go.opentelemetry.io/otel/exporters/otlp/otlpmetric/otlpmetricgrpc"
	"go.opentelemetry.io/otel/exporters/otlp/otlptrace/otlptracegrpc"
	"go.opentelemetry.io/otel/log/global"
	"go.opentelemetry.io/otel/propagation"
	sdklog "go.opentelemetry.io/otel/sdk/log"
	sdkmetric "go.opentelemetry.io/otel/sdk/metric"
	"go.opentelemetry.io/otel/sdk/resource"
	sdktrace "go.opentelemetry.io/otel/sdk/trace"
	semconv "go.opentelemetry.io/otel/semconv/v1.43.0"
)

var on bool

// Setup installs the global tracer, meter and logger providers. shutdown flushes whatever is pending; call it before
// the process exits.
func Setup(ctx context.Context, service string) (shutdown func(context.Context) error, err error) {
	if os.Getenv("OTEL_EXPORTER_OTLP_ENDPOINT") == "" {
		return func(context.Context) error { return nil }, nil
	}

	attrs := []resource.Option{resource.WithAttributes(semconv.ServiceName(service))}
	if info, ok := debug.ReadBuildInfo(); ok {
		for _, s := range info.Settings {
			if s.Key == "vcs.revision" {
				attrs = append(attrs, resource.WithAttributes(semconv.ServiceVersion(s.Value)))
			}
		}
	}
	// Later options win: OTEL_SERVICE_NAME and OTEL_RESOURCE_ATTRIBUTES override the defaults above.
	res, err := resource.New(ctx, append(attrs, resource.WithTelemetrySDK(), resource.WithHost(), resource.WithFromEnv())...)
	if err != nil {
		return nil, err
	}

	var downs []func(context.Context) error
	shutdown = func(ctx context.Context) error {
		var errs []error
		for _, f := range downs {
			errs = append(errs, f(ctx))
		}
		return errors.Join(errs...)
	}
	fail := func(err error) (func(context.Context) error, error) {
		shutdown(context.Background())
		return nil, err
	}

	traceExporter, err := otlptracegrpc.New(ctx)
	if err != nil {
		return fail(err)
	}
	tp := sdktrace.NewTracerProvider(sdktrace.WithBatcher(traceExporter), sdktrace.WithResource(res))
	downs = append(downs, tp.Shutdown)

	metricExporter, err := otlpmetricgrpc.New(ctx)
	if err != nil {
		return fail(err)
	}
	mp := sdkmetric.NewMeterProvider(sdkmetric.WithResource(res),
		sdkmetric.WithReader(sdkmetric.NewPeriodicReader(metricExporter, sdkmetric.WithInterval(30*time.Second))))
	downs = append(downs, mp.Shutdown)

	logExporter, err := otlploggrpc.New(ctx)
	if err != nil {
		return fail(err)
	}
	lp := sdklog.NewLoggerProvider(sdklog.WithResource(res), sdklog.WithProcessor(sdklog.NewBatchProcessor(logExporter)))
	downs = append(downs, lp.Shutdown)

	otel.SetTracerProvider(tp)
	otel.SetMeterProvider(mp)
	global.SetLoggerProvider(lp)
	// W3C trace context: calls between services end up in the same trace (service map arrows).
	otel.SetTextMapPropagator(propagation.NewCompositeTextMapPropagator(propagation.TraceContext{}, propagation.Baggage{}))
	if err := runtime.Start(runtime.WithMeterProvider(mp)); err != nil {
		return fail(err)
	}
	on = true
	return shutdown, nil
}

// SlogHandler also sends every slog record to the OTLP logs, with the trace and span ids of its context, when Setup
// installed the providers.
func SlogHandler(base slog.Handler) slog.Handler {
	if !on {
		return base
	}
	return slog.NewMultiHandler(base, otelslog.NewHandler("app"))
}
