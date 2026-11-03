#!/usr/bin/env python3
"""Check that an application is monitored end to end by "OpenTelemetry application by OTLP".

  export ZABBIX_URL=http://zabbix.example.com/zabbix ZABBIX_API_TOKEN=...   # or ZABBIX_USER and ZABBIX_PASSWORD
  export CH_URL=http://127.0.0.1:8123 CH_USER=reader CH_PASSWORD=...   # optional: compare with ClickHouse
  python3 scripts/verify-app.py opentelemetry-demo
  python3 scripts/verify-app.py tienda-demo --kpi     # also the business KPIs (template APM business metrics)

Checks: the host apm-<namespace> exists with the template and the right {$OTLP.NAMESPACE}; no item is unsupported;
the span items received values in the last minutes; and, with ClickHouse access, that the item values match what
ClickHouse holds for that namespace (not for all applications). With --kpi: the template "APM business metrics by
ClickHouse HTTP" is linked with the right {$APM.NAMESPACE}, its two queries answer, KPI items were discovered and,
with ClickHouse access, the "today" item of each metric matches ClickHouse. Exit code 1 if a check fails.
"""

import argparse
import base64
import json
import os
import re
import sys
import time
import urllib.parse
import urllib.request

TEMPLATE_NAME = 'OpenTelemetry application by OTLP'
NAMESPACE_MACRO = '{$OTLP.NAMESPACE}'
SPAN_ITEMS = ('otlp.span.status.ok', 'otlp.span.status.error', 'otlp.span.status.unset')
WINDOW_MIN = 10
HEARTBEAT = 3600
TOLERANCE = 0.2
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
KPI_TEMPLATE_NAME = 'APM business metrics by ClickHouse HTTP'
KPI_TEMPLATE_FILE = os.path.join(ROOT, 'templates', 'apm_business_metrics_clickhouse_http.json')
KPI_TOTALS_SQL = os.path.join(ROOT, 'templates', 'src', 'apm_business_totals.sql')
KPI_TODAY = re.compile(r'^apm\.kpi\.today\["(?P<service>[^"]*)","(?P<metric>[^"]*)"(?:,"(?P<key>[^"]*)","(?P<value>[^"]*)")?\]$')

failures = 0


def report(ok: bool, text: str) -> None:
    global failures
    failures += not ok
    print(('OK   ' if ok else 'FAIL ') + text)


def zabbix(url: str, token: str, method: str, params):
    body = json.dumps({'jsonrpc': '2.0', 'method': method, 'params': params, 'id': 1}).encode()
    headers = {'Content-Type': 'application/json-rpc'}

    if token:
        headers['Authorization'] = 'Bearer ' + token

    request = urllib.request.Request(url.rstrip('/') + '/api_jsonrpc.php', body, headers)
    with urllib.request.urlopen(request, timeout=60) as response:
        result = json.load(response)
    if 'error' in result:
        raise SystemExit(f'{method}: {result["error"].get("data") or result["error"].get("message")}')
    return result['result']


def clickhouse(sql: str, params: dict) -> list:
    query = {'query': sql + ' FORMAT JSON', 'database': os.environ.get('CH_DB', 'zabbix')}
    query.update({'param_' + k: v for k, v in params.items()})
    request = urllib.request.Request(os.environ['CH_URL'].rstrip('/') + '/?' + urllib.parse.urlencode(query))
    if os.environ.get('CH_USER'):
        credentials = f'{os.environ["CH_USER"]}:{os.environ.get("CH_PASSWORD", "")}'
        request.add_header('Authorization', 'Basic ' + base64.b64encode(credentials.encode()).decode())
    with urllib.request.urlopen(request, timeout=60) as response:
        return json.load(response)['data']


def macro_seconds(host_macros: list, name: str, default: int) -> int:
    """{$OTLP.<name>:traces} (or without context) from the host, in seconds; otherwise the template default."""
    values = {m['macro'].replace('"', ''): m.get('value', '') for m in host_macros}  # secret macros have no value
    value = values.get(f'{{$OTLP.{name}:traces}}') or values.get(f'{{$OTLP.{name}}}')
    if not value:
        return default
    unit = {'s': 1, 'm': 60, 'h': 3600}.get(value[-1])
    return int(value[:-1]) * unit if unit else int(value)


def close(a: float, b: float) -> bool:
    return abs(a - b) <= TOLERANCE * max(abs(a), abs(b), 1e-9)


def kpi_checks(host: dict, items: dict, now: int) -> None:
    """The KPI template is linked and set, its queries answer and its discovery found items."""
    report(any(t['host'] == KPI_TEMPLATE_NAME for t in host['parentTemplates']), f'template "{KPI_TEMPLATE_NAME}" is linked')
    macro = next((m.get('value') for m in host['macros'] if m['macro'] == '{$APM.NAMESPACE}'), None)
    report(macro == host['namespace'], f'{{$APM.NAMESPACE}} = {macro!r}')

    # The master items keep no history: their freshness is that of the items that depend on them.
    for key in ('apm.kpi.get', 'apm.kpi.totals.get'):
        master = items.get(key)

        if master is None:
            report(False, f'{key} exists (the template is older: run "zabbix-apm-app.py add <app> --kpi" again)')
            continue

        clocks = [int(i['lastclock']) for i in items.values() if i.get('master_itemid') == master['itemid']]
        age = now - max(clocks) if clocks and max(clocks) > 0 else None
        error = f', error: {master["error"]}' if master['state'] == '1' else ''
        report(master['state'] == '0' and age is not None and age < 600,
            f'{key} answers (newest dependent value {age if age is not None else "never"} s ago{error})')

    # A template update reaches the discovered items at the next discovery (when the data changes, or every 15 min).
    stale = [k for k, i in items.items() if k.startswith('apm.kpi.today[') and i['type'] != '18']
    report(not stale, f'"today" items read from ClickHouse ({len(stale)} still calculated: wait for the next '
        'discovery, up to 15 minutes after updating the template)' if stale else '"today" items read from ClickHouse')

    kpis = [k for k in items if k.startswith('apm.kpi.') and '[' in k]
    report(bool(kpis), f'{len(kpis)} KPI items discovered')


def kpi_compare(namespace: str, host: dict, items: dict) -> None:
    """The "today" item of each metric against the totals query of the template, run now on ClickHouse."""
    with open(KPI_TEMPLATE_FILE, encoding='utf-8') as f:
        macros = {m['macro']: m.get('value', '') for m in json.load(f)['zabbix_export']['templates'][0]['macros']}
    macros.update({m['macro']: m['value'] for m in host['macros'] if m.get('value') is not None})

    with open(KPI_TOTALS_SQL, encoding='utf-8') as f:
        sql = '\n'.join(line for line in f.read().splitlines()
            if not line.startswith(('--', 'FORMAT', 'SETTINGS')))

    rows = clickhouse(sql, {
        'db': macros.get('{$CH.DB}') or 'zabbix', 'ns': namespace, 'delay': macros['{$APM.KPI.DELAY}'],
        'tz': macros['{$APM.KPI.TIMEZONE}'], 'metric_matches': macros['{$APM.KPI.METRIC.MATCHES}'],
        'metric_not_matches': macros['{$APM.KPI.METRIC.NOT_MATCHES}'],
        'dim_not_matches': macros['{$APM.KPI.DIMENSION.NOT_MATCHES}'], 'limit': macros['{$APM.KPI.LIMIT}']})
    today = {(r['service'], r['metric'], r['dim_key'], r['dim_value']): float(r['today']) for r in rows}
    compared = 0

    for key, item in items.items():
        match = KPI_TODAY.match(key)

        row = match and (match['service'], match['metric'], match['key'] or '', match['value'] or '')

        if not match or item['type'] != '18' or item['lastclock'] == '0' or row not in today:
            continue

        compared += 1
        zabbix_value, ch_value = float(item['lastvalue']), today[row]
        name = match['metric'] + (f' {match["key"]}={match["value"]}' if match['key'] else '')
        # The item is up to two intervals older than this query: a small absolute difference is expected.
        report(close(zabbix_value, ch_value) or abs(zabbix_value - ch_value) <= 10,
            f'today of {name} ({match["service"]}): Zabbix {zabbix_value:,.2f}, ClickHouse {ch_value:,.2f}')

    report(compared > 0, f'{compared} "today" items compared with ClickHouse')


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__.split('\n')[0])
    parser.add_argument('namespace', help='service.namespace of the application')
    parser.add_argument('--kpi', action='store_true', help=f'also check the business KPIs ("{KPI_TEMPLATE_NAME}")')
    args = parser.parse_args()

    url, token = os.environ.get('ZABBIX_URL'), os.environ.get('ZABBIX_API_TOKEN')
    user, password = os.environ.get('ZABBIX_USER'), os.environ.get('ZABBIX_PASSWORD')
    if not url or not (token or (user and password)):
        parser.error('set ZABBIX_URL and ZABBIX_API_TOKEN (or ZABBIX_USER and ZABBIX_PASSWORD)')
    if not token:
        token = zabbix(url, '', 'user.login', {'username': user, 'password': password})

    api = lambda method, params: zabbix(url, token, method, params)
    host_name = 'apm-' + re.sub(r'[^0-9A-Za-z._ -]', '-', args.namespace)
    hosts = api('host.get', {'output': ['hostid', 'status'], 'filter': {'host': host_name},
        'selectParentTemplates': ['host'], 'selectMacros': ['macro', 'value']})

    report(bool(hosts), f'host {host_name} exists')
    if not hosts:
        return 1
    host = hosts[0]
    host['namespace'] = args.namespace
    report(host['status'] == '0', 'host is enabled')
    report(any(t['host'] == TEMPLATE_NAME for t in host['parentTemplates']), f'template "{TEMPLATE_NAME}" is linked')
    macro = next((m.get('value') for m in host['macros'] if m['macro'] == NAMESPACE_MACRO), None)
    report(macro == args.namespace, f'{NAMESPACE_MACRO} = {macro!r}')

    items = {i['key_']: i for i in api('item.get', {'hostids': host['hostid'],
        'output': ['itemid', 'key_', 'type', 'master_itemid', 'state', 'error', 'lastclock', 'lastvalue',
                   'value_type']})}
    unsupported = {k: i['error'] for k, i in items.items() if i['state'] == '1'}
    report(not unsupported, f'{len(items)} items, unsupported: {unsupported or "none"}')

    now = int(time.time())
    # The template discards unchanged values (heartbeat 1 h): a steady application only stores the changes.
    ages = [now - int(items[k]['lastclock']) for k in SPAN_ITEMS if k in items and items[k]['lastclock'] != '0']
    report(bool(ages) and min(ages) < HEARTBEAT + 300,
        f'span items receive values (newest {min(ages) if ages else "never"} s ago, heartbeat {HEARTBEAT} s)')

    # Each value covers the GRANULARITY seconds that end TIMESHIFT seconds before it was collected.
    granularity, shift, frequency = (macro_seconds(host['macros'], name, default)
        for name, default in (('GRANULARITY', 60), ('TIMESHIFT', 10), ('FREQUENCY', 60)))
    start, end = now - WINDOW_MIN * 60, now

    def history(key: str) -> dict:
        """{collection time: value} for every collection in the window. Unchanged values are discarded by the
        template, so the last stored value is carried forward to the collections that stored nothing."""
        item = items[key]
        rows = api('history.get', {'itemids': item['itemid'], 'history': int(item['value_type']),
            'time_from': start - HEARTBEAT, 'time_till': end, 'output': ['clock', 'value'], 'sortfield': 'clock'})
        if not rows:
            return {}
        phase = int(rows[-1]['clock']) % frequency
        series, last, i = {}, None, 0
        for slot in range(start - start % frequency + phase - frequency, end, frequency):
            collected = slot
            while i < len(rows) and int(rows[i]['clock']) < slot + frequency // 2:
                last, collected = float(rows[i]['value']), int(rows[i]['clock'])
                i += 1
            if last is not None and collected >= start:
                series[collected] = last
        return series

    if args.kpi:
        kpi_checks(host, items, now)

    if not os.environ.get('CH_URL'):
        print('---- set CH_URL (and CH_USER/CH_PASSWORD) to compare with ClickHouse')
        return 1 if failures else 0

    if args.kpi:
        kpi_compare(args.namespace, host, items)

    # The same seconds in ClickHouse, for this namespace and for all applications.
    rows = clickhouse(
        'SELECT toUnixTimestamp(Timestamp) AS second, StatusCode AS status,'
        ' countIf(ResourceAttributes[\'service.namespace\']={ns:String}) AS app,'
        ' count() AS total,'
        ' sumIf(Duration, ResourceAttributes[\'service.namespace\']={ns:String}) / 1e9 AS app_s'
        ' FROM otel_traces'
        ' WHERE Timestamp >= toDateTime({from:UInt32}) AND Timestamp < toDateTime({till:UInt32})'
        ' GROUP BY second, status',
        {'ns': args.namespace, 'from': start - granularity - shift - frequency, 'till': end})
    seconds = {(int(r['second']), r['status']): r for r in rows}
    mean = lambda values: sum(values) / len(values) if values else 0.0

    def window(collected: int, status: str) -> dict:
        """ClickHouse totals over the seconds that the value collected at that time covers."""
        found = [seconds.get((s, status), {}) for s in range(collected - shift - granularity, collected - shift)]
        return {k: sum(float(r.get(k, 0)) for r in found) for k in ('app', 'total', 'app_s')}

    for status, key in (('Ok', 'otlp.span.status.ok'), ('Error', 'otlp.span.status.error'),
            ('Unset', 'otlp.span.status.unset')):
        counts = history(key)
        if not counts:
            report(False, f'{status:<5} no values in the last {WINDOW_MIN} minutes')
            continue

        ch = [window(t, status) for t in counts]
        zabbix_avg = mean(list(counts.values()))
        app = mean([w['app'] for w in ch])
        total = mean([w['total'] for w in ch])
        report(close(zabbix_avg, app), f'{status:<5} spans per {granularity} s, {len(counts)} values: '
            f'Zabbix {zabbix_avg:,.1f}, ClickHouse {app:,.1f} for {args.namespace} ({total:,.1f} all applications)')

        if total > app * (1 + TOLERANCE) and app > 0:
            report(not close(zabbix_avg, total), f'{status:<5} filtered by namespace (not the total of all applications)')

        # Average duration: the mean of the per-value averages, as the item reports one average per collection.
        both = [(d, w['app_s'] / w['app']) for t, d in history(key + '.duration').items()
            for w in [window(t, status)] if w['app'] > 0]
        if both:
            zabbix_s, ch_s = mean([d for d, _ in both]), mean([c for _, c in both])
            report(close(zabbix_s, ch_s),
                f'{status:<5} average duration: Zabbix {zabbix_s:.4f} s, ClickHouse {ch_s:.4f} s')

    print('---- ' + ('all checks passed' if not failures else f'{failures} check(s) failed'))
    return 1 if failures else 0


if __name__ == '__main__':
    sys.exit(main())
