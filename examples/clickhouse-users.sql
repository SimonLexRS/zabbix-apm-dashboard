-- ClickHouse users for Zabbix APM. Run with clickhouse-client as an admin user, after replacing the passwords.
-- The collector creates the tables in database "zabbix" (examples/otelcol-config.yaml, create_schema: true).

CREATE DATABASE IF NOT EXISTS zabbix;

-- Writer: used by the OpenTelemetry Collector (creates and fills the otel_* tables).
CREATE USER IF NOT EXISTS otel_writer IDENTIFIED WITH sha256_password BY '<writer-password>';
GRANT SELECT, INSERT, CREATE TABLE, ALTER, SHOW ON zabbix.* TO otel_writer;

-- Reader: used by the Zabbix frontend (Administration -> Data source -> APM, which these modules reuse) and by the
-- template items ({$CH.USER} / {$CH.PASSWORD}). Read-only.
CREATE USER IF NOT EXISTS zabbix_ro IDENTIFIED WITH sha256_password BY '<reader-password>';
GRANT SELECT ON zabbix.* TO zabbix_ro;
