# Zero-code instrumentation: Java, Python, Node.js, .NET, Kubernetes

[Español](README.es.md)

Most stacks can send traces, metrics and logs without code changes, with OpenTelemetry's automatic instrumentation.
The configuration is the same for all of them; only how the agent is loaded changes.

## Common configuration

```sh
OTEL_SERVICE_NAME=orders-api
OTEL_RESOURCE_ATTRIBUTES=service.namespace=shop,deployment.environment.name=production
OTEL_EXPORTER_OTLP_ENDPOINT=http://<collector>:4317
OTEL_EXPORTER_OTLP_PROTOCOL=grpc          # or http/protobuf with http://<collector>:4318
OTEL_TRACES_EXPORTER=otlp
OTEL_METRICS_EXPORTER=otlp
OTEL_LOGS_EXPORTER=otlp
```

Every service of one application uses the same `service.namespace`; then `python3 scripts/zabbix-apm-app.py add shop`
([add-application.md](../../../docs/add-application.md)).

## Java (JVM 8+)

```sh
curl -L -o opentelemetry-javaagent.jar \
  https://github.com/open-telemetry/opentelemetry-java-instrumentation/releases/latest/download/opentelemetry-javaagent.jar
java -javaagent:./opentelemetry-javaagent.jar -jar app.jar
```

Spring, servlet containers, JDBC, Kafka, HTTP clients and logging frameworks are instrumented automatically.
[Docs](https://opentelemetry.io/docs/zero-code/java/agent/).

## Python

```sh
pip install opentelemetry-distro opentelemetry-exporter-otlp
opentelemetry-bootstrap -a install                 # instrumentations for the installed libraries
OTEL_PYTHON_LOGGING_AUTO_INSTRUMENTATION_ENABLED=true opentelemetry-instrument python app.py
```

Django, Flask, FastAPI, requests, psycopg, SQLAlchemy, Celery… [Docs](https://opentelemetry.io/docs/zero-code/python/).

## Node.js

```sh
npm install @opentelemetry/api @opentelemetry/auto-instrumentations-node
NODE_OPTIONS="--require @opentelemetry/auto-instrumentations-node/register" node app.js
```

Express, Fastify, NestJS, http, pg, mysql, redis… [Docs](https://opentelemetry.io/docs/zero-code/js/).

## .NET

```sh
curl -sSfL https://github.com/open-telemetry/opentelemetry-dotnet-instrumentation/releases/latest/download/otel-dotnet-auto-install.sh -O
sh ./otel-dotnet-auto-install.sh
. $HOME/.otel-dotnet-auto/instrument.sh && dotnet app.dll
```

ASP.NET Core, HttpClient, SqlClient, EF Core… [Docs](https://opentelemetry.io/docs/zero-code/dotnet/).

## Go

Go has no agent: initialize the SDK in `main` — runnable example in [../go](../go/README.md).

## Kubernetes

Either the same variables in the Deployment, or the [OpenTelemetry Operator](https://opentelemetry.io/docs/platforms/kubernetes/operator/automatic/),
which injects the agent:

```yaml
apiVersion: opentelemetry.io/v1alpha1
kind: Instrumentation
metadata:
  name: zabbix-apm
spec:
  exporter:
    endpoint: http://<collector>:4317
  propagators: [tracecontext, baggage]
  resource:
    resourceAttributes:
      service.namespace: shop
      deployment.environment.name: production
```

and the annotation on the pod template, e.g. `instrumentation.opentelemetry.io/inject-java: "true"` (also `-python`,
`-nodejs`, `-dotnet`). With an in-cluster collector, follow the pattern of [../opentelemetry-demo](../opentelemetry-demo/README.md).

## Coverage of these instructions

They are the standard OpenTelemetry procedures. The verified setup ([verification.md](../../../docs/verification.md))
covers them indirectly: the OpenTelemetry Demo includes Java, Python, Node.js and .NET services instrumented with these
SDKs and agents, and all of them reach Zabbix through the same path.
