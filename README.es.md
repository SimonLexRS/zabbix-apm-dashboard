# Zabbix APM Dashboard

[English](README.md)

Monitoreo del rendimiento de aplicaciones (APM) en **Zabbix 8.0** para cualquier aplicación instrumentada con
**OpenTelemetry**. Incluye:
- módulos de frontend: mapa de servicios, métricas RED por servicio y endpoint, errores agrupados, experiencia del
  usuario, origen de la lentitud y una pestaña por aplicación;
- un template derivado del template oficial de OpenTelemetry de Zabbix, con un host por aplicación y sus triggers;
- scripts de alta y de verificación;
- guías paso a paso para aplicaciones en Go, Java, Python, Node.js, .NET, Kubernetes y Docker Compose.

![APM Overview: mapa de servicios](docs/screenshots/service-map-es.png)

| Componente | Qué aporta |
|---|---|
| `modules/ApmOverview` | Página **APM → Overview**: una pestaña por aplicación (`service.namespace`), con Estado, Mapa de servicios, Servicios, Endpoints, Errores, Experiencia del usuario y Origen de la lentitud. Actualización en tiempo real. |
| `modules/ApmServiceMap`, `modules/ApmServiceHealth` | Widgets de dashboard: mapa de servicios y tabla de salud de servicios, con campo *Aplicación*. |
| `templates/otel_application_otlp.json` | **OpenTelemetry application by OTLP**: el oficial *Generic OpenTelemetry by OTLP* limitado a una aplicación. Spans por estado, duraciones, % de error, logs por severidad, triggers y dashboard. [Detalles](docs/apm-template.es.md). |
| `templates/apm_services_clickhouse_http.yaml` | Opcional: items y triggers por servicio, endpoint y conexión (tasa de error, p95, Apdex). |
| `scripts/zabbix-apm-app.py` | Da de alta una aplicación por la API de Zabbix (template, host `apm-<namespace>` y macro). Idempotente. |
| `scripts/verify-app.py` | Comprueba una aplicación de punta a punta y compara los valores de Zabbix con ClickHouse. |
| `examples/stacks/` | Instrumentación por stack: [Go](examples/stacks/go/README.es.md) (ejecutable), [Java/Python/Node.js/.NET/Kubernetes](examples/stacks/zero-code/README.es.md), [OpenTelemetry Demo / collector propio](examples/stacks/opentelemetry-demo/README.es.md). |

Español e inglés: los módulos siguen el idioma del usuario de Zabbix.

## Cómo funciona

```
tus aplicaciones ──OTLP──▶ OpenTelemetry Collector ──▶ ClickHouse (zabbix.otel_*)
  service.name                                              │
  service.namespace = la aplicación ◀── todo se organiza por él
                                                            ├──▶ APM → Overview (pestaña por aplicación) + widgets
                                                            └──▶ host apm-<aplicación>: items del template, triggers, dashboard
```

Una aplicación se identifica por el atributo de recurso estándar **`service.namespace`**. Nada en Zabbix ni en estos
módulos es específico de una aplicación: para una nueva bastan las variables `OTEL_*` y un comando.

## Inicio rápido

1. **Instala una vez por Zabbix** ClickHouse, el collector, la fuente de datos, los módulos y el template:
   [docs/installation.es.md](docs/installation.es.md).
2. **Instrumenta la aplicación** con las variables estándar (ejemplos de código por stack en [examples/stacks](examples/stacks)):

   ```sh
   OTEL_SERVICE_NAME=checkout-api
   OTEL_RESOURCE_ATTRIBUTES=service.namespace=shop
   OTEL_EXPORTER_OTLP_ENDPOINT=http://<collector>:4317
   ```

3. **Crea su host:** `python3 scripts/zabbix-apm-app.py add shop`
4. **Verifica:** `python3 scripts/verify-app.py shop` debe terminar con `all checks passed`.
5. **Mira el resultado** en **APM → Overview → shop** y en **Monitoring → Hosts → apm-shop**.

Por aplicación, en detalle: [docs/add-application.es.md](docs/add-application.es.md).

## Verificado

Probado en Zabbix 8.0.0rc3, ClickHouse 26.9 y otelcol-contrib 0.162 con cuatro aplicaciones: el OpenTelemetry Demo
(unos 20 servicios en 10 lenguajes), una API Go en producción, el ejemplo Go y una prueba de humo con `telemetrygen`.
Los valores del template coinciden con ClickHouse para cada aplicación. Método, resultados y cómo repetirlo:
[docs/verification.es.md](docs/verification.es.md).

## Capturas

| Servicios de una aplicación | La tienda del OpenTelemetry Demo (una de las aplicaciones verificadas) |
|---|---|
| ![Servicios](docs/screenshots/services.png) | ![Tienda](docs/screenshots/otel-demo-store.png) |

Más: [experiencia del usuario](docs/screenshots/user-experience.png), [origen de la lentitud](docs/screenshots/origin-of-slowness-dark.png).

## Documentación

| Guía | |
|---|---|
| [installation.es.md](docs/installation.es.md) | Una vez por Zabbix, paso a paso, con una comprobación por paso y una prueba de humo |
| [add-application.es.md](docs/add-application.es.md) | Cada aplicación: instrumentar, llegar al collector, host en Zabbix, verificar |
| [apm-template.es.md](docs/apm-template.es.md) | El template APM: items, triggers, macros, ajuste y actualizaciones |
| [verification.es.md](docs/verification.es.md) | Qué se probó y cómo repetirlo |
| [rum.md](docs/rum.md) | Web Vitals de navegadores reales (en inglés) |
| [troubleshooting.md](docs/troubleshooting.md) | Problemas frecuentes (en inglés) |

## Qué responde cada pestaña

- **Estado**: ¿llega la telemetría? Fuente de datos, conexión y registros por señal.
- **Mapa de servicios**: usuarios → servicios → bases de datos y servicios externos, con llamadas/min, tasa de error y
  p95 en cada flecha, colores de salud y clic para ir a cada servicio.
- **Servicios / Endpoints**: métricas RED (tasa, errores, duración) con tendencias por minuto.
- **Errores**: fallos agrupados por servicio, endpoint y mensaje, con veces, primera y última vez y la última traza.
- **Experiencia del usuario**: Core Web Vitals (ver [docs/rum.md](docs/rum.md)), Apdex por servicio y endpoint, e
  histogramas de latencia.
- **Origen de la lentitud**: tiempo por endpoint (código propio / base de datos / otros servicios), las peticiones más
  lentas con su cascada de spans, las consultas más costosas y los endpoints más lentos que en el periodo anterior.

El filtro de servicio acepta un texto contenido (`checkout`), un comodín (`demo-*`) o un nombre exacto (`=api`). Solo se
consulta la pestaña visible, cada 10 s / 30 s o a demanda.

## Desarrollo

```sh
php tests/run.php                                   # tests de los módulos (no necesitan Zabbix)
python3 scripts/build-otlp-app-template.py --check  # template generado al día
```

La CI ([.github/workflows/ci.yml](.github/workflows/ci.yml)):
- pasa el lint de PHP 8.2–8.4 y de JavaScript, y ejecuta los tests;
- valida los manifests y los templates, y comprueba que el template generado está al día;
- compila el ejemplo Go;
- instala y desinstala en un frontend simulado.

Las traducciones están en `modules/*/lang/es.php`, indexadas por el texto en inglés.

## Licencia

[AGPL-3.0](LICENSE), la licencia de Zabbix. © Simon Alex Rodriguez.
