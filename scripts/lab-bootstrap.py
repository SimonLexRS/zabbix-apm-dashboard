#!/usr/bin/env python3
"""Configure the Zabbix of the lab (examples/lab) once it is up: modules, APM data source and the first application.

  cd examples/lab && cp .env.example .env && docker compose up -d --build
  python3 ../../scripts/lab-bootstrap.py

Steps, all through the Zabbix API (the same as the manual steps of docs/install-module.md):
  1. wait until the frontend answers (the first start creates the database: 2-3 minutes);
  2. register and enable the modules APM Overview, APM service health and APM service map;
  3. set Administration → Data source → APM to the ClickHouse of the lab;
  4. import the template and create the host apm-go-example (scripts/zabbix-apm-app.py add go-example).

Lab only: it signs in with the default Zabbix user of a new installation (Admin / zabbix) unless ZABBIX_USER and
ZABBIX_PASSWORD are set. Safe to run again.
"""

import json
import os
import subprocess
import sys
import time
import urllib.error
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
LAB = ROOT / 'examples/lab'
MODULES = {'apmoverview': 'ApmOverview', 'apmservicehealth': 'ApmServiceHealth', 'apmservicemap': 'ApmServiceMap'}
APM_GLOBAL_DB_ENABLED = 1


def env_file() -> dict:
    path = LAB / '.env'

    if not path.exists():
        raise SystemExit(f'{path} not found: cp examples/lab/.env.example examples/lab/.env')

    values = {}

    for line in path.read_text(encoding='utf-8').splitlines():
        if '=' in line and not line.lstrip().startswith('#'):
            key, value = line.split('=', 1)
            values[key.strip()] = value.strip()

    return values


class Api:

    def __init__(self, url: str):
        self.url = url
        self.auth = None

    def call(self, method: str, params, fail: bool = True):
        headers = {'Content-Type': 'application/json-rpc'}

        if self.auth:
            headers['Authorization'] = 'Bearer ' + self.auth

        body = json.dumps({'jsonrpc': '2.0', 'method': method, 'params': params, 'id': 1}).encode()

        with urllib.request.urlopen(urllib.request.Request(self.url, body, headers), timeout=60) as response:
            result = json.load(response)

        if 'error' in result:
            if fail:
                raise SystemExit(f'{method}: {result["error"].get("data") or result["error"].get("message")}')
            return None

        return result['result']


def main() -> int:
    lab = env_file()
    base = os.environ.get('ZABBIX_URL', f'http://127.0.0.1:{lab.get("ZABBIX_WEB_PORT", "8088")}')
    api = Api(base.rstrip('/') + '/api_jsonrpc.php')

    print(f'Waiting for Zabbix at {base} (the first start creates the database) ', end='', flush=True)

    for _ in range(60):
        try:
            if api.call('apiinfo.version', {}, fail=False):
                break
        except (urllib.error.URLError, OSError, ValueError):
            pass

        print('.', end='', flush=True)
        time.sleep(5)
    else:
        raise SystemExit('\nZabbix did not answer in 5 minutes: docker compose logs zabbix-server zabbix-web')

    print(f' Zabbix {api.call("apiinfo.version", {})}')

    user = os.environ.get('ZABBIX_USER', 'Admin')
    password = os.environ.get('ZABBIX_PASSWORD', 'zabbix')
    api.auth = api.call('user.login', {'username': user, 'password': password})

    # 2. Modules: the API equivalent of Administration → General → Modules → Scan directory + Enable.
    existing = {m['id']: m for m in api.call('module.get', {'output': ['moduleid', 'id', 'status']})}

    for module_id, directory in MODULES.items():
        if module_id in existing:
            api.call('module.update', {'moduleid': existing[module_id]['moduleid'], 'status': 1})
        else:
            api.call('module.create', {'id': module_id, 'relative_path': 'modules/' + directory, 'status': 1})
        print(f'module {directory}: enabled')

    # 3. APM data source, as seen from the Zabbix containers.
    api.call('settings.update', {'apm_global_db': {
        'status': APM_GLOBAL_DB_ENABLED,
        'url': 'http://clickhouse:8123',
        'db': 'zabbix',
        'username': lab['CLICKHOUSE_USER'],
        'password': lab['CLICKHOUSE_PASSWORD']
    }})
    print('APM data source: http://clickhouse:8123, database zabbix')

    # 4. Template and host of the Go example.
    env = dict(os.environ, ZABBIX_URL=base, ZABBIX_USER=user, ZABBIX_PASSWORD=password)
    env.pop('ZABBIX_API_TOKEN', None)
    sys.stdout.flush()
    subprocess.run([sys.executable, str(ROOT / 'scripts/zabbix-apm-app.py'), 'add', 'go-example', '--name', 'Go example'],
        env=env, check=True
    )

    print(f'''
Ready. Open {base} (user {user}) → APM → Overview → tab go-example.
In 3-4 minutes, check it end to end:

  ZABBIX_URL={base} ZABBIX_USER={user} ZABBIX_PASSWORD=*** \\
  CH_URL=http://127.0.0.1:{lab.get("CLICKHOUSE_HTTP_PORT", "18123")} CH_USER={lab["CLICKHOUSE_USER"]} CH_PASSWORD=*** \\
  python3 scripts/verify-app.py go-example
''')
    return 0


if __name__ == '__main__':
    sys.exit(main())
