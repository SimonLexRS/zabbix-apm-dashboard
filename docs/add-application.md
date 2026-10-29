# Add an application to Zabbix APM

[Español](add-application.es.md)

Any application instrumented with OpenTelemetry can be monitored in four steps. Nothing in Zabbix or in these modules
is specific to an application: everything is keyed by the **`service.namespace`** resource attribute.

```
application (OTel SDK) ──OTLP──▶ collector ──▶ ClickHouse zabbix.otel_* ──▶ APM menu, APM Overview (tab per app),
                                                                           host "apm-<app>" (template, triggers)
```

| Term | Meaning | Example |
|---|---|---|
| Application | `service.namespace`: groups the services of one system | `flowspock` |
| Service | `service.name`: one deployable process | `flowspock-api` |
| Environment (optional) | `deployment.environment.name` | `production` |

The same steps, filled in for your application and with live checks, are in Zabbix: **APM → Overview → How it works**.

Prerequisites (once per Zabbix): [installation.md](installation.md) (ClickHouse, collector, data source, modules,
template).

## 1. Instrument the application

Every service of the application sends the same `service.namespace`. With any OpenTelemetry SDK or auto-instrumentation,
only standard environment variables are needed:

```sh
OTEL_SERVICE_NAME=flowspock-api
OTEL_RESOURCE_ATTRIBUTES=service.namespace=flowspock,deployment.environment.name=production
OTEL_EXPORTER_OTLP_ENDPOINT=http://<collector>:4317
OTEL_EXPORTER_OTLP_PROTOCOL=grpc        # or http/protobuf with port 4318
```

Per stack, with the code or commands: [Go](../examples/stacks/go/README.md) (SDK, runnable example),
[Java, Python, Node.js, .NET, Kubernetes](../examples/stacks/zero-code/README.md) (no code changes),
[an application with its own collector](../examples/stacks/opentelemetry-demo/README.md) (OpenTelemetry Demo).

Rules for the namespace: lowercase, stable, without spaces (it becomes the tab and the host name). Spans without it
appear in the "No application" tab.

## 2. Reach the collector

The collector of [examples/otelcol-config.yaml](../examples/otelcol-config.yaml) receives OTLP on 4317 (gRPC) and 4318
(HTTP). Choose the path that fits where the application runs:

- **Direct**: the application sends to the collector address (same network, VPN or Tailscale). Open only the port you
  use, never to the internet without TLS.
- **Through the application's own collector** (Kubernetes, Docker Compose, several services): its collector forwards
  to the Zabbix one and can add the namespace for services that do not set it:

  ```yaml
  processors:
    resource/app:
      attributes:
        - key: service.namespace
          value: flowspock
          action: insert          # only where missing
  exporters:
    otlp_grpc/zabbix:             # named "otlp" in older collector versions
      endpoint: ${env:ZABBIX_APM_ENDPOINT}   # e.g. zabbix-apm.example.com:4317
      tls:
        insecure: true            # only inside a VPN/Tailscale; otherwise configure TLS
  service:
    pipelines:
      traces/zabbix:
        receivers: [otlp]
        processors: [resource/app, batch]
        exporters: [otlp_grpc/zabbix]
      # same for metrics/zabbix and logs/zabbix
  ```

  Complete, tested example: [examples/stacks/opentelemetry-demo](../examples/stacks/opentelemetry-demo/README.md).

Check: **APM → Overview** shows a tab with the namespace within a minute.

## 3. Create the application host in Zabbix

One host per application, with **OpenTelemetry application by OTLP**
([templates/otel_application_otlp.json](../templates/otel_application_otlp.json)): Zabbix's official
"Generic OpenTelemetry by OTLP" limited to one application with the macro `{$OTLP.NAMESPACE}`.

With the script (idempotent; imports or updates the template, creates the host group and the host):

```sh
export ZABBIX_URL=http://<zabbix>/zabbix
export ZABBIX_API_TOKEN=<token>     # User settings → API tokens, Super admin
python3 scripts/zabbix-apm-app.py add flowspock --name "Flowspock"
python3 scripts/zabbix-apm-app.py list
```

Or by hand: **Data collection → Templates → Import** the JSON file; create the host `apm-flowspock` (no interface
needed) in a group such as `Applications/APM`, link the template and set `{$OTLP.NAMESPACE}` = `flowspock`.

The host gets, every minute and only for that application: spans by status (Ok / Error / Unset) with their average
duration, error percentage, log entries by severity and incoming metric counts, plus triggers:

| Trigger | Macro (default) |
|---|---|
| High span error rate | `{$SPAN.ERROR.WARN}` (5 %) |
| Span / Unset span duration is too big | `{$SPAN.OK.DURATION.WARN}` (0.5 s), `{$SPAN.UNSET.DURATION.WARN}` (1 s) |
| Warning / Error / Fatal entries in logs | `{$LOG.SEVERITY.WARN}` (10), `{$LOG.SEVERITY.ERROR}` (5), `{$LOG.SEVERITY.FATAL}` (0) |

Override the macros on the host to tune each application. For per-service and per-endpoint alerts add
**APM services by ClickHouse HTTP v2** ([installation.md](installation.md#6-template-alerts-and-history)) with
`{$APM.SERVICE.MATCHES}` limited to the application's services.

Check it end to end (after 3-4 minutes; see [verification.md](verification.md)):

```sh
python3 scripts/verify-app.py flowspock          # add CH_URL/CH_USER/CH_PASSWORD to compare with ClickHouse
```

Template details and tuning: [apm-template.md](apm-template.md).

## 4. Look at it

- **APM → Overview**: choose the application tab (service map, services, endpoints, errors, user experience, origin of
  slowness).
- Dashboards: the **APM service health** and **APM service map** widgets have an **Application** field.
- **Monitoring → Hosts → apm-&lt;app&gt;**: latest data, graphs and problems of the template.

## Troubleshooting

| Symptom | Cause |
|---|---|
| No tab for the application | No `service.namespace` (it is in "No application"), or no data in the last 7 days |
| No data at all | Endpoint, port or protocol (gRPC 4317 vs HTTP 4318), or the network path to the collector |
| Host items "not supported": data source | **Administration → Data source → APM** not configured or ClickHouse not reachable from the server |
| Host values are 0 but the tab has data | `{$OTLP.NAMESPACE}` differs from the namespace sent (it is case-sensitive) |

## Updating the template

The template is generated from Zabbix's own "Generic OpenTelemetry by OTLP", exported from Zabbix
(`templates/upstream/`). After a Zabbix upgrade, export it again (**Data collection → Templates → Export**, JSON),
replace the upstream file and run:

```sh
python3 scripts/build-otlp-app-template.py
python3 scripts/zabbix-apm-app.py add <each application>   # re-imports the template
```

The generator also fixes two problems of the template shipped with 8.0.0rc3: results come as rows (`[{"count": …}]`)
while its items read `$.columns.<alias>`, and the duration items grouped by `Duration`, so they reported the shortest
span instead of the average.
