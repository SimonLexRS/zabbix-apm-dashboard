#!/usr/bin/env python3
"""Add an application to Zabbix APM monitoring: one host per service.namespace with "OpenTelemetry application by OTLP".

  export ZABBIX_URL=http://zabbix.example.com/zabbix   # frontend URL (api_jsonrpc.php is appended)
  export ZABBIX_API_TOKEN=...                           # User settings -> API tokens (Super admin, for the import)
  # or, e.g. in the lab: export ZABBIX_USER=Admin ZABBIX_PASSWORD=...

  python3 scripts/zabbix-apm-app.py add flowspock --name "Flowspock"
  python3 scripts/zabbix-apm-app.py list

"add" is idempotent: it imports (or updates) the template, creates the host group and creates or updates the host
apm-<namespace> with {$OTLP.NAMESPACE}=<namespace>. Run it again after updating the template.

--kpi also links "APM business metrics by ClickHouse HTTP" (business metrics such as orders by store) and sets
{$APM.NAMESPACE}. Its ClickHouse macros come from CH_URL, CH_USER and CH_PASSWORD (a secret macro) when they are set;
otherwise they must exist as global macros ({$CH.URL}, {$CH.USER}, {$CH.PASSWORD}):

  export CH_URL=http://127.0.0.1:8123/ CH_USER=zabbix_ro CH_PASSWORD=...
  python3 scripts/zabbix-apm-app.py add flowspock --kpi

"kpi-dashboard" creates or updates the dashboard "KPIs <namespace>" to compare the values of each attribute (stores,
hotels...): today by value, its share and rank, the trend per hour, and the current levels (availability, stock). Run it
again when the application sends a new metric or attribute:

  python3 scripts/zabbix-apm-app.py kpi-dashboard flowspock
"""

import argparse
import json
import os
import re
import sys
import urllib.request
from pathlib import Path

TEMPLATES = Path(__file__).resolve().parent.parent / 'templates'
TEMPLATE_FILE = TEMPLATES / 'otel_application_otlp.json'
TEMPLATE_NAME = 'OpenTelemetry application by OTLP'
KPI_TEMPLATE_FILE = TEMPLATES / 'apm_business_metrics_clickhouse_http.json'
KPI_TEMPLATE_NAME = 'APM business metrics by ClickHouse HTTP'
KPI_NAMESPACE_MACRO = '{$APM.NAMESPACE}'
# Environment variable -> host macro of the business metrics template.
CH_MACROS = {'CH_URL': '{$CH.URL}', 'CH_USER': '{$CH.USER}', 'CH_PASSWORD': '{$CH.PASSWORD}'}
MACRO_SECRET = 1
HOST_GROUP = 'Applications/APM'
NAMESPACE_MACRO = '{$OTLP.NAMESPACE}'
TIMESHIFT_MACRO = '{$OTLP.TIMESHIFT}'


class Api:

    def __init__(self, url: str, token: str):
        self.url = url.rstrip('/') + '/api_jsonrpc.php'
        self.token = token

    def call(self, method: str, params):
        body = json.dumps({'jsonrpc': '2.0', 'method': method, 'params': params, 'id': 1}).encode()
        headers = {'Content-Type': 'application/json-rpc'}

        if self.token:
            headers['Authorization'] = 'Bearer ' + self.token

        request = urllib.request.Request(self.url, body, headers)

        with urllib.request.urlopen(request, timeout=60) as response:
            result = json.load(response)

        if 'error' in result:
            raise SystemExit(f'{method}: {result["error"].get("data") or result["error"].get("message")}')

        return result['result']


def import_template(api: Api, path: Path = TEMPLATE_FILE, name: str = TEMPLATE_NAME) -> str:
    rule = {'createMissing': True, 'updateExisting': True}
    api.call('configuration.import', {
        'format': 'json',
        'source': path.read_text(encoding='utf-8'),
        'rules': {
            'template_groups': {'createMissing': True},
            'templates': rule,
            'items': rule | {'deleteMissing': True},
            'triggers': rule | {'deleteMissing': True},
            'discoveryRules': rule | {'deleteMissing': True},
            'templateDashboards': rule | {'deleteMissing': True},
            'templateLinkage': {'createMissing': True}
        }
    })

    return api.call('template.get', {'output': ['templateid'], 'filter': {'host': name}})[0]['templateid']


def host_group_id(api: Api) -> str:
    groups = api.call('hostgroup.get', {'output': ['groupid'], 'filter': {'name': HOST_GROUP}})

    return groups[0]['groupid'] if groups else api.call('hostgroup.create', {'name': HOST_GROUP})['groupids'][0]


def add(api: Api, namespace: str, name: str | None, timeshift: str | None = None, kpi: bool = False) -> None:
    # Host names allow letters, digits, spaces, dots, dashes and underscores; other characters become "-".
    host = 'apm-' + re.sub(r'[^0-9A-Za-z._ -]', '-', namespace)
    templateids = [import_template(api)]

    if kpi:
        templateids.append(import_template(api, KPI_TEMPLATE_FILE, KPI_TEMPLATE_NAME))

    groupid = host_group_id(api)
    fields = {
        'name': name or f'APM {namespace}',
        'groups': [{'groupid': groupid}],
        'templates': [{'templateid': templateid} for templateid in templateids],
        'macros': [{
            'macro': NAMESPACE_MACRO,
            'value': namespace,
            'description': 'service.namespace of the application (zabbix-apm-app.py)'
        }]
    }

    if timeshift:
        fields['macros'].append({
            'macro': TIMESHIFT_MACRO,
            'value': timeshift,
            'description': 'Wait for late spans before reading each window (zabbix-apm-app.py)'
        })

    if kpi:
        fields['macros'].append({
            'macro': KPI_NAMESPACE_MACRO,
            'value': namespace,
            'description': 'service.namespace of the application, for its business metrics (zabbix-apm-app.py)'
        })

        for variable, macro in CH_MACROS.items():
            if os.environ.get(variable):
                fields['macros'].append({
                    'macro': macro,
                    'value': os.environ[variable],
                    'type': MACRO_SECRET if variable == 'CH_PASSWORD' else 0,
                    'description': f'ClickHouse for the business metrics, from {variable} (zabbix-apm-app.py)'
                })

    ours = {m['macro'] for m in fields['macros']}

    existing = api.call('host.get', {'output': ['hostid'], 'filter': {'host': host}})

    if existing:
        # Keep the other templates and macros of the host, replace only ours.
        current = api.call('host.get', {
            'output': ['hostid'], 'hostids': existing[0]['hostid'],
            'selectParentTemplates': ['templateid'], 'selectMacros': ['hostmacroid', 'macro', 'value', 'type'],
            'selectHostGroups': ['groupid']
        })[0]
        fields['templates'] += [t for t in current['parentTemplates'] if t['templateid'] not in templateids]
        # Other macros stay as they are: by ID, so that secret ones (whose value the API does not return) keep it.
        fields['macros'] += [{'hostmacroid': m['hostmacroid']} for m in current['macros'] if m['macro'] not in ours]
        fields['groups'] += [g for g in current['hostgroups'] if g['groupid'] != groupid]
        api.call('host.update', {'hostid': current['hostid'], **fields})
        print(f'updated {host} ({fields["name"]}): {NAMESPACE_MACRO}={namespace}')
    else:
        api.call('host.create', {'host': host, **fields})
        print(f'created {host} ({fields["name"]}): {NAMESPACE_MACRO}={namespace}')

    if kpi:
        missing = [macro for variable, macro in CH_MACROS.items() if not os.environ.get(variable)]
        print(f'business metrics: {KPI_TEMPLATE_NAME} linked, {KPI_NAMESPACE_MACRO}={namespace}')

        if missing:
            print(f'  {", ".join(missing)} not set on the host: they must exist as global macros (Administration ->'
                ' Macros), or run again with ' + ', '.join(v for v, m in CH_MACROS.items() if m in missing) + '.')


# Name of a KPI item: "KPI <metric> [<key>=<value>] (<service>): <period>" (template "APM business metrics").
KPI_NAME = re.compile(r'^KPI (?P<metric>\S+)(?: (?P<key>[^ =]+)=(?P<value>.*))? \((?P<service>[^()]+)\): (?P<period>.+)$')
AGGREGATE_LAST, AGGREGATE_SUM = 7, 5
TOPITEMS_VERTICAL = 1
ORDER_BY_VALUE, ORDER_BY_NAME = 3, 2


def field(name: str, value, kind: int = 1) -> dict:
    """A widget field: kind 0 = integer, 1 = string, 3 = host."""
    return {'type': kind, 'name': name, 'value': value}


def top_items(hostid: str, patterns: list, order: int, limit: int = 20) -> list:
    """Fields of a "Top items" widget: one row per item matching the patterns, ordered by value or by name."""
    fields = [field('hostids.0', hostid, 3), field('layout', TOPITEMS_VERTICAL, 0),
              field('item_ordering_order_by', order, 0), field('item_ordering_limit', limit, 0)]

    for i, pattern in enumerate(patterns):
        fields.append(field(f'columns.{i}.items.0', pattern))

    return fields


def kpi_widgets(host_name: str, hostid: str, metric: str, key: str, current: bool, y: int) -> list:
    """One row of the comparison dashboard: the values of one attribute of one metric (e.g. orders by store).
    Patterns of widgets match the visible host name."""
    base = f'KPI {metric} {key}=*'
    period = 'current' if current else 'today'
    title = f'{metric} by {key}'
    pie = [
        field('ds.0.dataset_type', 1, 0), field('ds.0.hosts.0', host_name), field('ds.0.items.0', f'{base}: {period}'),
        field('ds.0.aggregate_function', AGGREGATE_LAST, 0), field('ds.0.dataset_aggregation', 0, 0),
        field('legend_value', 1, 0), field('total_show', 1, 0)
    ]
    # The values per minute added up per hour: bars of each store or hotel along the last 7 days (current values:
    # the line of the last 24 hours).
    graph = [field('ds.0.dataset_type', 1, 0), field('ds.0.hosts.0', host_name)]

    if current:
        graph += [field('ds.0.items.0', f'{base}: current'), field('time_period.from', 'now-24h')]
    else:
        graph += [field('ds.0.items.0', f'{base}: per minute'), field('ds.0.aggregate_function', AGGREGATE_SUM, 0),
                  field('ds.0.aggregate_interval', '1h'), field('time_period.from', 'now-7d')]

    graph.append(field('time_period.to', 'now'))
    widgets = [
        {'type': 'piechart', 'name': f'{title}: {period}', 'x': 0, 'y': y, 'width': 16, 'height': 6, 'fields': pie},
        {'type': 'topitems', 'name': f'{title}: {period}, largest first', 'x': 16, 'y': y, 'width': 18, 'height': 6,
         'fields': top_items(hostid, [f'{base}: {period}'], ORDER_BY_VALUE)},
    ]

    if not current:
        widgets.append({'type': 'topitems', 'name': f'{title}: share of today, %', 'x': 34, 'y': y, 'width': 14,
                        'height': 6, 'fields': top_items(hostid, [f'{base}: share today'], ORDER_BY_VALUE)})

    widgets.append({'type': 'svggraph', 'name': f'{title}: ' + ('last 24 hours' if current else 'per hour, last 7 days'),
                    'x': 34 if current else 48, 'y': y, 'width': 38 if current else 24, 'height': 6, 'fields': graph})

    return widgets


def kpi_dashboard(api: Api, namespace: str) -> None:
    host = 'apm-' + re.sub(r'[^0-9A-Za-z._ -]', '-', namespace)
    hosts = api.call('host.get', {'output': ['hostid', 'name'], 'filter': {'host': host}})

    if not hosts:
        raise SystemExit(f'no host {host}: run "add {namespace} --kpi" first')

    hostid, host_name = hosts[0]['hostid'], hosts[0]['name']
    items = api.call('item.get', {'hostids': hostid, 'output': ['name'], 'selectTags': ['tag', 'value'],
                                  'search': {'name': 'KPI '}, 'startSearch': True})
    metrics = {}

    for item in items:
        match = KPI_NAME.match(item['name'])

        if not match:
            continue

        kind = next((t['value'] for t in item['tags'] if t['tag'] == 'kind'), '')
        entry = metrics.setdefault(match['metric'], {'current': kind in ('gauge', 'updown'), 'keys': set(),
                                                     'totals': set()})

        if match['key']:
            entry['keys'].add(match['key'])
        elif match['period'] in ('today', 'current', 'total (counter)'):
            entry['totals'].add(item['name'])

    if not metrics:
        raise SystemExit(f'{host} has no KPI items yet: link the template with "add {namespace} --kpi" and wait 2-3 '
                         'minutes')

    # First the totals of every metric, then one row per attribute: sales first, availability after.
    widgets = [{'type': 'topitems', 'name': 'Totals: today, current value and counter of each metric', 'x': 0, 'y': 0,
                'width': 72, 'height': 5, 'fields': top_items(hostid, sorted(n for m in metrics.values()
                                                                           for n in m['totals']), ORDER_BY_NAME, 50)}]
    y = 5

    for metric, entry in sorted(metrics.items(), key=lambda m: (m[1]['current'], m[0])):
        for key in sorted(entry['keys']):
            widgets += kpi_widgets(host_name, hostid, metric, key, entry['current'], y)
            y += 6

    name = f'KPIs {namespace}'
    dashboard = {'name': name, 'display_period': 60, 'auto_start': 0, 'pages': [{'widgets': widgets}]}
    existing = api.call('dashboard.get', {'output': ['dashboardid'], 'filter': {'name': name}})

    if existing:
        api.call('dashboard.update', {'dashboardid': existing[0]['dashboardid'], **dashboard})
        print(f'updated dashboard "{name}": {len(widgets)} widgets')
    else:
        api.call('dashboard.create', dashboard)
        print(f'created dashboard "{name}": {len(widgets)} widgets (private: share it in Dashboards -> Sharing)')


def list_apps(api: Api) -> None:
    templates = api.call('template.get', {'output': ['templateid'], 'filter': {'host': TEMPLATE_NAME}})

    if not templates:
        print(f'"{TEMPLATE_NAME}" is not imported yet: run "add <namespace>".')
        return

    kpi = api.call('template.get', {'output': ['templateid'], 'filter': {'host': KPI_TEMPLATE_NAME}})
    kpi_id = kpi[0]['templateid'] if kpi else None
    hosts = api.call('host.get', {
        'output': ['host', 'name', 'status'], 'templateids': templates[0]['templateid'],
        'selectMacros': ['macro', 'value'], 'selectParentTemplates': ['templateid']
    })

    print(f'{"HOST":<32} {"NAMESPACE":<24} {"STATE":<9} {"KPIS":<5} NAME')

    for host in sorted(hosts, key=lambda h: h['host']):
        namespace = next((m['value'] for m in host['macros'] if m['macro'] == NAMESPACE_MACRO), '(not set)')
        state = 'disabled' if host['status'] == '1' else 'enabled'
        kpis = 'yes' if any(t['templateid'] == kpi_id for t in host['parentTemplates']) else 'no'
        print(f'{host["host"]:<32} {namespace:<24} {state:<9} {kpis:<5} {host["name"]}')


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__.split('\n')[0])
    commands = parser.add_subparsers(dest='command', required=True)
    add_parser = commands.add_parser('add', help='monitor an application (service.namespace)')
    add_parser.add_argument('namespace', help='service.namespace sent by the application')
    add_parser.add_argument('--name', help='visible host name (default: "APM <namespace>")')
    add_parser.add_argument('--timeshift', help='{$OTLP.TIMESHIFT} of the host, e.g. 60s when spans cross two collectors')
    add_parser.add_argument('--kpi', action='store_true',
        help='also discover the business metrics of the application (orders, reservations...)')
    commands.add_parser('list', help='applications monitored with the template')
    dashboard_parser = commands.add_parser('kpi-dashboard', help='dashboard to compare the business KPIs of an application')
    dashboard_parser.add_argument('namespace', help='service.namespace of the application')
    args = parser.parse_args()

    url = os.environ.get('ZABBIX_URL')
    token = os.environ.get('ZABBIX_API_TOKEN')
    user, password = os.environ.get('ZABBIX_USER'), os.environ.get('ZABBIX_PASSWORD')

    if not url or not (token or (user and password)):
        parser.error('set ZABBIX_URL and ZABBIX_API_TOKEN (or ZABBIX_USER and ZABBIX_PASSWORD)')

    api = Api(url, token or '')

    if not token:
        api.token = api.call('user.login', {'username': user, 'password': password})

    if args.command == 'add':
        if not args.namespace.strip():
            parser.error('the namespace cannot be empty')
        add(api, args.namespace.strip(), args.name, args.timeshift, args.kpi)

        if args.kpi:
            print(f'  in 2-3 minutes: python3 scripts/zabbix-apm-app.py kpi-dashboard {args.namespace.strip()}')
    elif args.command == 'kpi-dashboard':
        kpi_dashboard(api, args.namespace.strip())
    else:
        list_apps(api)

    return 0


if __name__ == '__main__':
    sys.exit(main())
