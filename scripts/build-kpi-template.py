#!/usr/bin/env python3
"""Build "APM business metrics by ClickHouse HTTP" (templates/apm_business_metrics_clickhouse_http.json).

The template discovers the business metrics (KPIs) an application sends over OpenTelemetry, such as orders by store or
reservations by hotel, and keeps one item per metric and per value of its attributes. Its master item runs the query
of templates/src/apm_business_metrics.sql once a minute; discovery rules and items are dependent on it.

  python3 scripts/build-kpi-template.py            # write the template
  python3 scripts/build-kpi-template.py --check    # fail if the committed file is out of date (CI)
"""

import argparse
import json
import sys
import uuid
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SQL = ROOT / 'templates/src/apm_business_metrics.sql'
TOTALS_SQL = ROOT / 'templates/src/apm_business_totals.sql'
TARGET = ROOT / 'templates/apm_business_metrics_clickhouse_http.json'

NAME = 'APM business metrics by ClickHouse HTTP'
GROUP = 'Templates/Applications'
MASTER = 'apm.kpi.get'
TOTALS = 'apm.kpi.totals.get'
# Fixed namespace for the UUIDs, so the output only changes when the definitions do.
UUID_NAMESPACE = uuid.UUID('5b0c8f0e-2f8a-4b8e-9a43-1d2f7c6e9b21')

# Default filters. ApmOverview's BusinessMetrics class uses the same values (tests/run.php checks it).
METRIC_NOT_MATCHES = (
    r'^(otel[._]|otelcol|process\.|runtime\.|jvm\.|dotnet\.|kestrel\.|aspnetcore\.|http\.|rpc\.|db\.|dns\.|'
    r'system\.|go\.|cpython\.|nodejs\.|v8js\.|signalr\.|kafka\.|feature_flag\.|messaging\.|container\.|k8s\.)'
)
DIMENSION_NOT_MATCHES = r'(^|[._])(id|uuid|trace|span|session|user|email|url|ip|address)$'

MACROS = [
    ('{$APM.KPI.DELAY}', '60', 'Seconds the query waits for a minute to be complete before measuring it (export '
        'interval of the applications plus the batching of the collector). The default fits a 60 s export interval.'),
    ('{$APM.KPI.DIMENSION.NOT_MATCHES}', DIMENSION_NOT_MATCHES, 'Regex: attribute keys that are not broken down into '
        'their own items (identifiers and other keys with too many values).'),
    ('{$APM.KPI.DISCOVERY.WINDOW}', '60', 'Minutes: metrics and attribute values seen within this window are '
        'discovered, and report 0 in the minutes without activity.'),
    ('{$APM.KPI.LIMIT}', '1000', 'Maximum number of rows (metrics plus attribute values) returned by the query.'),
    ('{$APM.KPI.TIMEZONE}', 'UTC', 'Time zone of the days of "today" and "yesterday", e.g. America/La_Paz (IANA '
        'name, as ClickHouse knows it).'),
    ('{$APM.KPI.TOTALS.INTERVAL}', '2m', 'Update interval of the totals (last hour, last 24 hours, today, yesterday, '
        'total of the counter, share and rank), read from ClickHouse.'),
    ('{$APM.KPI.METRIC.MATCHES}', '.*', 'Regex: metrics to discover, e.g. ^(shop|hotel)\\. to keep only your own.'),
    ('{$APM.KPI.METRIC.NOT_MATCHES}', METRIC_NOT_MATCHES, 'Regex: metrics to ignore. By default the runtime, HTTP, '
        'database and SDK metrics of the OpenTelemetry instrumentation, which are not business metrics.'),
    ('{$APM.KPI.VALUES.LIMIT}', '20', 'Maximum number of values per attribute and metric (the busiest in the '
        'discovery window). The others are still counted in the total of the metric.'),
    ('{$APM.NAMESPACE}', '', 'Application of this host: the service.namespace resource attribute its services send. '
        'Required. scripts/zabbix-apm-app.py add --kpi sets it.'),
    ('{$APM.SERVICE.MATCHES}', '.*', 'Regex: services whose metrics are discovered.'),
    ('{$APM.SERVICE.NOT_MATCHES}', 'CHANGE_IF_NEEDED', 'Regex: services whose metrics are ignored.'),
    ('{$CH.DB}', 'zabbix', 'ClickHouse database with the APM tables.'),
    ('{$CH.PASSWORD}', None, 'ClickHouse password (X-ClickHouse-Key).'),
    ('{$CH.URL}', 'http://localhost:8123/', 'ClickHouse HTTP interface URL, reachable from the Zabbix server or proxy.'),
    ('{$CH.USER}', 'zabbix_ro', 'ClickHouse read-only user (X-ClickHouse-User).'),
]

QUERY_FIELDS = [
    ('param_db', '{$CH.DB}'), ('param_ns', '{$APM.NAMESPACE}'), ('param_delay', '{$APM.KPI.DELAY}'),
    ('param_window', '{$APM.KPI.DISCOVERY.WINDOW}'), ('param_metric_matches', '{$APM.KPI.METRIC.MATCHES}'),
    ('param_metric_not_matches', '{$APM.KPI.METRIC.NOT_MATCHES}'),
    ('param_dim_not_matches', '{$APM.KPI.DIMENSION.NOT_MATCHES}'), ('param_values_limit', '{$APM.KPI.VALUES.LIMIT}'),
    ('param_limit', '{$APM.KPI.LIMIT}'),
]

TOTALS_FIELDS = [
    ('param_db', '{$CH.DB}'), ('param_ns', '{$APM.NAMESPACE}'), ('param_delay', '{$APM.KPI.DELAY}'),
    ('param_tz', '{$APM.KPI.TIMEZONE}'), ('param_metric_matches', '{$APM.KPI.METRIC.MATCHES}'),
    ('param_metric_not_matches', '{$APM.KPI.METRIC.NOT_MATCHES}'),
    ('param_dim_not_matches', '{$APM.KPI.DIMENSION.NOT_MATCHES}'), ('param_limit', '{$APM.KPI.LIMIT}'),
]

# LLD macros: the value of the item (count for a histogram), the label of the value and its units.
LLD_JS = '''var rows = JSON.parse(value).data, out = [];
var labels = {counter: 'per minute', updown: 'current', gauge: 'current', histogram: 'count per minute'};
for (var i = 0; i < rows.length; i++) {
    var r = rows[i];
    if ((r.dim_key === '') !== %s) {
        continue;
    }
    out.push({service: r.service, metric: r.metric, kind: r.kind, label: labels[r.kind] || 'value',
        unit: r.kind === 'histogram' ? '' : r.unit, sum_unit: r.unit, description: r.description || r.metric,
        dim: r.dim_key, dim_value: r.dim_value});
}
return JSON.stringify(out);'''

LLD_PATHS = {
    '{#SERVICE}': '$.service', '{#METRIC}': '$.metric', '{#KIND}': '$.kind', '{#LABEL}': '$.label',
    '{#UNIT}': '$.unit', '{#SUM.UNIT}': '$.sum_unit', '{#DESCRIPTION}': '$.description',
}
DIM_PATHS = {'{#DIM}': '$.dim', '{#DIMVALUE}': '$.dim_value'}

HOW_TO_SUM = ('For a total read the "last hour", "last 24 hours", "today" and "yesterday" items (from ClickHouse), or '
    'sum the history of the "per hour" item over a longer period.')
FROM_CLICKHOUSE = (' Calculated from ClickHouse, so it is complete even for an item created today (ClickHouse keeps 3 '
    'days by default).')


def tags(dimension: bool) -> list:
    result = [('component', 'kpi'), ('kind', '{#KIND}'), ('kpi', '{#METRIC}'), ('service', '{#SERVICE}')]

    if dimension:
        result += [('dimension', '{#DIM}'), ('{#DIM}', '{#DIMVALUE}')]

    return [{'tag': tag, 'value': value} for tag, value in sorted(result)]


def prototypes(dimension: bool) -> list:
    if dimension:
        args = '"{#SERVICE}","{#METRIC}","{#DIM}","{#DIMVALUE}"'
        title = 'KPI {#METRIC} {#DIM}={#DIMVALUE} ({#SERVICE})'
        where = "@.service=='{#SERVICE}' && @.metric=='{#METRIC}' && @.dim_key=='{#DIM}' && @.dim_value=='{#DIMVALUE}'"
        scope = ' for {#DIM} = {#DIMVALUE}'
    else:
        args = '"{#SERVICE}","{#METRIC}"'
        title = 'KPI {#METRIC} ({#SERVICE})'
        where = "@.service=='{#SERVICE}' && @.metric=='{#METRIC}' && @.dim_key==''"
        scope = ''

    def dependent(key: str, label: str, field: str, units: str, description: str, master: str = MASTER,
            history: str = '31d') -> dict:
        return {
            'name': f'{title}: {label}', 'type': 'DEPENDENT', 'key': f'{key}[{args}]', 'history': history,
            'value_type': 'FLOAT', 'units': units, 'description': description,
            'preprocessing': [{'type': 'JSONPATH', 'parameters': [f'$.data[?({where})].{field}.first()'],
                               'error_handler': 'DISCARD_VALUE'}],
            'master_item': {'key': master}, 'tags': tags(dimension)
        }

    def calculated(key: str, label: str, formula: str, delay: str, units: str, description: str,
            history: str = '31d') -> dict:
        return {
            'name': f'{title}: {label}', 'type': 'CALCULATED', 'key': f'{key}[{args}]', 'delay': delay,
            'history': history, 'value_type': 'FLOAT', 'units': units, 'params': formula, 'description': description,
            'tags': tags(dimension)
        }

    def total(key: str, label: str, field: str, units: str, description: str, history: str = '31d') -> dict:
        return dependent(key, label, field, units, '{#DESCRIPTION}' + scope + ': ' + description + FROM_CLICKHOUSE,
            TOTALS, history)

    value = f'//apm.kpi.value[{args}]'

    items = [
        dependent('apm.kpi.value', '{#LABEL}', 'value', '{#UNIT}',
            '{#DESCRIPTION}. OpenTelemetry metric {#METRIC} ({#KIND}) of service {#SERVICE}' + scope + '. '
            'Counter: increase in each minute. Histogram: number of measurements in each minute. Up-down counter or '
            'gauge: current value. ' + HOW_TO_SUM),
        total('apm.kpi.1h', 'last hour', 'last_hour', '{#UNIT}', 'total of the last 60 minutes.'),
        total('apm.kpi.24h', 'last 24 hours', 'last_24h', '{#UNIT}', 'total of the last 24 hours.'),
        total('apm.kpi.today', 'today', 'today', '{#UNIT}', 'total since 00:00 today ({$APM.KPI.TIMEZONE}).'),
        total('apm.kpi.yesterday', 'yesterday', 'yesterday', '{#UNIT}',
            'total of yesterday ({$APM.KPI.TIMEZONE}). Its history keeps the total of each day.', history='400d'),
        total('apm.kpi.total', 'total (counter)', 'counter', '{#UNIT}',
            'value that the counter of the application reports now, i.e. its total since the application started '
            '(summed over its running instances). It starts again from 0 when the application restarts; compare it '
            'with the figures of the application itself.'),
        calculated('apm.kpi.hour', 'per hour', f'sum({value},1h:now/h)', '0;m5', '{#UNIT}',
            '{#DESCRIPTION}' + scope + ': total of the previous clock hour, stored at minute 5 of the next one. One '
            'value per hour: sum them for a period of several hours or days.', history='400d'),
        calculated('apm.kpi.7d', 'last 7 days', f'sum(//apm.kpi.hour[{args}],7d)', '1h', '{#UNIT}',
            '{#DESCRIPTION}' + scope + ': sum of the "per hour" values of the last 7 days. From the history of Zabbix: '
            'it only counts since the item exists.', history='400d'),
        dependent('apm.kpi.sum', 'sum per minute', 'sum', '{#SUM.UNIT}',
            '{#DESCRIPTION}' + scope + ': sum of the measurements in each minute (e.g. the amount of the orders). '
            + HOW_TO_SUM),
        total('apm.kpi.sum.today', 'sum today', 'sum_today', '{#SUM.UNIT}',
            'sum of the measurements since 00:00 today (e.g. the amount of the orders).'),
        total('apm.kpi.sum.yesterday', 'sum yesterday', 'sum_yesterday', '{#SUM.UNIT}',
            'sum of the measurements of yesterday.', history='400d'),
        total('apm.kpi.avg.today', 'average today', 'avg_today', '{#SUM.UNIT}',
            'average measurement since 00:00 today (e.g. the average order amount).'),
    ]

    if dimension:
        items += [
            total('apm.kpi.share.today', 'share today', 'share_today', '%',
                'share of today of {#DIM} = {#DIMVALUE} within all the values of {#DIM}, to compare them.'),
            total('apm.kpi.rank.today', 'rank today', 'rank_today', '',
                'position of {#DIMVALUE} among the values of {#DIM} by today (1 = the largest).'),
        ]

    return items


def overrides() -> list:
    def override(step: int, name: str, kinds: str, operator: str, names: str) -> dict:
        return {
            'name': name, 'step': str(step),
            'filter': {'conditions': [{'macro': '{#KIND}', 'value': kinds, 'operator': operator}]},
            'operations': [{'operationobject': 'ITEM_PROTOTYPE', 'operator': 'REGEXP', 'value': names,
                            'discover': 'NO_DISCOVER'}]
        }

    return [
        override(1, 'Current values have no totals', '^(gauge|updown)$', 'MATCHES_REGEX',
            ': (last hour|last 24 hours|per hour|last 7 days|today|yesterday|total \\(counter\\)|share today|'
            'rank today)$'),
        override(2, 'Only histograms have a sum and an average', '^histogram$', 'NOT_MATCHES_REGEX',
            ': (sum per minute|sum today|sum yesterday|average today)$'),
    ]


def discovery(dimension: bool) -> dict:
    paths = LLD_PATHS | (DIM_PATHS if dimension else {})

    return {
        'name': 'APM business metric values discovery' if dimension else 'APM business metrics discovery',
        'type': 'DEPENDENT',
        'key': 'apm.kpi.dimension.discovery' if dimension else 'apm.kpi.discovery',
        'filter': {
            'evaltype': 'AND',
            'conditions': [
                {'macro': '{#SERVICE}', 'value': '{$APM.SERVICE.MATCHES}'},
                {'macro': '{#SERVICE}', 'value': '{$APM.SERVICE.NOT_MATCHES}', 'operator': 'NOT_MATCHES_REGEX'},
            ]
        },
        'lifetime_type': 'DELETE_AFTER', 'lifetime': '30d',
        'enabled_lifetime_type': 'DISABLE_AFTER', 'enabled_lifetime': '1d',
        'description': (
            'One set of items per value of each attribute of a business metric (e.g. orders by shop.store = Centro), '
            'up to {$APM.KPI.VALUES.LIMIT} values per attribute. Attribute keys matching '
            '{$APM.KPI.DIMENSION.NOT_MATCHES} are not broken down.' if dimension else
            'Business metrics of the application seen in the last {$APM.KPI.DISCOVERY.WINDOW} minutes: every '
            'OpenTelemetry counter, up-down counter, gauge and histogram matching {$APM.KPI.METRIC.MATCHES} and not '
            '{$APM.KPI.METRIC.NOT_MATCHES}.'),
        'item_prototypes': prototypes(dimension),
        'master_item': {'key': MASTER},
        'lld_macro_paths': [{'lld_macro': macro, 'path': path} for macro, path in sorted(paths.items())],
        'preprocessing': [
            {'type': 'JAVASCRIPT', 'parameters': [LLD_JS % ('false' if dimension else 'true')]},
            # Discovery runs when the metrics or values change, and at least every 15 minutes, so that an update of
            # the template reaches the discovered items soon.
            {'type': 'DISCARD_UNCHANGED_HEARTBEAT', 'parameters': ['15m']},
        ],
        'overrides': overrides(),
    }


def build() -> dict:
    # Without the header comment of the files; the comments inside the queries stay.
    def query(path: Path) -> str:
        return '\n'.join(line for line in path.read_text(encoding='utf-8').splitlines()
            if not line.startswith('--')).strip() + '\n'

    sql = query(SQL)

    master = {
        'name': 'APM business metrics: Get', 'type': 'HTTP_AGENT', 'key': MASTER, 'history': '0',
        'value_type': 'TEXT', 'timeout': '15s', 'url': '{$CH.URL}',
        'query_fields': [{'name': name, 'value': value} for name, value in QUERY_FIELDS],
        'posts': sql,
        'headers': [{'name': 'X-ClickHouse-User', 'value': '{$CH.USER}'},
                    {'name': 'X-ClickHouse-Key', 'value': '{$CH.PASSWORD}'}],
        'request_method': 'POST',
        'description': 'Business metrics of the application in the last closed minute: one row per metric and one per '
            'value of each attribute. The discovery rules and the KPI items are dependent on this item.',
        'tags': [{'tag': 'component', 'value': 'kpi'}],
    }
    totals = master | {
        'name': 'APM business metrics: Get totals', 'key': TOTALS, 'delay': '{$APM.KPI.TOTALS.INTERVAL}',
        'timeout': '30s',
        'query_fields': [{'name': name, 'value': value} for name, value in TOTALS_FIELDS],
        'posts': query(TOTALS_SQL),
        'description': 'Totals of the business metrics from ClickHouse: last hour, last 24 hours, today, yesterday, the '
            'value of each counter, and the share and rank of each attribute value. The total items are dependent on '
            'this item.',
    }
    rows = {
        'name': 'APM business metrics: Rows', 'type': 'DEPENDENT', 'key': 'apm.kpi.rows', 'history': '7d',
        'description': 'Rows returned by the query: business metrics plus their attribute values. It stops receiving '
            'values when ClickHouse cannot be queried.',
        'preprocessing': [{'type': 'JSONPATH', 'parameters': ['$.rows']}],
        'master_item': {'key': MASTER},
        'tags': [{'tag': 'component', 'value': 'kpi'}],
        'triggers': [{
            'expression': f'nodata(/{NAME}/apm.kpi.rows,10m)=1',
            'name': 'APM business metrics: no data from ClickHouse',
            'event_name': 'APM business metrics: the ClickHouse query has returned nothing for 10 minutes',
            'priority': 'WARNING',
            'description': 'Check {$CH.URL}, {$CH.USER} and {$CH.PASSWORD}, and the Latest data of the item '
                '"APM business metrics: Get" (its error).',
            'tags': [{'tag': 'scope', 'value': 'availability'}],
        }],
    }
    macros = []

    for macro, value, description in MACROS:
        entry = {'macro': macro}

        if value is None:
            entry['type'] = 'SECRET_TEXT'
        elif value != '':
            entry['value'] = value

        entry['description'] = description
        macros.append(entry)

    template = {
        'uuid': NAME,
        'template': NAME,
        'name': NAME,
        'description': (
            'Version 1.2. Business metrics (KPIs) of one application from its OpenTelemetry metrics in ClickHouse '
            '(otel_metrics_sum, otel_metrics_gauge, otel_metrics_histogram), through the ClickHouse HTTP interface: '
            'orders by store, reservations by hotel, revenue, carts abandoned... whatever counters, gauges and '
            'histograms the application sends.\n\n'
            'Link it to the host of the application (apm-<namespace>, see scripts/zabbix-apm-app.py add --kpi) and '
            'set {$APM.NAMESPACE}. Each metric gets an item with its value per minute and per hour, the totals of the '
            'last hour, last 24 hours, today and yesterday (from ClickHouse, complete from the first minute) and the '
            'value of its counter; each value of its attributes (store, hotel, currency...) gets the same items plus '
            'its share and rank of today, to compare them. Two queries (per minute and totals); everything else is '
            'dependent or calculated.\n\n'
            'Docs: https://github.com/SimonLexRS/zabbix-apm-dashboard/blob/main/docs/business-kpis.md'),
        'groups': [{'name': GROUP}],
        'items': [master, totals, rows],
        'discovery_rules': [discovery(False), discovery(True)],
        'tags': [{'tag': 'class', 'value': 'application'}, {'tag': 'target', 'value': 'apm'}],
        'macros': macros,
    }
    # Template, items, prototypes, rules and triggers need a UUID: derived from their name, key or expression.
    # Zabbix only accepts version 4 UUIDs.
    def stamp(node) -> None:
        if isinstance(node, dict):
            seed = node.get('uuid') or (node.get('key') if 'type' in node else None) or node.get('expression')

            if seed:
                node['uuid'] = uuid.UUID(bytes=uuid.uuid5(UUID_NAMESPACE, seed).bytes, version=4).hex

            for val in node.values():
                stamp(val)
        elif isinstance(node, list):
            for val in node:
                stamp(val)

    stamp(template)
    # Same UUID as the group of Zabbix's own templates and of "APM services by ClickHouse HTTP v2".
    data = {'zabbix_export': {'version': '8.0',
                              'template_groups': [{'uuid': '9d9b1911c05b4f8db5931564f780fa71', 'name': GROUP}],
                              'templates': [template]}}

    # Zabbix exports keys in a fixed order; keep uuid first for readability.
    def uuid_first(node):
        if isinstance(node, dict):
            return {k: uuid_first(node[k]) for k in sorted(node, key=lambda k: k != 'uuid')}
        if isinstance(node, list):
            return [uuid_first(v) for v in node]
        return node

    return uuid_first(data)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__.split('\n')[0])
    parser.add_argument('--check', action='store_true', help='fail if the committed template is out of date')
    args = parser.parse_args()

    output = json.dumps(build(), indent=4, ensure_ascii=False) + '\n'

    if args.check:
        if not TARGET.exists() or TARGET.read_text(encoding='utf-8') != output:
            print(f'{TARGET.relative_to(ROOT)} is out of date: run scripts/build-kpi-template.py', file=sys.stderr)
            return 1

        return 0

    TARGET.write_text(output, encoding='utf-8')
    print(f'Wrote {TARGET.relative_to(ROOT)}')

    return 0


if __name__ == '__main__':
    sys.exit(main())
