# Monitorización de usuarios reales

[English](rum.md)

**APM → Real user monitoring** muestra qué viven los usuarios reales en el navegador y qué llamada del backend hay
detrás. Lee los spans que el SDK web de OpenTelemetry ya envía (`telemetry.sdk.language = webjs`); no se instala nada
más. Como todas las páginas del módulo, tiene una pestaña por aplicación (`service.namespace`) y refresco en tiempo
real, y respeta los roles y el APM filter de los grupos de usuarios.

![Resumen](screenshots/rum-overview.png)

| Pestaña | Qué responde |
|---|---|
| **Resumen** | Sesiones, usuarios, cargas de página, acciones de usuario, peticiones del navegador y sus fallos, primer contenido visible, Core Web Vitals, actividad por minuto, páginas más lentas, peticiones que fallan, navegadores y sistemas operativos |
| **Páginas** | Una fila por página (ruta, con los ids agrupados en `:id`) con cargas, sesiones, carga p75/p95 y primer contenido visible. Abre una página para ver en qué se va el tiempo de su carga típica y de la más lenta, fase a fase |
| **Recursos** | Scripts, estilos, imágenes y fuentes por host con sus fases de red; las peticiones del código de la página (fetch, XMLHttpRequest) por método, ruta y estado, con su última traza |
| **Sesiones** | Las 100 sesiones más recientes. **Línea de tiempo** abre un panel con las cargas de página, acciones y peticiones de la sesión; cada petición muestra los servicios del backend que recorrió su traza, el que falló y el enlace a la traza |
| **Cómo funciona** | Cómo instrumentar una aplicación web, y comprobaciones en vivo: spans del navegador, `session.id`, cargas de página, acciones y peticiones enlazadas con el backend |

![Cómo carga una página](screenshots/rum-pages.png)

![Línea de tiempo de una sesión](screenshots/rum-session.png)

## Qué lee

| Concepto | Dato de OpenTelemetry |
|---|---|
| Sesión, usuario | `session.id`, `enduser.id` en los spans del navegador |
| Carga de página | span `documentLoad`; sus eventos (y los de su hijo `documentFetch`) dan las fases de la carga y el primer contenido visible |
| Acción de usuario | spans de la instrumentación de interacción de usuario (`event_type`, `target_element`, `target_xpath`) |
| Recurso | spans `resourceFetch` |
| Petición | spans del navegador de tipo `Client`; fallida = estado HTTP ≥ 400 o estado del span Error |
| Parte de backend de una petición | los spans de otros servicios en la misma traza |
| Navegador, SO, dispositivo | `user_agent.original` |
| Core Web Vitals | registros de log con atributos `rum.*` ([más abajo](#core-web-vitals)) |

## Instrumentar una aplicación web

La pestaña **Cómo funciona** tiene el código. En resumen:

1. Arranca el SDK web de OpenTelemetry con las instrumentaciones de carga de documento, interacción de usuario y
   fetch, un span processor que pone `session.id` en cada span, y `service.namespace` = la aplicación.
2. Deja que el navegador llegue al receptor OTLP/HTTP del collector (puerto 4318), con HTTPS y CORS para el sitio, o a
   través de una ruta del propio sitio.
3. Añade las URLs de la API a `propagateTraceHeaderCorsUrls` y permite las cabeceras `traceparent` y `tracestate` en
   la política CORS de la API: así cada petición continúa en los servicios del backend, en la misma traza.

El frontend del [OpenTelemetry Demo](../examples/stacks/opentelemetry-demo/README.es.md) está instrumentado así y
llena todas las pestañas.

## Límites

- Los cambios de ruta de las aplicaciones de una sola página no crean cargas de página con la instrumentación
  estándar: aparecen como acciones y peticiones.
- Sin propagación de `traceparent`, las peticiones no muestran traza de backend.
- La reproducción de sesiones, el profiling del navegador y la geografía no forman parte de los datos de
  OpenTelemetry.
- `enduser.id` debe ser un id seudónimo, nunca un correo ni un nombre.

## Core Web Vitals

La pestaña **Resumen** de esta página y la pestaña **User experience** de APM Overview muestran los Core Web Vitals
medidos en los navegadores de usuarios reales: LCP, INP, CLS, FCP y TTFB, como percentil 75 (como los evalúa Google),
por página, por dispositivo y por hora.

### Formato de los datos

Un **registro de log** OTLP por métrica y carga de página, con estos atributos (cualquier `service.name`, p. ej.
`mi-app-browser`):

| Atributo | Valor |
|---|---|
| `rum.metric` | `LCP`, `INP`, `CLS`, `FCP` o `TTFB` |
| `rum.value` | número: milisegundos (CLS: puntuación sin unidad) |
| `rum.rating` | `good`, `needs-improvement` o `poor` (de la librería web-vitals) |
| `rum.page` | plantilla de ruta de la página, p. ej. `/orders/{id}`: sin ids, tokens ni query strings |
| `rum.device` | `mobile` o `desktop` (opcional) |
| `rum.id` | id aleatorio de la carga de página (opcional): CLS e INP se envían otra vez al ocultar la pestaña; el módulo se queda con el último valor por id |

El filtro de servicio de la página se le aplica como a cualquier otro servicio.

### Enviarlos

[examples/web-vitals.js](../examples/web-vitals.js) recoge las métricas con la librería
[web-vitals](https://github.com/GoogleChrome/web-vitals) y las envía como OTLP/HTTP JSON con `sendBeacon` cuando la
página se oculta. Dos formas de llegar al collector:

1. **A través de tu propio sitio** (recomendada): redirige una ruta como `/otlp/v1/logs` a
   `http://<collector>:4318/v1/logs`. Mismo origen, sin CORS, y el collector sigue siendo privado.
2. **Directamente**: publica el receptor OTLP/HTTP del collector y permite el origen de tu sitio:

   ```yaml
   receivers:
     otlp:
       protocols:
         http:
           endpoint: 0.0.0.0:4318
           cors:
             allowed_origins: ["https://tu.sitio"]
   ```

Una tercera opción es un endpoint de tu backend que valida el beacon y emite los registros de log con su propio SDK de
OpenTelemetry; así lo hace [Flowspock](https://app.flowspock.com), lo que además le permite reducir la ruta a una
plantilla en el servidor y limitar por IP.

### Privacidad

No envíes ids de usuario, correos, direcciones IP, query strings ni títulos de página: solo la plantilla de ruta, el
tipo de dispositivo y la métrica. El ejemplo del collector además ofusca las direcciones de cliente de los spans de
servidor (`attributes/privacy`).
