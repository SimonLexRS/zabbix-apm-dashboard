#!/usr/bin/env python3
"""Build "OpenTelemetry application by OTLP" from Zabbix's official "Generic OpenTelemetry by OTLP".

The official template has no application filter: a host linked to it measures all the telemetry in ClickHouse. This
copy adds the condition ResourceAttributes['service.namespace'] = {$OTLP.NAMESPACE} to every Telemetry query item, so
one host per application (macro set on the host) measures only that application.

  python3 scripts/build-otlp-app-template.py                 # write templates/otel_application_otlp.json
  python3 scripts/build-otlp-app-template.py --check         # fail if the committed file is out of date (CI)
  python3 scripts/build-otlp-app-template.py --namespace X   # fixed namespace, for Zabbix versions without macros in
                                                             # conditions: template "OpenTelemetry X by OTLP"

It also makes the problems close by themselves: an interval without spans or log records is a 0 (not a discarded value
or an unsupported item), "No spans received" tells that the application stopped sending, and every trigger links to the
APM overview of the application.

Source: templates/upstream/generic_opentelemetry_by_otlp.json, exported from Zabbix (configuration.export, JSON).
"""

import argparse
import json
import re
import sys
import uuid
import urllib.parse
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SOURCE = ROOT / 'templates/upstream/generic_opentelemetry_by_otlp.json'
TARGET = ROOT / 'templates/otel_application_otlp.json'

UPSTREAM_NAME = 'Generic OpenTelemetry by OTLP'
NAMESPACE_MACRO = '{$OTLP.NAMESPACE}'
# Fixed namespace for the derived UUIDs, so the output only changes when the source does.
UUID_NAMESPACE = uuid.UUID('0f6b1c43-3a5e-4c7e-9d3e-6a1f5c2b7e10')


NODATA_MACRO = '{$SPAN.NODATA}'
SPAN_COUNTS = ('otlp.span.status.unset', 'otlp.span.status.ok', 'otlp.span.status.error')
SPAN_DURATIONS = tuple(key + '.duration' for key in SPAN_COUNTS)
LOGS_MASTER = 'otlp.logs.get'
ERROR_PERCENTAGE = 'otlp.span.error.percentage'
SPAN_TOTAL = '+'.join(f'last(//otlp.span.status.{status})' for status in ('ok', 'error', 'unset'))
# The Telemetry query fails with this error when the interval has no rows (the official count items already read it as 0).
NO_VALUE = '^No value$'
# Module page of the application, opened from the trigger menu (user macros are resolved in trigger URLs).
OVERVIEW_URL = 'zabbix.php?action=apm.overview&tab={tab}&filter_set=1&filter_app={app}'
OVERVIEW_URL_NAME = 'APM overview'

# Zabbix 8.0.0rc3 returns Telemetry query results as rows, [{"clock":…,"count":55}], while the "$.columns.<alias>" paths
# of the official template expect {"columns":{"count":55}}. Rows are turned into that shape; the other shape passes as is.
# One query normally returns one bucket ({$OTLP.GRANULARITY}), but it can return several (first run, or after a pause:
# up to {$OTLP.LOOPBACK}) and their order is not documented: the row of the newest bucket is taken, by its clock. The
# query range only contains whole buckets, so that bucket is complete. Empty aggregates (avg of no rows: null) are left
# out, so that the path fails and the item gets its "no rows" value.
ROWS_TO_COLUMNS = (
    'var v = JSON.parse(value), row = {}, columns = {};\n'
    'if (!Array.isArray(v)) {\n'
    '\treturn value;\n'
    '}\n'
    'for (var i = 0; i < v.length; i++) {\n'
    '\tif (i === 0 || Number(v[i].clock) > Number(row.clock)) {\n'
    '\t\trow = v[i];\n'
    '\t}\n'
    '}\n'
    'for (var k in row) {\n'
    '\tif (row[k] !== null) {\n'
    '\t\tcolumns[k] = row[k];\n'
    '\t}\n'
    '}\n'
    'return JSON.stringify({columns: columns});'
)

# Log records of one severity range: the official path, "$[?(…)].count.first()", read only the first SeverityNumber of
# the range and failed (value discarded, so the last count stayed forever) when the range had no rows. All the rows of
# the range in the newest bucket are added up (counts may arrive as strings) and no rows is 0.
LOG_SEVERITY_SUM = (
    'var rows = JSON.parse(value), newest = null, total = 0, i, clock, severity, count;\n'
    'if (!Array.isArray(rows)) {\n'
    '\tthrow \'Unexpected result of "Get logs": \' + value.substring(0, 200);\n'
    '}\n'
    'for (i = 0; i < rows.length; i++) {\n'
    '\tclock = Number(rows[i].clock);\n'
    '\tif (!isNaN(clock) && (newest === null || clock > newest)) {\n'
    '\t\tnewest = clock;\n'
    '\t}\n'
    '}\n'
    'for (i = 0; i < rows.length; i++) {\n'
    '\tseverity = Number(rows[i].SeverityNumber);\n'
    '\tcount = Number(rows[i].count);\n'
    '\tif (severity >= %(min)d && severity <= %(max)d && !isNaN(count)\n'
    '\t\t\t&& (newest === null || Number(rows[i].clock) === newest)) {\n'
    '\t\ttotal += count;\n'
    '\t}\n'
    '}\n'
    'return total;'
)
LOG_SEVERITY_PATH = re.compile(
    r'^\$\[\?\(@\.SeverityNumber >= (\d+) && @\.SeverityNumber <= (\d+)\)\]\.count\.first\(\)$'
)


def normalize_rows(item: dict) -> None:
    steps = item.get('preprocessing', [])
    paths = [i for i, step in enumerate(steps)
        if step['type'] == 'JSONPATH' and step['parameters'][0].startswith('$.columns.')]

    if not paths:
        return

    path = steps[paths[0]]
    steps.insert(paths[0], {'type': 'JAVASCRIPT', 'parameters': [ROWS_TO_COLUMNS]})

    # No rows in the interval: a count is 0 and so is the duration of the spans (otherwise the item keeps its last
    # value, or becomes unsupported, and its problems never close); other averages have no value (e.g. no uptime).
    if path['parameters'][0] == '$.columns.count' or item['key'] in SPAN_DURATIONS:
        path.update({'error_handler': 'CUSTOM_VALUE', 'error_handler_params': '0'})
    else:
        path.update({'error_handler': 'DISCARD_VALUE'})

    if item['key'] in SPAN_DURATIONS and steps[0]['type'] != 'CHECK_NOT_SUPPORTED':
        steps.insert(0, no_value_step('{"columns":{}}'))

    # Every value of the span counts is stored: "No spans received" looks at a period, and with the official "discard
    # unchanged" (heartbeat 1 h) a count that stays at 0 would have no values in it.
    if item['key'] in SPAN_COUNTS:
        steps[:] = [step for step in steps if step['type'] != 'DISCARD_UNCHANGED_HEARTBEAT']


def no_value_step(custom_value: str) -> dict:
    """The "check for not supported value" step of the official count items: no rows in the interval is this value."""
    return {'type': 'CHECK_NOT_SUPPORTED', 'parameters': ['0', NO_VALUE], 'error_handler': 'CUSTOM_VALUE',
        'error_handler_params': custom_value}


def sum_log_severity(item: dict) -> None:
    for step in item.get('preprocessing', []):
        match = step['type'] == 'JSONPATH' and LOG_SEVERITY_PATH.match(step['parameters'][0])

        if match:
            step.clear()
            step.update({'type': 'JAVASCRIPT',
                'parameters': [LOG_SEVERITY_SUM % {'min': int(match[1]), 'max': int(match[2])}]})


def overview_url(key: str, app: str) -> dict:
    tab = 'slow' if key.endswith('.duration') else 'errors'

    return {'url': OVERVIEW_URL.format(tab=tab, app=app), 'url_name': OVERVIEW_URL_NAME}


def no_spans_trigger(name: str, app: str) -> dict:
    """The application stopped sending: every span count was 0 during the period, or the counts are not collected
    (ClickHouse or the Telemetry query fail). It closes with the first interval that has spans again."""
    zero = ' and '.join(f'max(/{name}/{key},{NODATA_MACRO})=0' for key in SPAN_COUNTS)

    return {
        'uuid': uuid.UUID(bytes=uuid.uuid5(UUID_NAMESPACE, name + '/no-spans').bytes, version=4).hex,
        'expression': f'({zero}) or nodata(/{name}/{SPAN_COUNTS[0]},{NODATA_MACRO})=1',
        'name': 'OpenTelemetry: No spans received',
        'event_name': f'OpenTelemetry: No spans received for {NODATA_MACRO}',
        'priority': 'AVERAGE',
        'description': f'No span of the application arrived for {NODATA_MACRO}, or the span counts are not being '
            'collected: check that the application and the collector are running and the state of the "Span: ... '
            'count" items. It closes by itself when spans arrive again.',
        'url': OVERVIEW_URL.format(tab='status', app=app),
        'url_name': OVERVIEW_URL_NAME,
        'tags': [{'tag': 'scope', 'value': 'availability'}]
    }


def build(source: dict, namespace: str | None) -> dict:
    name = f'OpenTelemetry {namespace} by OTLP' if namespace else 'OpenTelemetry application by OTLP'
    value = namespace if namespace else NAMESPACE_MACRO

    data = json.loads(json.dumps(source).replace(UPSTREAM_NAME, name))
    template = data['zabbix_export']['templates'][0]

    template['description'] = (
        f'Copy of the official "{UPSTREAM_NAME}" limited to one application: every Telemetry query item only reads '
        f'telemetry whose resource attribute service.namespace is {value}. Link it to one host per application'
        + ('' if namespace else f' and set {NAMESPACE_MACRO} on the host') + '.\n\n'
        'Generated by scripts/build-otlp-app-template.py (zabbix-apm-dashboard); do not edit by hand.'
    )
    template.pop('readme', None)
    template.pop('wizard_ready', None)

    # In the URL the fixed namespace is encoded; the macro is resolved by Zabbix as it is.
    app = urllib.parse.quote(namespace, safe='') if namespace else NAMESPACE_MACRO
    keys = {item['key'] for item in template['items']}
    assert keys.issuperset(SPAN_COUNTS + SPAN_DURATIONS + (LOGS_MASTER, ERROR_PERCENTAGE)), 'item keys changed upstream'

    for item in template['items']:
        for trigger in item.get('triggers', []):
            trigger.update(overview_url(item['key'], app))

        sum_log_severity(item)

        if item['key'] == ERROR_PERCENTAGE:
            # Without spans the official formula divides by zero: the item became unsupported and its problem stayed
            # open. The comparison adds 1 to an empty total, so no spans is 0 %.
            assert item['params'] == f'100*last(//otlp.span.status.error)/({SPAN_TOTAL})', item['params']
            item['params'] = f'100*last(//otlp.span.status.error)/({SPAN_TOTAL}+(({SPAN_TOTAL})=0))'

        if item['type'] != 'TELEMETRY_QUERY':
            continue

        query_filter = item['query'].setdefault('filter', {'evaltype': 'AND', 'conditions': []})
        assert query_filter['evaltype'] == 'AND', item['key']
        query_filter['conditions'].append(
            {'column': 'ResourceAttributes', 'attribute_key': 'service.namespace', 'value': value}
        )

        # Raw columns group the result: "avg(Duration)" next to the raw "Duration" column returns one row per
        # duration, so the duration items reported the shortest span instead of the average.
        aggregated = {column.get('column') for column in item['query'].get('aggregated_columns', [])}
        if 'columns' in item['query']:
            item['query']['columns'] = [c for c in item['query']['columns'] if c['column'] not in aggregated]

        normalize_rows(item)

        if item['key'] == LOGS_MASTER:
            # No log records in the interval: an empty result, which the dependent items count as 0.
            item['preprocessing'] = [no_value_step('[]')] + item.get('preprocessing', [])

    template['macros'].append({
        'macro': NODATA_MACRO,
        'value': '10m',
        'description': 'Time without spans after which "No spans received" opens.'
    })

    if not namespace:
        template['macros'].append({
            'macro': NAMESPACE_MACRO,
            'description': 'Application of this host: the service.namespace resource attribute its services send '
                'in OTEL_RESOURCE_ATTRIBUTES. Required.'
        })

    template['macros'].sort(key=lambda macro: macro['macro'])

    seed = namespace or ''

    def renew_uuids(node):
        if isinstance(node, dict):
            for key, val in node.items():
                if key == 'uuid':
                    # Deterministic, but Zabbix only accepts version 4 UUIDs.
                    node[key] = uuid.UUID(bytes=uuid.uuid5(UUID_NAMESPACE, seed + val).bytes, version=4).hex
                else:
                    renew_uuids(val)
        elif isinstance(node, list):
            for val in node:
                renew_uuids(val)

    renew_uuids(template)
    data['zabbix_export']['triggers'] = [no_spans_trigger(name, app)]

    return data


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__.split('\n')[0])
    parser.add_argument('--check', action='store_true', help='fail if the committed template is out of date')
    parser.add_argument('--namespace', help='fixed service.namespace instead of the {$OTLP.NAMESPACE} macro')
    parser.add_argument('--output', type=Path, help='output file (default: templates/otel_application_otlp.json)')
    args = parser.parse_args()

    output = json.dumps(build(json.loads(SOURCE.read_text(encoding='utf-8')), args.namespace), indent=4,
        ensure_ascii=False) + '\n'
    target = args.output or (TARGET if not args.namespace else ROOT / f'templates/otel_{args.namespace}_otlp.json')

    if args.check:
        if not target.exists() or target.read_text(encoding='utf-8') != output:
            print(f'{target.relative_to(ROOT)} is out of date: run scripts/build-otlp-app-template.py', file=sys.stderr)
            return 1
        print('template up to date')
        return 0

    target.write_text(output, encoding='utf-8', newline='\n')
    print(f'written {target}')
    return 0


if __name__ == '__main__':
    sys.exit(main())
