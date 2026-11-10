# El template APM: OpenTelemetry application by OTLP

[English](apm-template.md)

[templates/otel_application_otlp.json](../templates/otel_application_otlp.json) es el template oficial de Zabbix
**Generic OpenTelemetry by OTLP** (Zabbix 8.0) limitado a **una aplicación**. Se vincula a un host por aplicación y se
pone `{$OTLP.NAMESPACE}` en el host; cada item lee solo la telemetría cuyo atributo de recurso `service.namespace` tiene
ese valor. `scripts/zabbix-apm-app.py add <namespace>` hace las dos cosas.

## Por qué un template derivado

| Template oficial | Este template |
|---|---|
| Sin filtro de aplicación: un host mide **toda** la telemetría de ClickHouse | Condición `ResourceAttributes['service.namespace'] = {$OTLP.NAMESPACE}` en cada item Telemetry query |
| En 8.0.0rc3, 10 de 16 items no soportados: `cannot extract value from json by path "$.columns.count"` | Resultados normalizados (ver abajo): los 16 funcionan |
| Los items de duración agrupaban por `Duration`: publicaban el span **más corto** del minuto | Media del minuto, igual que en ClickHouse |
| Un intervalo sin datos conserva el último valor o deja el item no soportado: los problemas nunca se cierran | Sin datos es un 0, así que los problemas se cierran solos; **No spans received** avisa de que la aplicación dejó de enviar |

Los dos conviven (nombres y UUID distintos). La comparación con datos reales está en [verification.es.md](verification.es.md).

## Qué mide

Cada minuto (`{$OTLP.FREQUENCY}`), sobre los 60 s (`{$OTLP.GRANULARITY}`) que terminan 10 s antes de la recogida
(`{$OTLP.TIMESHIFT}`), para que hayan llegado los lotes del collector:

| Item | Significado |
|---|---|
| Span: Ok / Error / Unset count | Spans por estado. Las instrumentaciones de OpenTelemetry dejan los spans correctos como **Unset** (Ok solo se marca explícitamente) y los fallos como **Error**, así que *Unset* es el tráfico normal. |
| Span: Ok / Error / Unset duration | Duración media de esos spans, en segundos. *Unset duration* es la latencia típica de la aplicación. |
| Span: Error percentage | Error / (Ok + Error + Unset) × 100. |
| Logs: Info / Warning / Error / Fatal entries count | Registros de log por severidad (de `Get logs`, una sola consulta). |
| Metrics: Count, Gauge count, Histogram count | Puntos de métricas recibidos: indica que llegan métricas. Sus valores (pedidos, reservas…) son los [KPIs de negocio](business-kpis.es.md), con su propio template. |
| System: Uptime | Métrica `system.uptime`, si la aplicación la envía. |

**Un intervalo sin datos es un 0**, no un valor ausente: los conteos de spans, las duraciones, el porcentaje de error
(0 % sin spans, en lugar de una división por cero) y los conteos de logs vuelven a 0 cuando la aplicación deja de enviar
o deja de fallar. Eso es lo que cierra los problemas. Una duración de 0 significa, por tanto, «sin spans de ese estado»
(lo normal en *Ok*). Los conteos de logs suman todos los `SeverityNumber` de su rango (p. ej. 17–20 para Error) del
intervalo más reciente.

Los conteos de logs y de métricas solo se guardan cuando cambian, con un latido de 1 h (el *discard unchanged* del
template oficial); los gráficos dibujan igualmente el valor constante. Los conteos de spans guardan todos los valores,
porque *No spans received* mira un periodo.

## Triggers

| Trigger | Expresión | Por defecto |
|---|---|---|
| High span error rate | porcentaje de error > `{$SPAN.ERROR.WARN}` | 5 (%) |
| Span duration is too big | `min(duración Ok, 5m)` > `{$SPAN.OK.DURATION.WARN}` | 0,5 s |
| Unset span duration is too big | `min(duración Unset, 5m)` > `{$SPAN.UNSET.DURATION.WARN}` | 1 s |
| Warning / Error / Fatal severity level entries in logs | número > `{$LOG.SEVERITY.WARN}` / `{$LOG.SEVERITY.ERROR}` / `{$LOG.SEVERITY.FATAL}` | 10 / 5 / 0 |
| No spans received (Average) | los tres conteos de spans fueron 0 durante `{$SPAN.NODATA}`, o los conteos no se recogen (`nodata`) | 10m |

Cada problema se cierra solo cuando termina su condición. *No spans received* se abre cuando la aplicación (o el
collector) deja de enviar y se cierra con el primer intervalo que vuelve a tener spans; mientras está abierto los demás
valores son 0, así que sus problemas están cerrados.

Cada trigger tiene un enlace, **APM overview**, en el menú del problema (clic en el nombre del problema en
**Monitoring → Problems**): abre el módulo en esa aplicación (`filter_app={$OTLP.NAMESPACE}`), en la pestaña
**Errores**, en **Lentitud** para los triggers de duración y en **Estado** para *No spans received*.

Ajústalos por aplicación con macros de host (p. ej. `{$SPAN.UNSET.DURATION.WARN}` = `0.3` en una API rápida). Los
macros de intervalo aceptan el contexto de la señal: `{$OTLP.FREQUENCY:traces}`, `:logs`, `:metrics`, `:gauge`,
`:histogram`.

El template trae además un dashboard de host (**Overview**: uptime, % de error, duración por estado, distribución de
estados, número de métricas, problemas y severidades de log): **Monitoring → Hosts → apm-&lt;app&gt; → Dashboards**.

## Generación y actualizaciones de Zabbix

El template se genera; nunca se edita a mano:

```
templates/upstream/generic_opentelemetry_by_otlp.json   (export del template oficial desde Zabbix)
        │  scripts/build-otlp-app-template.py
        ▼
templates/otel_application_otlp.json
```

El generador hace lo siguiente:
- añade la condición de namespace a cada item Telemetry query y el macro `{$OTLP.NAMESPACE}`;
- inserta un paso JavaScript que convierte los resultados en filas (`[{"count":55}]`, 8.0.0rc3) al formato
  `{"columns":{"count":55}}` que leen las rutas oficiales (si un resultado trae varios intervalos, toma el más
  reciente), y deja pasar sin cambios el otro formato;
- cuando no hay filas, pone 0 en los conteos y en las duraciones de spans (sin valor en el uptime);
- suma los registros de log de cada rango de severidad en lugar de leer solo su primera fila;
- reescribe el porcentaje de error para que no pueda dividir por cero;
- añade *No spans received*, `{$SPAN.NODATA}` y los enlaces de los triggers;
- quita las columnas en bruto que agrupan por la columna agregada;
- asigna UUID versión 4 deterministas.

La CI falla si el template commiteado no es el generado (`--check`).

Tras actualizar Zabbix, exporta de nuevo el template oficial (**Data collection → Templates →** selecciona
*Generic OpenTelemetry by OTLP* **→ Export → JSON**), sustituye el archivo de `upstream` y ejecuta:

```sh
python3 scripts/build-otlp-app-template.py
python3 scripts/zabbix-apm-app.py add <namespace>   # reimporta el template; basta con una aplicación
python3 scripts/verify-app.py <namespace>
```

Si una versión de Zabbix no resuelve los macros de usuario en las condiciones de Telemetry query, genera un template por
aplicación con el valor escrito: `python3 scripts/build-otlp-app-template.py --namespace <namespace>`.
