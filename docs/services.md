# Service map, service pages, versions and Zabbix problems

[Español](services.es.md)

## Reading the map

**APM → Overview → Service map.** Users on the left, dependencies on the right.

| You see | It means |
|---|---|
| Size of a circle | Its traffic |
| Letters inside (`Go`, `Java`, `.NET`, `JS`, `Py`...) | Its technology (`telemetry.sdk.language`); a stack for a proxy or web server, a window for a browser |
| Ring | Its health **now** (the last minutes: filter *"Now" window*). Green when healthy; all amber or red when it is slow or has open Zabbix problems |
| Red arc on the ring | The share of its requests that fail now, from the top: a quarter of the ring is 25 % |
| Cylinder, capsule, dashed diamond | Database, queue, external host |
| Figure under a node | What is wrong with it; when nothing is, its requests per minute |
| Grey line | Calls without problems. Amber or red: errors or slowness on that connection now |
| Small numbered mark | Open Zabbix problems of that service, in the color of the highest severity |

Point at a node to follow its calls. **Click it** to keep only its path and open its panel; Escape brings the map back. Above the map: search, *Only what has a problem*, *Hide databases, queues and external services*.

## Zabbix problems on a service

A problem belongs to a service when it carries the tag **`service` = the service name** (on the trigger, the item, the template or the host).

```
Tag: service   Value: checkout
```

| Problems of | Where they show |
|---|---|
| The service (tag `service`) | Mark on its node, its health, its panel and its page |
| The application host `apm-<namespace>` | Summary line above the map, panel and page |
| A Zabbix host named as the `host.name` of the service | Panel and page |

Severity Average or higher makes the node a problem; Warning, a warning. Only the problems you are allowed to see are read.

## Page of a service and of an endpoint

From the panel, the **Services** and **Endpoints** tabs, or any service name.

| Tab | What it answers |
|---|---|
| **Overview** | How is it doing, against yesterday and last week? Who calls it, what does it call, where does its time go? |
| **Errors and versions** | What fails, since which version, and what changed between the last two versions |
| **Why slow, why failing** | Which attribute values (route, version, host, status code...) appear much more in the slowest 5 % of the requests, or in the failed ones |

The page of an endpoint adds its latency distribution and what a request is made of: each operation below it, how often it runs and how much of the time it takes.

## Versions and deployments

They need `service.version` on the service:

```sh
export OTEL_RESOURCE_ATTRIBUTES=service.namespace=shop,service.version=1.4.2
```

A version first seen inside the period, while another one was there before, is a **deployment**: it is marked on the charts, in the panel and in the version table. An error group says which version brought it, or brought it back.

## Trying it without breaking anything

```sh
# A version that works, then one that fails
python3 scripts/test-rum.py opentelemetry-demo --send-error http://127.0.0.1:4318 --version 1.0.0 --healthy
python3 scripts/test-rum.py opentelemetry-demo --send-error http://127.0.0.1:4318 --version 1.1.0
```

✅ The page of `rum-test-api` shows 1.1.0 as *deployed in this period*, the error as *only in 1.1.0*, and the map draws `rum-test-web → rum-test-api` in red.
