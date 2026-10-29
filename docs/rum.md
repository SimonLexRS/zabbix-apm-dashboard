# Real-user monitoring (Web Vitals)

The **User experience** tab shows the Core Web Vitals measured in the browsers of real users: LCP, INP, CLS, FCP and
TTFB, as the 75th percentile (how Google evaluates them), per page, per device and per hour.

## Data format

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

## Sending them

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

## Privacy

Send no user ids, e-mails, IP addresses, query strings or page titles: only the route template, the device class and
the metric. The collector example also hashes client addresses of server spans (`attributes/privacy`).
