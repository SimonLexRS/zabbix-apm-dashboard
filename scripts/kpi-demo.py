#!/usr/bin/env python3
"""Sample business metrics (KPIs) over OTLP/HTTP, to try the business KPIs of Zabbix APM without an application.

  python3 scripts/kpi-demo.py                      # both applications, to the collector at http://127.0.0.1:4318
  python3 scripts/kpi-demo.py --app tienda-demo --endpoint http://<collector>:4318
  python3 scripts/kpi-demo.py --minutes 30         # stop after 30 minutes

Two applications, each with its own service.namespace, as real ones would send them:

  tienda-demo (service tienda-api): shop.orders by shop.store and shop.channel, shop.revenue (USD) by shop.store,
      shop.cart.abandoned by shop.store, and the histogram shop.order.amount (USD).
  hotel-demo (service reservas-api): hotel.reservations by hotel.name and hotel.room_type, hotel.cancellations and
      hotel.revenue (USD) by hotel.name, and hotel.rooms.occupied (an up-down counter) by hotel.name.

Counters are cumulative, like the OpenTelemetry SDKs send them by default. Each order and reservation is also a server
span (POST /checkout, POST /reservations), so both applications appear in APM with their service and endpoint. Every
minute the script prints the totals since it started, to compare them with Zabbix. Only the Python standard library is
needed.
"""

import argparse
import json
import os
import random
import sys
import time
import urllib.error
import urllib.request
import uuid

STORES = {'Centro': 6.0, 'Norte': 4.0, 'Sur': 2.0}                    # orders per minute
CHANNELS = {'web': 0.6, 'app': 0.4}
HOTELS = {'Hotel Playa': (3.0, 120), 'Hotel Montaña': (2.0, 60), 'Hotel Ciudad': (1.0, 40)}  # per minute, rooms
ROOM_TYPES = {'standard': 0.75, 'suite': 0.25}
AMOUNT_BOUNDS = [10, 25, 50, 100, 250]


def poisson(rng: random.Random, mean: float) -> int:
    """Number of events in an interval with the given mean (Knuth; the means here are small)."""
    limit, k, p = pow(2.718281828459045, -mean), 0, 1.0

    while True:
        p *= rng.random()

        if p <= limit:
            return k

        k += 1


def attrs(values: dict) -> list:
    return [{'key': key, 'value': {'stringValue': value}} for key, value in values.items()]


class Series:
    """Cumulative points of one metric, by attribute set."""

    def __init__(self, name: str, unit: str, description: str, monotonic: bool = True):
        self.name, self.unit, self.description, self.monotonic = name, unit, description, monotonic
        self.values = {}

    def add(self, labels: dict, value: float) -> None:
        key = tuple(sorted(labels.items()))
        self.values[key] = self.values.get(key, 0) + value

    def otlp(self, start_ns: int, now_ns: int) -> dict:
        points = [{
            'attributes': attrs(dict(key)), 'startTimeUnixNano': str(start_ns), 'timeUnixNano': str(now_ns),
            'asDouble': float(value)
        } for key, value in self.values.items()]

        return {'name': self.name, 'unit': self.unit, 'description': self.description,
                'sum': {'aggregationTemporality': 2, 'isMonotonic': self.monotonic, 'dataPoints': points}}

    def totals(self, label: str) -> dict:
        result = {}

        for key, value in self.values.items():
            name = dict(key).get(label, '')
            result[name] = result.get(name, 0) + value

        return result


class Histogram:
    """Cumulative histogram of one metric, without attributes."""

    def __init__(self, name: str, unit: str, description: str, bounds: list):
        self.name, self.unit, self.description, self.bounds = name, unit, description, bounds
        self.count, self.sum, self.buckets = 0, 0.0, [0] * (len(bounds) + 1)
        self.min, self.max = None, None

    def record(self, value: float) -> None:
        self.count += 1
        self.sum += value
        self.buckets[next((i for i, bound in enumerate(self.bounds) if value <= bound), len(self.bounds))] += 1
        self.min = value if self.min is None else min(self.min, value)
        self.max = value if self.max is None else max(self.max, value)

    def otlp(self, start_ns: int, now_ns: int) -> dict:
        point = {'attributes': [], 'startTimeUnixNano': str(start_ns), 'timeUnixNano': str(now_ns),
                 'count': str(self.count), 'sum': self.sum, 'bucketCounts': [str(b) for b in self.buckets],
                 'explicitBounds': self.bounds}

        if self.min is not None:
            point.update(min=self.min, max=self.max)

        return {'name': self.name, 'unit': self.unit, 'description': self.description,
                'histogram': {'aggregationTemporality': 2, 'dataPoints': [point]}}


class Store:
    namespace, service = 'tienda-demo', 'tienda-api'

    def __init__(self, rng: random.Random):
        self.rng = rng
        self.events = []
        self.orders = Series('shop.orders', '{order}', 'Orders placed')
        self.revenue = Series('shop.revenue', 'USD', 'Revenue of the orders placed')
        self.abandoned = Series('shop.cart.abandoned', '{cart}', 'Carts abandoned before paying')
        self.amount = Histogram('shop.order.amount', 'USD', 'Amount of each order', AMOUNT_BOUNDS)

    def step(self, minutes: float) -> None:
        for store, rate in STORES.items():
            for channel, share in CHANNELS.items():
                for _ in range(poisson(self.rng, rate * share * minutes)):
                    amount = round(self.rng.uniform(8, 160), 2)
                    self.orders.add({'shop.store': store, 'shop.channel': channel}, 1)
                    self.revenue.add({'shop.store': store}, amount)
                    self.amount.record(amount)
                    self.events.append(('POST /checkout', {'shop.store': store, 'shop.channel': channel}))

            self.abandoned.add({'shop.store': store}, poisson(self.rng, rate * 0.3 * minutes))

    def metrics(self, start_ns: int, now_ns: int) -> list:
        return [m.otlp(start_ns, now_ns) for m in (self.orders, self.revenue, self.abandoned, self.amount)]

    def report(self) -> str:
        orders, revenue = self.orders.totals('shop.store'), self.revenue.totals('shop.store')
        return ', '.join(f'{store}: {int(orders.get(store, 0))} orders / {revenue.get(store, 0):.2f} USD'
                         for store in STORES)


class Hotel:
    namespace, service = 'hotel-demo', 'reservas-api'

    def __init__(self, rng: random.Random):
        self.rng = rng
        self.events = []
        self.reservations = Series('hotel.reservations', '{reservation}', 'Reservations confirmed')
        self.cancellations = Series('hotel.cancellations', '{reservation}', 'Reservations cancelled')
        self.revenue = Series('hotel.revenue', 'USD', 'Revenue of the reservations confirmed')
        self.occupied = Series('hotel.rooms.occupied', '{room}', 'Rooms occupied now', monotonic=False)

        for hotel in HOTELS:
            self.occupied.add({'hotel.name': hotel}, 0)

    def step(self, minutes: float) -> None:
        for hotel, (rate, rooms) in HOTELS.items():
            label = {'hotel.name': hotel}
            new = 0

            for room_type, share in ROOM_TYPES.items():
                count = poisson(self.rng, rate * share * minutes)
                new += count
                self.reservations.add({'hotel.name': hotel, 'hotel.room_type': room_type}, count)

                for _ in range(count):
                    self.revenue.add(label, round(self.rng.uniform(80, 180) * (2.5 if room_type == 'suite' else 1), 2))
                    self.events.append(('POST /reservations', {'hotel.name': hotel, 'hotel.room_type': room_type}))

            self.cancellations.add(label, poisson(self.rng, rate * 0.1 * minutes))
            occupied = self.occupied.values[tuple(sorted(label.items()))]
            checkouts = poisson(self.rng, occupied * 0.02 * minutes)
            self.occupied.add(label, max(-occupied, min(rooms - occupied, new - checkouts)))

    def metrics(self, start_ns: int, now_ns: int) -> list:
        return [m.otlp(start_ns, now_ns) for m in (self.reservations, self.cancellations, self.revenue, self.occupied)]

    def report(self) -> str:
        reservations, occupied = self.reservations.totals('hotel.name'), self.occupied.totals('hotel.name')
        return ', '.join(f'{hotel}: {int(reservations.get(hotel, 0))} reservations, {int(occupied.get(hotel, 0))} rooms'
                         for hotel in HOTELS)


def resource(app, instance: str) -> dict:
    return {'attributes': attrs({'service.name': app.service, 'service.namespace': app.namespace,
                                 'service.instance.id': instance, 'deployment.environment.name': 'demo',
                                 'telemetry.sdk.name': 'kpi-demo'})}


def metrics_payload(app, instance: str, start_ns: int, now_ns: int) -> dict:
    return {'resourceMetrics': [{
        'resource': resource(app, instance),
        'scopeMetrics': [{'scope': {'name': 'kpi-demo'}, 'metrics': app.metrics(start_ns, now_ns)}]
    }]}


def traces_payload(app, instance: str, rng: random.Random, interval: float, now_ns: int) -> dict | None:
    """One server span per event of the last interval, spread over it."""
    events, app.events = app.events, []
    spans = []

    for name, labels in events:
        end = now_ns - int(rng.uniform(0, interval) * 1e9)
        method, route = name.split(' ', 1)
        spans.append({
            'traceId': os.urandom(16).hex(), 'spanId': os.urandom(8).hex(), 'name': name, 'kind': 2,
            'startTimeUnixNano': str(end - int(rng.uniform(0.04, 0.25) * 1e9)), 'endTimeUnixNano': str(end),
            'attributes': attrs({'http.request.method': method, 'http.route': route} | labels)
                + [{'key': 'http.response.status_code', 'value': {'intValue': '200'}}],
            'status': {'code': 1}
        })

    if not spans:
        return None

    return {'resourceSpans': [{'resource': resource(app, instance),
                               'scopeSpans': [{'scope': {'name': 'kpi-demo'}, 'spans': spans}]}]}


def send(endpoint: str, body: dict) -> None:
    request = urllib.request.Request(endpoint, json.dumps(body).encode(), {'Content-Type': 'application/json'})

    with urllib.request.urlopen(request, timeout=10) as response:
        response.read()


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__.split('\n\n')[0])
    parser.add_argument('--app', choices=['all', 'tienda-demo', 'hotel-demo'], default='all')
    parser.add_argument('--endpoint', default='http://127.0.0.1:4318', help='OTLP/HTTP address of the collector')
    parser.add_argument('--interval', type=float, default=15, help='seconds between exports (default 15)')
    parser.add_argument('--minutes', type=float, default=0, help='stop after this many minutes (default: never)')
    parser.add_argument('--seed', type=int, default=None, help='random seed, for repeatable runs')
    args = parser.parse_args()

    base = args.endpoint.rstrip('/').removesuffix('/v1/metrics')
    rng = random.Random(args.seed)
    apps = [cls(rng) for cls in (Store, Hotel) if args.app in ('all', cls.namespace)]
    instance = str(uuid.uuid4())
    # Durations use the monotonic clock: a clock correction (NTP) must not add or lose events.
    start, start_ns = time.monotonic(), time.time_ns()
    last, last_report = start, -60.0

    print(f'Sending {", ".join(a.namespace for a in apps)} to {base} every {args.interval:g} s. Ctrl+C stops.',
          flush=True)

    try:
        while args.minutes <= 0 or time.monotonic() - start < args.minutes * 60:
            now = time.monotonic()

            for app in apps:
                app.step((now - last) / 60)

            last = now

            for app in apps:
                try:
                    send(base + '/v1/metrics', metrics_payload(app, instance, start_ns, time.time_ns()))
                    traces = traces_payload(app, instance, rng, args.interval, time.time_ns())

                    if traces:
                        send(base + '/v1/traces', traces)
                except (urllib.error.URLError, OSError) as e:
                    print(f'{app.namespace}: send failed: {e}', file=sys.stderr)

            if now - last_report >= 60:
                last_report = now
                stamp = time.strftime('%H:%M:%S')

                for app in apps:
                    print(f'{stamp} {app.namespace} since start: {app.report()}', flush=True)

            time.sleep(max(0.0, args.interval - (time.monotonic() - now)))
    except KeyboardInterrupt:
        pass

    return 0


if __name__ == '__main__':
    sys.exit(main())
