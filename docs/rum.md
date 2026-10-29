# Real user monitoring

[Español](rum.es.md)

**APM → Real user monitoring** shows what real users experience in the browser, and which backend call is behind it. It
reads the spans that the OpenTelemetry web SDK already sends (`telemetry.sdk.language = webjs`); nothing else is
installed. Like every page of the module, it has one tab per application (`service.namespace`), real-time refresh,
and it respects the roles and the APM filter of the user groups.

![Overview](screenshots/rum-overview.png)

| Tab | What it answers |
|---|---|
| **Overview** | Sessions, users, page loads, user actions, browser requests and their failures, first content visible, Core Web Vitals, activity per minute, slowest pages, requests that fail, browsers and operating systems |
| **Pages** | One row per page (path, ids folded into `:id`) with loads, sessions, load p75/p95 and first content visible. Open a page to see where its typical and its slowest load spend the time, phase by phase |
| **Resources** | Scripts, styles, images and fonts by host with their network phases; the requests of the page code (fetch, XMLHttpRequest) by method, path and status, with their latest trace |
| **Sessions** | The 100 most recent sessions. **Timeline** opens a panel with the page loads, actions and requests of the session; each request shows the backend services its trace went through, the one that failed, and links to the trace |
| **How it works** | How to instrument a web application, and live checks: browser spans, `session.id`, page loads, actions, requests linked to the backend |

![How a page loads](screenshots/rum-pages.png)

![Timeline of a session](screenshots/rum-session.png)

## What it reads

| Concept | OpenTelemetry data |
|---|---|
| Session, user | `session.id`, `enduser.id` on the browser spans |
| Page load | `documentLoad` span; its events (and those of its `documentFetch` child) give the load phases and the first contentful paint |
| User action | spans of the user interaction instrumentation (`event_type`, `target_element`, `target_xpath`) |
| Resource | `resourceFetch` spans |
| Request | browser spans of kind `Client`; failed = HTTP status ≥ 400 or span status Error |
| Backend part of a request | the spans of other services in the same trace |
| Browser, OS, device | `user_agent.original` |
| Core Web Vitals | log records with `rum.*` attributes ([below](#core-web-vitals)) |

## Instrument a web application

The **How it works** tab has the code. In short:

1. Start the OpenTelemetry web SDK with the document load, user interaction and fetch instrumentations, a span
   processor that sets `session.id` on every span, and `service.namespace` = the application.
2. Let the browser reach the collector's OTLP/HTTP receiver (port 4318), through HTTPS and with CORS for the site, or
   through a path of the site itself.
3. Add the API URLs to `propagateTraceHeaderCorsUrls` and allow the `traceparent` and `tracestate` headers in the CORS
   policy of the API: each request then continues in the backend services, in the same trace.

The [OpenTelemetry Demo](../examples/stacks/opentelemetry-demo/README.md) frontend is instrumented this way and fills
every tab.

## Limits

- Route changes of single page applications create no page loads with the standard instrumentation: they appear as
  actions and requests.
- Without `traceparent` propagation, requests show no backend trace.
- Session replay, browser profiling and geography are not part of OpenTelemetry data.
- `enduser.id` should be a pseudonymous id, never an e-mail or a name.

## Core Web Vitals

The **Overview** tab of this page and the **User experience** tab of APM Overview show the Core Web Vitals measured in
the browsers of real users: LCP, INP, CLS, FCP and TTFB, as the 75th percentile (how Google evaluates them), per page,
per device and per hour.

### Data format

One OTLP **log record** per metric and page load, with these attributes (any `service.name`, e.g. `my-app-browser`):

| Attribute | Value |
|---|---|
| `rum.metric` | `LCP`, `INP`, `CLS`, `FCP` or `TTFB` |
| `rum.value` | number: milliseconds (CLS: unitless score) |
| `rum.rating` | `good`, `needs-improvement` or `poor` (from the web-vitals library) |
| `rum.page` | route template of the page, e.g. `/orders/{id}`: no ids, tokens or query strings |
| `rum.device` | `mobile` or `desktop` (optional) |
| `rum.id` | random id of the page load (optional): CLS and INP are reported again when the tab is hidden; the module keeps the last value per id |

The service filter of the page applies to it like to any other service.

### Sending them

[examples/web-vitals.js](../examples/web-vitals.js) collects the metrics with the
[web-vitals](https://github.com/GoogleChrome/web-vitals) library and posts OTLP/HTTP JSON with `sendBeacon` when the
page is hidden. Two ways to reach the collector:

1. **Through your own site** (recommended): proxy a path such as `/otlp/v1/logs` to `http://<collector>:4318/v1/logs`.
   Same origin, no CORS, the collector stays private.
2. **Directly**: expose the collector's OTLP/HTTP receiver and allow your site's origin:

   ```yaml
   receivers:
     otlp:
       protocols:
         http:
           endpoint: 0.0.0.0:4318
           cors:
             allowed_origins: ["https://your.site"]
   ```

A third option is an endpoint in your backend that validates the beacon and emits the log records with its own
OpenTelemetry SDK; that is how [Flowspock](https://app.flowspock.com) does it, which also lets it reduce the path to a
route template on the server and rate-limit by IP.

### Privacy

Send no user ids, e-mails, IP addresses, query strings or page titles: only the route template, the device class and
the metric. The collector example also hashes client addresses of server spans (`attributes/privacy`).
