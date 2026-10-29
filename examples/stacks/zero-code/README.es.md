# Instrumentación sin código: Java, Python, Node.js, .NET, Kubernetes

[English](README.md)

La mayoría de los stacks pueden enviar trazas, métricas y logs sin tocar el código, con la instrumentación automática
de OpenTelemetry. La configuración es la misma para todos; solo cambia cómo se carga el agente.

## Configuración común

```sh
OTEL_SERVICE_NAME=orders-api
OTEL_RESOURCE_ATTRIBUTES=service.namespace=shop,deployment.environment.name=production
OTEL_EXPORTER_OTLP_ENDPOINT=http://<collector>:4317
OTEL_EXPORTER_OTLP_PROTOCOL=grpc          # o http/protobuf con http://<collector>:4318
OTEL_TRACES_EXPORTER=otlp
OTEL_METRICS_EXPORTER=otlp
OTEL_LOGS_EXPORTER=otlp
```

Todos los servicios de una aplicación usan el mismo `service.namespace`. Después:
`python3 scripts/zabbix-apm-app.py add shop` ([add-application.es.md](../../../docs/add-application.es.md)).

## Java (JVM 8+)

```sh
curl -L -o opentelemetry-javaagent.jar \
  https://github.com/open-telemetry/opentelemetry-java-instrumentation/releases/latest/download/opentelemetry-javaagent.jar
java -javaagent:./opentelemetry-javaagent.jar -jar app.jar
```

Spring, contenedores de servlets, JDBC, Kafka, clientes HTTP y frameworks de logging se instrumentan solos.
[Documentación](https://opentelemetry.io/docs/zero-code/java/agent/).

## Python

```sh
pip install opentelemetry-distro opentelemetry-exporter-otlp
opentelemetry-bootstrap -a install                 # instrumentaciones para las librerías instaladas
OTEL_PYTHON_LOGGING_AUTO_INSTRUMENTATION_ENABLED=true opentelemetry-instrument python app.py
```

Django, Flask, FastAPI, requests, psycopg, SQLAlchemy, Celery… [Documentación](https://opentelemetry.io/docs/zero-code/python/).

## Node.js

```sh
npm install @opentelemetry/api @opentelemetry/auto-instrumentations-node
NODE_OPTIONS="--require @opentelemetry/auto-instrumentations-node/register" node app.js
```

Express, Fastify, NestJS, http, pg, mysql, redis… [Documentación](https://opentelemetry.io/docs/zero-code/js/).

## .NET

```sh
curl -sSfL https://github.com/open-telemetry/opentelemetry-dotnet-instrumentation/releases/latest/download/otel-dotnet-auto-install.sh -O
sh ./otel-dotnet-auto-install.sh
. $HOME/.otel-dotnet-auto/instrument.sh && dotnet app.dll
```

ASP.NET Core, HttpClient, SqlClient, EF Core… [Documentación](https://opentelemetry.io/docs/zero-code/dotnet/).

## Go

Go no tiene agente: el SDK se inicializa en `main`. Ejemplo ejecutable en [../go](../go/README.es.md).

## Kubernetes

Hay dos opciones: las mismas variables en el Deployment, o el
[OpenTelemetry Operator](https://opentelemetry.io/docs/platforms/kubernetes/operator/automatic/), que inyecta el agente:

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

Con el Operator, añade además la anotación en la plantilla del pod, p. ej. `instrumentation.opentelemetry.io/inject-java: "true"`
(también `-python`, `-nodejs`, `-dotnet`). Con un collector dentro del clúster, sigue el patrón de
[../opentelemetry-demo](../opentelemetry-demo/README.es.md).

## Alcance de estas instrucciones

Son los procedimientos estándar de OpenTelemetry. La instalación verificada ([verification.es.md](../../../docs/verification.es.md))
los cubre de forma indirecta: el OpenTelemetry Demo incluye servicios en Java, Python, Node.js y .NET instrumentados con
estos SDK y agentes, y todos llegan a Zabbix por el mismo camino.
