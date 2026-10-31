#!/usr/bin/env python3
"""Check that the real user monitoring data of an application reaches ClickHouse. Read-only unless --send-sample is used.

  export CH_URL=http://127.0.0.1:8123 CH_USER=zabbix_ro CH_PASSWORD=...
  python3 scripts/test-rum.py opentelemetry-demo
  python3 scripts/test-rum.py opentelemetry-demo --send-sample http://<site>/otlp-http/v1/logs

--send-sample sends synthetic Core Web Vitals (rum.page = /rum-test, service rum-test) to an OTLP/HTTP logs URL, to test
that path without a browser. They appear on the RUM pages next to real data until the collector's TTL removes them.

Exit code: 0 when every required check passes, 1 otherwise.
"""

import argparse
import base64
import json
import os
import secrets
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

BROWSER = "ResourceAttributes['telemetry.sdk.language'] = 'webjs'"
SAMPLE = {
    'LCP': (1800.0, 'good'),
    'INP': (120.0, 'good'),
    'CLS': (0.05, 'good'),
    'FCP': (1200.0, 'good'),
    'TTFB': (400.0, 'good')
}


def clickhouse(sql: str, params: dict) -> list:
    query = {'query': sql + ' FORMAT JSON', 'database': os.environ.get('CH_DB', 'zabbix')}
    query.update({f'param_{key}': value for key, value in params.items()})
    request = urllib.request.Request(os.environ['CH_URL'].rstrip('/') + '/?' + urllib.parse.urlencode(query))

    if os.environ.get('CH_USER'):
        credentials = f'{os.environ["CH_USER"]}:{os.environ.get("CH_PASSWORD", "")}'
        request.add_header('Authorization', 'Basic ' + base64.b64encode(credentials.encode()).decode())

    try:
        with urllib.request.urlopen(request, timeout=60) as response:
            return json.load(response)['data']
    except (urllib.error.URLError, OSError, ValueError) as error:
        detail = error.read().decode(errors='replace')[:300] if isinstance(error, urllib.error.HTTPError) else error
        raise SystemExit(f'Cannot query ClickHouse: {detail}')


def send_sample(url: str, namespace: str) -> None:
    def attribute(key: str, value) -> dict:
        return {'key': key, 'value': {'doubleValue': value} if isinstance(value, float) else {'stringValue': value}}

    load_id = secrets.token_hex(8)
    records = [{
        'timeUnixNano': str(time.time_ns()),
        'severityText': 'INFO',
        'body': {'stringValue': 'web-vital ' + metric},
        'attributes': [
            attribute('rum.metric', metric), attribute('rum.value', value), attribute('rum.rating', rating),
            attribute('rum.page', '/rum-test'), attribute('rum.device', 'desktop'), attribute('rum.id', load_id)
        ]
    } for metric, (value, rating) in SAMPLE.items()]
    body = json.dumps({'resourceLogs': [{
        'resource': {'attributes': [attribute('service.name', 'rum-test'), attribute('service.namespace', namespace)]},
        'scopeLogs': [{'scope': {'name': 'rum'}, 'logRecords': records}]
    }]}).encode()

    try:
        request = urllib.request.Request(url, body, {'Content-Type': 'application/json'})
        with urllib.request.urlopen(request, timeout=30) as response:
            print(f'sent {len(records)} synthetic Web Vitals to {url}: HTTP {response.status}')
    except (urllib.error.URLError, OSError) as error:
        raise SystemExit(f'Cannot send the sample to {url}: {error}')


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__.split('\n')[0])
    parser.add_argument('namespace', help='service.namespace of the application')
    parser.add_argument('--minutes', type=int, default=15, help='period to check (default: 15)')
    parser.add_argument('--send-sample', metavar='URL', help='send synthetic Web Vitals to this OTLP/HTTP logs URL')
    args = parser.parse_args()

    if not os.environ.get('CH_URL'):
        parser.error('set CH_URL (and CH_USER and CH_PASSWORD)')

    params = {'ns': args.namespace, 'minutes': args.minutes}
    window = ("Timestamp > now() - toIntervalMinute({minutes:UInt32})"
        " AND ResourceAttributes['service.namespace'] = {ns:String}")
    failures = 0

    def report(ok: bool | None, text: str) -> None:
        nonlocal failures
        failures += ok is False
        print(('INFO' if ok is None else 'OK  ' if ok else 'FAIL'), text)

    row = clickhouse(
        "SELECT count() AS spans, countIf(SpanAttributes['session.id'] != '') AS with_session,"
        " uniqExactIf(SpanAttributes['session.id'], SpanAttributes['session.id'] != '') AS sessions,"
        " countIf(SpanName = 'documentLoad') AS loads, countIf(SpanName = 'resourceFetch') AS resources,"
        " countIf(SpanKind = 'Client') AS requests, countIf(ParentSpanId = '' AND SpanAttributes['event_type'] != '') AS actions"
        f" FROM otel_traces WHERE {window} AND {BROWSER}", params)[0]
    row = {key: int(value) for key, value in row.items()}

    report(row['spans'] > 0, f'browser spans (telemetry.sdk.language = webjs): {row["spans"]:,}')

    if row['spans'] == 0:
        print('---- no browser data: open the site in a browser, or see docs/rum.md#troubleshooting')
        return 1

    report(row['with_session'] == row['spans'],
        f'spans with session.id: {row["with_session"]:,} of {row["spans"]:,}, {row["sessions"]:,} sessions')
    report(row['loads'] > 0, f'page loads (documentLoad): {row["loads"]:,}')
    report(None if row['resources'] else False, f'resources (resourceFetch): {row["resources"]:,}')
    report(None, f'user actions: {row["actions"]:,} (0 is normal if nobody clicked)')

    # A browser request is linked when its trace also has spans of another service.
    link = clickhouse(
        "SELECT count() AS traces, countIf(backend > 0) AS linked FROM ("
        " SELECT TraceId, uniqExactIf(ServiceName, ResourceAttributes['telemetry.sdk.language'] != 'webjs') AS backend"
        f" FROM otel_traces WHERE {window} GROUP BY TraceId"
        f" HAVING countIf({BROWSER} AND SpanKind = 'Client') > 0)", params)[0]
    traces, linked = int(link['traces']), int(link['linked'])

    if traces:
        report(linked > 0, f'browser requests linked with the backend: {linked:,} of {traces:,} traces')
    else:
        report(None, 'browser requests: none in this period')

    if args.send_sample:
        send_sample(args.send_sample, args.namespace)

    vitals_sql = ("SELECT LogAttributes['rum.metric'] AS metric, count() AS records FROM otel_logs"
        " WHERE Timestamp > now() - toIntervalMinute({minutes:UInt32})"
        " AND ResourceAttributes['service.namespace'] = {ns:String}"
        " AND LogAttributes['rum.metric'] != '' GROUP BY metric ORDER BY metric")
    vitals = {}

    # The sample crosses the collector batches before it can be read.
    for _ in range(12 if args.send_sample else 1):
        vitals = {r['metric']: int(r['records']) for r in clickhouse(vitals_sql, params)}

        if not args.send_sample or set(SAMPLE) <= set(vitals):
            break

        time.sleep(5)

    if args.send_sample:
        report(set(SAMPLE) <= set(vitals), f'Web Vitals in ClickHouse: {vitals or "none"}')
    elif vitals:
        report(True, f'Web Vitals in ClickHouse: {vitals}')
    else:
        report(None, 'Web Vitals: none. They need examples/web-vitals.js, or test the path with --send-sample')

    print('---- ' + ('all required checks passed' if not failures else f'{failures} check(s) failed'))

    return 1 if failures else 0


if __name__ == '__main__':
    sys.exit(main())
