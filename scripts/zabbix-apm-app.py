#!/usr/bin/env python3
"""Add an application to Zabbix APM monitoring: one host per service.namespace with "OpenTelemetry application by OTLP".

  export ZABBIX_URL=http://zabbix.example.com/zabbix   # frontend URL (api_jsonrpc.php is appended)
  export ZABBIX_API_TOKEN=...                           # User settings -> API tokens (Super admin, for the import)

  python3 scripts/zabbix-apm-app.py add flowspock --name "Flowspock"
  python3 scripts/zabbix-apm-app.py list

"add" is idempotent: it imports (or updates) the template, creates the host group and creates or updates the host
apm-<namespace> with {$OTLP.NAMESPACE}=<namespace>. Run it again after updating the template.
"""

import argparse
import json
import os
import re
import sys
import urllib.request
from pathlib import Path

TEMPLATE_FILE = Path(__file__).resolve().parent.parent / 'templates/otel_application_otlp.json'
TEMPLATE_NAME = 'OpenTelemetry application by OTLP'
HOST_GROUP = 'Applications/APM'
NAMESPACE_MACRO = '{$OTLP.NAMESPACE}'


class Api:

    def __init__(self, url: str, token: str):
        self.url = url.rstrip('/') + '/api_jsonrpc.php'
        self.token = token

    def call(self, method: str, params):
        body = json.dumps({'jsonrpc': '2.0', 'method': method, 'params': params, 'id': 1}).encode()
        request = urllib.request.Request(self.url, body, {
            'Content-Type': 'application/json-rpc',
            'Authorization': 'Bearer ' + self.token
        })

        with urllib.request.urlopen(request, timeout=60) as response:
            result = json.load(response)

        if 'error' in result:
            raise SystemExit(f'{method}: {result["error"].get("data") or result["error"].get("message")}')

        return result['result']


def import_template(api: Api) -> str:
    rule = {'createMissing': True, 'updateExisting': True}
    api.call('configuration.import', {
        'format': 'json',
        'source': TEMPLATE_FILE.read_text(encoding='utf-8'),
        'rules': {
            'template_groups': {'createMissing': True},
            'templates': rule,
            'items': rule | {'deleteMissing': True},
            'triggers': rule | {'deleteMissing': True},
            'templateDashboards': rule | {'deleteMissing': True},
            'templateLinkage': {'createMissing': True}
        }
    })

    return api.call('template.get', {'output': ['templateid'], 'filter': {'host': TEMPLATE_NAME}})[0]['templateid']


def host_group_id(api: Api) -> str:
    groups = api.call('hostgroup.get', {'output': ['groupid'], 'filter': {'name': HOST_GROUP}})

    return groups[0]['groupid'] if groups else api.call('hostgroup.create', {'name': HOST_GROUP})['groupids'][0]


def add(api: Api, namespace: str, name: str | None) -> None:
    # Host names allow letters, digits, spaces, dots, dashes and underscores; other characters become "-".
    host = 'apm-' + re.sub(r'[^0-9A-Za-z._ -]', '-', namespace)
    templateid = import_template(api)
    groupid = host_group_id(api)
    fields = {
        'name': name or f'APM {namespace}',
        'groups': [{'groupid': groupid}],
        'templates': [{'templateid': templateid}],
        'macros': [{
            'macro': NAMESPACE_MACRO,
            'value': namespace,
            'description': 'service.namespace of the application (zabbix-apm-app.py)'
        }]
    }

    existing = api.call('host.get', {'output': ['hostid'], 'filter': {'host': host}})

    if existing:
        # Keep the other templates and macros of the host, replace only ours.
        current = api.call('host.get', {
            'output': ['hostid'], 'hostids': existing[0]['hostid'],
            'selectParentTemplates': ['templateid'], 'selectMacros': ['macro', 'value', 'description', 'type'],
            'selectHostGroups': ['groupid']
        })[0]
        fields['templates'] += [t for t in current['parentTemplates'] if t['templateid'] != templateid]
        fields['macros'] += [m for m in current['macros'] if m['macro'] != NAMESPACE_MACRO]
        fields['groups'] += [g for g in current['hostgroups'] if g['groupid'] != groupid]
        api.call('host.update', {'hostid': current['hostid'], **fields})
        print(f'updated {host} ({fields["name"]}): {NAMESPACE_MACRO}={namespace}')
    else:
        api.call('host.create', {'host': host, **fields})
        print(f'created {host} ({fields["name"]}): {NAMESPACE_MACRO}={namespace}')


def list_apps(api: Api) -> None:
    templates = api.call('template.get', {'output': ['templateid'], 'filter': {'host': TEMPLATE_NAME}})

    if not templates:
        print(f'"{TEMPLATE_NAME}" is not imported yet: run "add <namespace>".')
        return

    hosts = api.call('host.get', {
        'output': ['host', 'name', 'status'], 'templateids': templates[0]['templateid'],
        'selectMacros': ['macro', 'value']
    })

    for host in sorted(hosts, key=lambda h: h['host']):
        namespace = next((m['value'] for m in host['macros'] if m['macro'] == NAMESPACE_MACRO), '(not set)')
        state = 'disabled' if host['status'] == '1' else 'enabled'
        print(f'{host["host"]:<32} {namespace:<24} {state:<9} {host["name"]}')


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__.split('\n')[0])
    commands = parser.add_subparsers(dest='command', required=True)
    add_parser = commands.add_parser('add', help='monitor an application (service.namespace)')
    add_parser.add_argument('namespace', help='service.namespace sent by the application')
    add_parser.add_argument('--name', help='visible host name (default: "APM <namespace>")')
    commands.add_parser('list', help='applications monitored with the template')
    args = parser.parse_args()

    url = os.environ.get('ZABBIX_URL')
    token = os.environ.get('ZABBIX_API_TOKEN')

    if not url or not token:
        parser.error('set ZABBIX_URL and ZABBIX_API_TOKEN')

    api = Api(url, token)

    if args.command == 'add':
        if not args.namespace.strip():
            parser.error('the namespace cannot be empty')
        add(api, args.namespace.strip(), args.name)
    else:
        list_apps(api)

    return 0


if __name__ == '__main__':
    sys.exit(main())
