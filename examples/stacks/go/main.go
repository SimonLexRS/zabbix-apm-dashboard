// Minimal Go service instrumented for Zabbix APM: HTTP server spans, an instrumented HTTP client, logs and runtime
// metrics, all over OTLP. See README.md.
package main

import (
	"context"
	"errors"
	"log/slog"
	"math/rand/v2"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"

	"go.opentelemetry.io/contrib/instrumentation/net/http/otelhttp"

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

	addr := envOr("LISTEN_ADDR", ":8080")
	// otelhttp names each span after the matched route pattern ("GET /hello"), which the endpoint views use.
	srv := &http.Server{Addr: addr, Handler: otelhttp.NewHandler(mux, "http")}

	if os.Getenv("SELF_LOAD") != "" {
		go selfLoad(ctx, "http://localhost"+addr)
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

// selfLoad calls the service a few times per second through an instrumented client: client spans, and the trace
// context propagated to the server spans.
func selfLoad(ctx context.Context, base string) {
	client := &http.Client{Transport: otelhttp.NewTransport(http.DefaultTransport), Timeout: 5 * time.Second}
	paths := []string{"/hello", "/hello", "/hello", "/hello", "/slow", "/fail"}
	tick := time.NewTicker(250 * time.Millisecond)
	defer tick.Stop()

	for {
		select {
		case <-ctx.Done():
			return
		case <-tick.C:
			req, _ := http.NewRequestWithContext(ctx, http.MethodGet, base+paths[rand.IntN(len(paths))], nil)
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
