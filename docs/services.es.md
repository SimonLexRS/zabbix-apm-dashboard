# Mapa de servicios, páginas de servicio, versiones y problemas de Zabbix

[English](services.md)

## Cómo leer el mapa

**APM → Overview → Mapa de servicios.** Usuarios a la izquierda, dependencias a la derecha.

| Ves | Significa |
|---|---|
| Tamaño de un círculo | Su tráfico |
| Letras dentro (`Go`, `Java`, `.NET`, `JS`, `Py`...) | Su tecnología (`telemetry.sdk.language`); una pila para un proxy o servidor web, una ventana para un navegador |
| Anillo | Su salud **ahora** (los últimos minutos: filtro *Ventana «ahora»*). Verde si está sano; todo ámbar o rojo si va lento o tiene problemas abiertos en Zabbix |
| Arco rojo sobre el anillo | La parte de sus peticiones que falla ahora, desde arriba: un cuarto del anillo es el 25 % |
| Cilindro, cápsula, rombo punteado | Base de datos, cola, host externo |
| Cifra bajo un nodo | Lo que tiene mal; si no tiene nada, sus peticiones por minuto |
| Línea gris | Llamadas sin problemas. Ámbar o roja: errores o lentitud en esa conexión ahora |
| Marca pequeña con número | Problemas abiertos en Zabbix de ese servicio, con el color de la severidad más alta |

Señala un nodo para seguir sus llamadas. **Haz clic** para dejar solo su camino y abrir su panel; Escape devuelve el mapa. Sobre el mapa: buscador, *Solo lo que tiene un problema*, *Ocultar bases de datos, colas y servicios externos*.

## Problemas de Zabbix en un servicio

Un problema pertenece a un servicio cuando lleva la etiqueta **`service` = nombre del servicio** (en el trigger, el item, la plantilla o el host).

```
Tag: service   Value: checkout
```

| Problemas de | Dónde se ven |
|---|---|
| El servicio (etiqueta `service`) | Marca en su nodo, su salud, su panel y su página |
| El host de la aplicación `apm-<namespace>` | Línea de resumen sobre el mapa, panel y página |
| Un host de Zabbix con el nombre del `host.name` del servicio | Panel y página |

Severidad Average o mayor pone el nodo en problema; Warning, en aviso. Solo se leen los problemas que tu usuario puede ver.

## Página de un servicio y de un endpoint

Desde el panel, las pestañas **Servicios** y **Endpoints**, o cualquier nombre de servicio.

| Pestaña | Qué responde |
|---|---|
| **Resumen** | ¿Cómo va, frente a ayer y a la semana pasada? ¿Quién lo llama, a qué llama, en qué se le va el tiempo? |
| **Errores y versiones** | Qué falla, desde qué versión, y qué cambió entre las dos últimas versiones |
| **Por qué es lento, por qué falla** | Qué valores de atributos (ruta, versión, host, código de estado...) aparecen mucho más en el 5 % más lento de las peticiones, o en las fallidas |

La página de un endpoint añade su distribución de latencia y de qué está hecha una petición: cada operación por debajo, cuántas veces corre y qué parte del tiempo se lleva.

## Versiones y despliegues

Necesitan `service.version` en el servicio:

```sh
export OTEL_RESOURCE_ATTRIBUTES=service.namespace=shop,service.version=1.4.2
```

Una versión vista por primera vez dentro del periodo, cuando ya había otra antes, es un **despliegue**: se marca en los gráficos, en el panel y en la tabla de versiones. Cada grupo de errores indica qué versión lo trajo, o lo trajo de vuelta.

## Probarlo sin romper nada

```sh
# Una versión que funciona y luego una que falla
python3 scripts/test-rum.py opentelemetry-demo --send-error http://127.0.0.1:4318 --version 1.0.0 --healthy
python3 scripts/test-rum.py opentelemetry-demo --send-error http://127.0.0.1:4318 --version 1.1.0
```

✅ La página de `rum-test-api` muestra 1.1.0 como *desplegada en este periodo*, el error como *solo en 1.1.0*, y el mapa dibuja `rum-test-web → rum-test-api` en rojo.
