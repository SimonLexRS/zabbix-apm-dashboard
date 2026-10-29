# Design: Real user monitoring page (stages 1 and 2)

Status: approved design, before the implementation plan.

## Goal

Answer "what do real users experience in the browser, and which backend call is behind it" from the OpenTelemetry
data already stored in ClickHouse, with a page that is quick to read and to explore. No new collector, agent or
storage: only what the OpenTelemetry web SDK and the existing pipeline produce.

This design covers the first two stages of a larger roadmap:

1. **Overview and page performance**, this design.
2. **Session explorer with front-to-back correlation**, this design.
3. Frustration signals (repeated clicks, clicks without effect, clicks followed by an error) and frontend errors.
4. User journeys, funnels between pages, most used actions.
5. Breakdowns by browser, OS, device and language, and comparison between releases.

Out of scope, because OpenTelemetry does not produce the data:
- video-like session replay;
- browser CPU profiling;
- geography. It could come later from a GeoIP processor in the collector.

Visual design and wording are original to this project and follow the existing modules and Zabbix's own style.

## Data observed (OpenTelemetry Demo, one hour)

| Span | Count | Attributes and events used |
|---|---|---|
| `documentLoad` | 904 | `session.id`, `enduser.id`, `url.full`, `user_agent.original`; navigation timing events |
| `documentFetch` | 904 | same identity attributes |
| `resourceFetch` | 31,267 | `url.full`; resource timing events |
| `click` | 1,299 | `target_element`, `target_xpath`, `event_type` |
| `GET` / `POST` (Client) | 19,674 | `http.request.method`, `http.response.status_code`, `server.address`, `url.full` |

- The resource attributes include `telemetry.sdk.language = webjs`, `browser.language`, `os.type` and `user_agent.original`.
- Every client request of the browser had a backend `Server` span with the same `TraceId` (3,299 of 3,299 in ten minutes).
- Web Vitals arrive as log records with `rum.metric` (LCP, INP, CLS, FCP, TTFB), as the existing User experience tab already uses.

## Where it lives

- A new page **APM → Real user monitoring** (action `apm.rum`) inside the APM Overview module. It sits next to *Overview* in the APM menu, under the same role check (*APM → Traces*).
- It reuses, unchanged:
  - the application tabs (`service.namespace`) and the time selector;
  - real-time refresh with Pause;
  - the APM filter of the user's groups and the role checks (`ApmAccess`);
  - the ClickHouse access through `ApmStats`.
- Tabs: **Overview**, **Pages**, **Resources**, **Sessions**, **How it works**.
- APM Overview's *User experience* tab gets a link to the new page; nothing is removed.

## Definitions

All queries are limited to browser telemetry, the application and the user's APM filter.

| Concept | Rule |
|---|---|
| Browser telemetry | Spans whose resource has `telemetry.sdk.language = 'webjs'` |
| Session, user | `SpanAttributes['session.id']`, `SpanAttributes['enduser.id']` |
| Page view | Span `documentLoad`; load time = its duration |
| Page | Path of `url.full`, normalised: no query string or fragment; numeric, UUID and long hex segments become `:id` |
| Load waterfall | Events of the `documentLoad` span, as offsets from the span start: `fetchStart`, `domainLookupStart/End`, `connectStart/End`, `secureConnectionStart`, `requestStart`, `responseStart/End`, `domInteractive`, `domContentLoadedEventEnd`, `domComplete`, `loadEventEnd`, `firstPaint`, `firstContentfulPaint`. Missing events leave their phase out |
| Action | Span with `SpanAttributes['event_type']` (user interaction instrumentation); label from `target_element` and `target_xpath` |
| Resource | Span `resourceFetch`; type from the URL extension (script, stylesheet, image, font, other); phases from the same events |
| API request | Browser span of kind `Client` with `http.request.method`. Failed: status ≥ 400 or `StatusCode = 'Error'` |
| Backend link | A `Server` span of a non-browser service with the same `TraceId` |
| Core Web Vitals | p75 of `rum.metric` log records; without them, FCP from the `firstContentfulPaint` event, marked as such |
| Browser, OS, device | Parsed in PHP from `user_agent.original` with a small rule list: Edge, Opera, Chrome, Firefox, Safari / Windows, macOS, iOS, Android, Linux / mobile or desktop |

## Tabs

**Overview**
- KPIs: sessions, users, page views, actions, API requests, failed request rate.
- Core Web Vitals at p75, each with its good / needs improvement / poor share. The thresholds are the published Web Vitals ones already used by the module.
- Sessions and page views per minute (bars), load time p75 per minute (line).
- Slowest pages (p75 load), requests with most failures, browsers and operating systems (bars).

**Pages**
- One row per page: views, sessions, load p75 and p95, FCP p75, failed requests, share of views.
- Clicking a page shows the load waterfall of its median view and of its slowest view, phase by phase.

**Resources**
- Grouped by type and by host: count, p75 duration, phase breakdown (DNS, connection, TLS, waiting for the first byte, download), slowest URLs.
- API requests grouped by method + normalised path + status class, with p75 and a link to a sample backend trace.

**Sessions**
- The 100 most recent sessions of the period: start, user, pages, actions, requests, failed requests, duration, browser / OS.
- Filters: with failed requests, page visited, user, browser.
- Opening a session loads its timeline in a side panel:
  - views, actions and requests in time order;
  - requests are nested under the action of the same trace;
  - each request shows status and duration, and a summary of its backend trace: services crossed and the first failing one;
  - each request links to the native **APM → Traces** view of that trace.

**How it works**
- How to instrument a browser application with the OpenTelemetry web SDK:
  - packages: document load, user interaction, fetch / XMLHttpRequest;
  - a `session.id` span processor;
  - `traceparent` propagation to the backend (`propagateTraceHeaderCorsUrls`);
  - CORS on the collector's OTLP/HTTP receiver.
- Live checks of the selected application: browser spans, `session.id` present, requests linked to a backend trace, Web Vitals.

## Limits shown in the interface

- Route changes in single page applications produce no `documentLoad` with the standard instrumentation. They appear as actions and requests, not as new views.
- Without `traceparent` propagation, requests appear without a backend link. The session marks them "no backend trace".
- Sessions, pages and timelines need `session.id`. Without it, the Sessions tab explains how to add it.

## Cost on ClickHouse

- Aggregations read only the browser spans of the period.
- Waterfalls, timelines and backend summaries use the existing join window and `max_threads=2`.
- The session list is limited to 100 rows. A timeline is read between the session's own first and last span.

## Code layout

| Unit | Responsibility |
|---|---|
| `includes/RumStats.php` | ClickHouse queries of the page. Reuses the scope, access and row helpers of `ApmStats`; the shared helpers become protected so `RumStats` can extend `ApmStats` |
| `includes/Rum.php` | Pure functions, testable without Zabbix: page normalisation, user agent parsing, waterfall phases from events, timeline assembly |
| `includes/RumHtml.php` | Rendering of each tab; SVG charts drawn by the module, like the existing ones |
| `actions/Rum.php`, `actions/RumData.php`, `views/apm.rum.php` | Page and real-time data actions, as `Overview` / `OverviewData` |
| `assets/js`, `assets/css` | Side panel and charts, extending the existing files |

## Tests and verification

- **`tests/run.php`** checks:
  - page normalisation;
  - user agents of each browser and OS;
  - waterfall phases with missing events (no TLS on HTTP);
  - timeline nesting by trace;
  - empty data.
- **Lab** (`examples/lab`): a small static page instrumented with the OpenTelemetry web SDK, driven by a headless browser that clicks. It calls the Go example, so a fork sees sessions and front-to-back traces without the OpenTelemetry Demo.
- **Real data:**
  - on the user's Zabbix with the OpenTelemetry Demo, sessions, views and requests are compared with direct ClickHouse counts;
  - every request with a backend trace has to show as linked;
  - the page is checked as Super admin and as a user with an APM filter.
- **Docs:** EN and ES, with screenshots of this module only. CHANGELOG 2.0.0.
