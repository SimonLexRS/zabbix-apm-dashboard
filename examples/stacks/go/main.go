// Minimal Go service instrumented for Zabbix APM: HTTP server spans, an instrumented HTTP client, logs, runtime
// metrics and two business metrics (orders and revenue by store), all over OTLP. See README.md.
package main

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"math/rand/v2"
	"net/http"
	"os"
	"os/signal"
	"slices"
	"strings"
	"syscall"
	"time"

	"go.opentelemetry.io/contrib/instrumentation/net/http/otelhttp"
	"go.opentelemetry.io/otel"
	"go.opentelemetry.io/otel/attribute"
	"go.opentelemetry.io/otel/metric"

	"example.com/apm-go-example/telemetry"
)

func main() {
	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()

	shutdown, err := telemetry.Setup(ctx, "go-example-api")
	if err != nil {
		slog.Error("telemetry", "error", err)
		os.Exit(1)
	}
	defer func() {
		flush, cancel := context.WithTimeout(context.Background(), 5*time.Second)
		defer cancel()
		_ = shutdown(flush)
	}()
	slog.SetDefault(slog.New(telemetry.SlogHandler(slog.NewJSONHandler(os.Stdout, nil))))

	mux := http.NewServeMux()
	mux.HandleFunc("GET /hello", func(w http.ResponseWriter, r *http.Request) {
		_, _ = w.Write([]byte("hello\n"))
	})
	mux.HandleFunc("GET /slow", func(w http.ResponseWriter, r *http.Request) {
		time.Sleep(time.Duration(200+rand.IntN(600)) * time.Millisecond)
		_, _ = w.Write([]byte("slow\n"))
	})
	mux.HandleFunc("GET /fail", func(w http.ResponseWriter, r *http.Request) {
		slog.ErrorContext(r.Context(), "payment declined", "reason", "example")
		http.Error(w, "payment declined", http.StatusInternalServerError) // 5xx: the server span gets status Error
	})

	// Business metrics: Zabbix discovers each one and each value of its attributes (see docs/business-kpis.md).
	// Counters, with a few known values per attribute: never an ID, an e-mail or free text.
	meter := otel.Meter("example.com/apm-go-example")
	orders, _ := meter.Int64Counter("shop.orders", metric.WithUnit("{order}"), metric.WithDescription("Orders placed"))
	revenue, _ := meter.Float64Counter("shop.revenue", metric.WithUnit("USD"),
		metric.WithDescription("Revenue of the orders placed"))
	mux.HandleFunc("POST /order", func(w http.ResponseWriter, r *http.Request) {
		store := r.URL.Query().Get("store")
		if !slices.Contains(stores, store) {
			http.Error(w, "unknown store", http.StatusBadRequest)
			return
		}
		amount := 10 + rand.Float64()*90
		attrs := metric.WithAttributes(attribute.String("shop.store", store))
		orders.Add(r.Context(), 1, attrs)
		revenue.Add(r.Context(), amount, attrs)
		_, _ = fmt.Fprintf(w, "order placed in %s: %.2f USD\n", store, amount)
	})

	addr := envOr("LISTEN_ADDR", ":8080")
	// otelhttp names each span after the matched route pattern ("GET /hello"), which the endpoint views use.
	srv := &http.Server{Addr: addr, Handler: otelhttp.NewHandler(mux, "http")}

	if os.Getenv("SELF_LOAD") != "" {
		host := addr
		if strings.HasPrefix(host, ":") {
			host = "localhost" + host // ":8080" listens on every address
		}
		go selfLoad(ctx, "http://"+host)
	}

	go func() {
		<-ctx.Done()
		_ = srv.Shutdown(context.Background())
	}()

	slog.Info("listening", "addr", addr)
	if err := srv.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
		slog.Error("server", "error", err)
	}
}

// stores accepted by POST /order.
var stores = []string{"Centro", "Norte", "Sur"}

// selfLoad calls the service a few times per second through an instrumented client: client spans, and the trace
// context propagated to the server spans. One call in eight places an order.
func selfLoad(ctx context.Context, base string) {
	client := &http.Client{Transport: otelhttp.NewTransport(http.DefaultTransport), Timeout: 5 * time.Second}
	paths := []string{"/hello", "/hello", "/hello", "/hello", "/slow", "/fail", "/hello", "/order"}
	tick := time.NewTicker(250 * time.Millisecond)
	defer tick.Stop()

	for {
		select {
		case <-ctx.Done():
			return
		case <-tick.C:
			method, path := http.MethodGet, paths[rand.IntN(len(paths))]
			if path == "/order" {
				// Centro gets half of the orders, so the stores differ in Zabbix.
				method, path = http.MethodPost, path+"?store="+[]string{"Centro", "Centro", "Norte", "Sur"}[rand.IntN(4)]
			}
			req, _ := http.NewRequestWithContext(ctx, method, base+path, nil)
			if resp, err := client.Do(req); err == nil {
				_ = resp.Body.Close()
			}
		}
	}
}

func envOr(key, fallback string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return fallback
}
