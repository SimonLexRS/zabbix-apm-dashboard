# Troubleshooting

## "The APM data source is not configured"

Configure **Administration → Data source → APM** (or `$TELEMETRY_PROVIDERS` in `zabbix.conf.php`). The modules use the
same connection as the native APM pages.

## "Cannot query the APM data source" / a section says ClickHouse did not answer

Check **APM → Traces** first: if it fails too, the problem is the connection or the user. If only some queries fail, look
at the ClickHouse error log:

```sh
grep '<Error> executeQuery' /var/log/clickhouse-server/clickhouse-server.err.log | tail
```

### MEMORY_LIMIT_EXCEEDED

`(total) memory limit exceeded` while the process uses much less memory than the limit usually comes from ClickHouse's
own system logs: the background merges of `system.metric_log` (hundreds of columns) can take more than 1 GB, fail and
start again forever. While that happens, APM queries and even the collector's inserts fail.

1. Add [examples/clickhouse-low-memory.xml](../examples/clickhouse-low-memory.xml) to `/etc/clickhouse-server/config.d/`
   (disables the unused system logs, raises the memory ratio) and restart ClickHouse.
2. Drop the tables that are no longer written, which stops their merges and frees disk space:

   ```sh
   for t in metric_log trace_log text_log asynchronous_metric_log processors_profile_log part_log; do
     clickhouse-client -q "DROP TABLE IF EXISTS system.$t SYNC"
   done
   ```

The tabs with several queries (User experience, Origin of slowness) show the failure only in the affected section and
retry on the next refresh; the queries that join spans use at most the last 6 hours of the period.

## The map has no database or arrows between services

- Databases come from client spans with `db.system.name` (current semantic conventions) or `db.system`.
- Arrows between services need trace context propagation: the client span of the caller must be the parent of the
  server span of the callee (W3C `traceparent`).
- The service filter of the page highlights services on the map, it never hides them.

## A widget says it needs APM Overview

The service map widget uses the code of the APM Overview module: enable both.

## A user sees fewer applications than an administrator, or none

The modules apply the **APM filter** of the user's groups (Users → User groups → APM filter), as the native APM pages
do: only telemetry whose resource attributes match a filter is shown, and Super admins are not filtered. Check that
the filter matches the data, e.g. Key `service.namespace` and the exact namespace. Without *APM → Logs* or *APM →
Metrics* in the role, logs and metrics are hidden as well. See
[install-module.md](install-module.md#step-4-see-data-and-give-access).

## The page is in English for a Spanish user

The language is the one of the Zabbix user (User settings → Profile → Language); `es_*` shows Spanish. "System default"
uses the frontend default language.
