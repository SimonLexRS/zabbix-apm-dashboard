#!/usr/bin/env python3
"""Check that an application is monitored end to end by "OpenTelemetry application by OTLP".

  export ZABBIX_URL=http://zabbix.example.com/zabbix ZABBIX_API_TOKEN=...
  export CH_URL=http://127.0.0.1:8123 CH_USER=reader CH_PASSWORD=...   # optional: compare with ClickHouse
  python3 scripts/verify-app.py opentelemetry-demo

Checks: the host apm-<namespace> exists with the template and the right {$OTLP.NAMESPACE}; no item is unsupported;
the span items received values in the last minutes; and, with ClickHouse access, that the item values match what
ClickHouse holds for that namespace (not for all applications). Exit code 1 if a check fails.
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

failures = 0


def report(ok: bool, text: str) -> None:
    global failures
    failures += not ok
    print(('OK   ' if ok else 'FAIL ') + text)


def zabbix(url: str, token: str, method: str, params):
    body = json.dumps({'jsonrpc': '2.0', 'method': method, 'params': params, 'id': 1}).encode()
    request = urllib.request.Request(url.rstrip('/') + '/api_jsonrpc.php', body,
        {'Content-Type': 'application/json-rpc', 'Authorization': 'Bearer ' + token})
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


def close(a: float, b: float) -> bool:
    return abs(a - b) <= TOLERANCE * max(abs(a), abs(b), 1e-9)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__.split('\n')[0])
    parser.add_argument('namespace', help='service.namespace of the application')
    args = parser.parse_args()

    url, token = os.environ.get('ZABBIX_URL'), os.environ.get('ZABBIX_API_TOKEN')
    if not url or not token:
        parser.error('set ZABBIX_URL and ZABBIX_API_TOKEN')

    api = lambda method, params: zabbix(url, token, method, params)
    host_name = 'apm-' + re.sub(r'[^0-9A-Za-z._ -]', '-', args.namespace)
    hosts = api('host.get', {'output': ['hostid', 'status'], 'filter': {'host': host_name},
        'selectParentTemplates': ['host'], 'selectMacros': ['macro', 'value']})

    report(bool(hosts), f'host {host_name} exists')
    if not hosts:
        return 1
    host = hosts[0]
    report(host['status'] == '0', 'host is enabled')
    report(any(t['host'] == TEMPLATE_NAME for t in host['parentTemplates']), f'template "{TEMPLATE_NAME}" is linked')
    macro = next((m['value'] for m in host['macros'] if m['macro'] == NAMESPACE_MACRO), None)
    report(macro == args.namespace, f'{NAMESPACE_MACRO} = {macro!r}')

    items = {i['key_']: i for i in api('item.get', {'hostids': host['hostid'],
        'output': ['itemid', 'key_', 'state', 'error', 'lastclock', 'value_type']})}
    unsupported = {k: i['error'] for k, i in items.items() if i['state'] == '1'}
    report(not unsupported, f'{len(items)} items, unsupported: {unsupported or "none"}')

    now = int(time.time())
    # The template discards unchanged values (heartbeat 1 h): a steady application only stores the changes.
    ages = [now - int(items[k]['lastclock']) for k in SPAN_ITEMS if k in items and items[k]['lastclock'] != '0']
    report(bool(ages) and min(ages) < HEARTBEAT + 300,
        f'span items receive values (newest {min(ages) if ages else "never"} s ago, heartbeat {HEARTBEAT} s)')

    # Minutes compared: the last WINDOW_MIN complete minutes, by the minute each Zabbix value measures.
    end = now // 60 * 60
    start = end - WINDOW_MIN * 60

    def history(key: str) -> dict:
        """{measured minute: value}. A value collected at minute T measures minute T-1 (granularity 1m, time shift
        10s). Unchanged values are discarded by the template, so the last stored value is carried forward."""
        item = items[key]
        rows = api('history.get', {'itemids': item['itemid'], 'history': int(item['value_type']),
            'time_from': start - HEARTBEAT, 'time_till': end, 'output': ['clock', 'value'], 'sortfield': 'clock'})
        series, last, i = {}, None, 0
        for minute in range(start, end, 60):
            while i < len(rows) and int(rows[i]['clock']) < minute + 60:
                last = float(rows[i]['value'])
                i += 1
            if last is not None:
                series[minute - 60] = last
        return series

    if not os.environ.get('CH_URL'):
        print('---- set CH_URL (and CH_USER/CH_PASSWORD) to compare with ClickHouse')
        return 1 if failures else 0

    # The same minutes in ClickHouse, for this namespace and for all applications.
    rows = clickhouse(
        'SELECT toUnixTimestamp(toStartOfMinute(Timestamp)) AS minute, StatusCode AS status,'
        ' countIf(ResourceAttributes[\'service.namespace\']={ns:String}) AS app,'
        ' count() AS total,'
        ' avgIf(Duration, ResourceAttributes[\'service.namespace\']={ns:String}) / 1e9 AS app_avg_s'
        ' FROM otel_traces'
        ' WHERE Timestamp >= toDateTime({from:UInt32}) AND Timestamp < toDateTime({till:UInt32})'
        ' GROUP BY minute, status',
        {'ns': args.namespace, 'from': start - 60, 'till': end - 60})
    clickhouse_minutes = {(int(r['minute']), r['status']): r for r in rows}
    mean = lambda values: sum(values) / len(values) if values else 0.0

    for status, key in (('Ok', 'otlp.span.status.ok'), ('Error', 'otlp.span.status.error'),
            ('Unset', 'otlp.span.status.unset')):
        counts = history(key)
        minutes = sorted(counts)
        if not minutes:
            report(False, f'{status:<5} no values in the last {WINDOW_MIN} minutes')
            continue

        ch = [clickhouse_minutes.get((m, status), {}) for m in minutes]
        zabbix_avg = mean([counts[m] for m in minutes])
        app = mean([float(r.get('app', 0)) for r in ch])
        total = mean([float(r.get('total', 0)) for r in ch])
        report(close(zabbix_avg, app), f'{status:<5} spans/min over {len(minutes)} min: Zabbix {zabbix_avg:,.1f}, '
            f'ClickHouse {app:,.1f} for {args.namespace} ({total:,.1f} all applications)')

        if total > app * (1 + TOLERANCE) and app > 0:
            report(not close(zabbix_avg, total), f'{status:<5} filtered by namespace (not the total of all applications)')

        # Average duration: the mean of the per-minute averages, as the item reports one average per minute.
        durations = history(key + '.duration')
        both = [(durations[m], float(r['app_avg_s'])) for m, r in zip(minutes, ch)
            if m in durations and float(r.get('app', 0)) > 0]
        if both:
            zabbix_s, ch_s = mean([d for d, _ in both]), mean([c for _, c in both])
            report(close(zabbix_s, ch_s),
                f'{status:<5} average duration: Zabbix {zabbix_s:.4f} s, ClickHouse {ch_s:.4f} s')

    print('---- ' + ('all checks passed' if not failures else f'{failures} check(s) failed'))
    return 1 if failures else 0


if __name__ == '__main__':
    sys.exit(main())
