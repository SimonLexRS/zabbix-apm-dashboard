#!/usr/bin/env python3
"""Register and enable the APM modules through the Zabbix API. Safe to run again.

  export ZABBIX_URL=http://zabbix.example.com          # frontend URL (api_jsonrpc.php is appended)
  export ZABBIX_API_TOKEN=...                          # User settings -> API tokens (Super admin)
  python3 scripts/enable-modules.py

The API equivalent of Administration -> General -> Modules -> Scan directory + Enable. Copy the folders first
(scripts/install.sh).
"""

import json
import os
import sys
import urllib.error
import urllib.request

MODULES = {'apmoverview': 'ApmOverview', 'apmservicehealth': 'ApmServiceHealth', 'apmservicemap': 'ApmServiceMap'}


def main() -> int:
    url, token = os.environ.get('ZABBIX_URL'), os.environ.get('ZABBIX_API_TOKEN')

    if not url or not token:
        print('Set ZABBIX_URL and ZABBIX_API_TOKEN.', file=sys.stderr)
        return 2

    endpoint = url.rstrip('/') + '/api_jsonrpc.php'

    def call(method: str, params):
        body = json.dumps({'jsonrpc': '2.0', 'method': method, 'params': params, 'id': 1}).encode()
        headers = {'Content-Type': 'application/json-rpc', 'Authorization': 'Bearer ' + token}

        with urllib.request.urlopen(urllib.request.Request(endpoint, body, headers), timeout=60) as response:
            result = json.load(response)

        if 'error' in result:
            raise SystemExit(f'{method}: {result["error"].get("data") or result["error"].get("message")}')

        return result['result']

    try:
        existing = {m['id']: m for m in call('module.get', {'output': ['moduleid', 'id', 'status']})}

        for module_id, directory in MODULES.items():
            if module_id in existing:
                call('module.update', {'moduleid': existing[module_id]['moduleid'], 'status': 1})
            else:
                call('module.create', {'id': module_id, 'relative_path': 'modules/' + directory, 'status': 1})

            print(f'{directory}: enabled')
    except (urllib.error.URLError, OSError, ValueError) as error:
        raise SystemExit(f'Cannot reach the Zabbix API at {endpoint}: {error}')

    return 0


if __name__ == '__main__':
    sys.exit(main())
