#!/usr/bin/env python3
"""Sample business metrics (KPIs) over OTLP/HTTP, to try the business KPIs of Zabbix APM without an application.

  python3 scripts/kpi-demo.py                      # both applications, to the collector at http://127.0.0.1:4318
  python3 scripts/kpi-demo.py --app tienda-demo --endpoint http://<collector>:4318
  python3 scripts/kpi-demo.py --minutes 30         # stop after 30 minutes

Always running, in Docker, next to the Go example: examples/demo-apps (compose.yaml).

Two applications, each with its own service.namespace, as real ones would send them:

  tienda-demo (service tienda-api): shop.orders by shop.store and shop.channel, shop.revenue (USD) by shop.store,
      shop.cart.abandoned by shop.store, the histogram shop.order.amount (USD), and the availability of each store as
      gauges: shop.stock.available and shop.stock.capacity (units), shop.products.out_of_stock (products).
  hotel-demo (service reservas-api): hotel.reservations by hotel.name and hotel.room_type, hotel.cancellations and
      hotel.revenue (USD) by hotel.name, hotel.rooms.occupied (an up-down counter) by hotel.name, and the availability
      of each hotel as gauges: hotel.rooms.capacity, hotel.rooms.available and hotel.occupancy (%).

Counters are cumulative, like the OpenTelemetry SDKs send them by default. Each order and reservation is also a server
span (POST /checkout, POST /reservations), so both applications appear in APM with their service and endpoint. Every
minute the script prints the totals since it started, to compare them with Zabbix. Only the Python standard library is
needed.
"""

import argparse
import json
import os
import random
import signal
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
PRODUCTS, PRODUCT_STOCK = 20, 30                                       # per store: products and units of each


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


class Gauge:
    """Last measured value of one metric, by attribute set (an OpenTelemetry gauge)."""

    def __init__(self, name: str, unit: str, description: str):
        self.name, self.unit, self.description = name, unit, description
        self.values = {}

    def set(self, labels: dict, value: float) -> None:
        self.values[tuple(sorted(labels.items()))] = value

    def otlp(self, start_ns: int, now_ns: int) -> dict:
        points = [{'attributes': attrs(dict(key)), 'timeUnixNano': str(now_ns), 'asDouble': float(value)}
                  for key, value in self.values.items()]

        return {'name': self.name, 'unit': self.unit, 'description': self.description, 'gauge': {'dataPoints': points}}


class Store:
    namespace, service = 'tienda-demo', 'tienda-api'

    def __init__(self, rng: random.Random):
        self.rng = rng
        self.events = []
        self.orders = Series('shop.orders', '{order}', 'Orders placed')
        self.revenue = Series('shop.revenue', 'USD', 'Revenue of the orders placed')
        self.abandoned = Series('shop.cart.abandoned', '{cart}', 'Carts abandoned before paying')
        self.amount = Histogram('shop.order.amount', 'USD', 'Amount of each order', AMOUNT_BOUNDS)
        self.stock = {store: [PRODUCT_STOCK] * PRODUCTS for store in STORES}
        self.available = Gauge('shop.stock.available', '{unit}', 'Units in stock')
        self.capacity = Gauge('shop.stock.capacity', '{unit}', 'Units the store can hold')
        self.out_of_stock = Gauge('shop.products.out_of_stock', '{product}', 'Products without stock')

    def step(self, minutes: float) -> None:
        for store, rate in STORES.items():
            stock = self.stock[store]

            for channel, share in CHANNELS.items():
                for _ in range(poisson(self.rng, rate * share * minutes)):
                    # Each order takes 1 to 3 units of a product in stock.
                    in_stock = [i for i, units in enumerate(stock) if units > 0]

                    if in_stock:
                        product = self.rng.choice(in_stock)
                        stock[product] -= min(stock[product], self.rng.randint(1, 3))

                    amount = round(self.rng.uniform(8, 160), 2)
                    self.orders.add({'shop.store': store, 'shop.channel': channel}, 1)
                    self.revenue.add({'shop.store': store}, amount)
                    self.amount.record(amount)
                    self.events.append(('POST /checkout', {'shop.store': store, 'shop.channel': channel}))

            self.abandoned.add({'shop.store': store}, poisson(self.rng, rate * 0.3 * minutes))

            # A product without stock is restocked with a probability of 10 % per minute.
            for i, units in enumerate(stock):
                if units == 0 and self.rng.random() < 0.1 * minutes:
                    stock[i] = PRODUCT_STOCK

            label = {'shop.store': store}
            self.available.set(label, sum(stock))
            self.capacity.set(label, PRODUCTS * PRODUCT_STOCK)
            self.out_of_stock.set(label, sum(1 for units in stock if units == 0))

    def metrics(self, start_ns: int, now_ns: int) -> list:
        return [m.otlp(start_ns, now_ns) for m in (self.orders, self.revenue, self.abandoned, self.amount,
                                                   self.available, self.capacity, self.out_of_stock)]

    def report(self) -> str:
        orders, revenue = self.orders.totals('shop.store'), self.revenue.totals('shop.store')
        return ', '.join(f'{store}: {int(orders.get(store, 0))} orders / {revenue.get(store, 0):.2f} USD, '
                         f'stock {sum(self.stock[store])}/{PRODUCTS * PRODUCT_STOCK}' for store in STORES)


class Hotel:
    namespace, service = 'hotel-demo', 'reservas-api'

    def __init__(self, rng: random.Random):
        self.rng = rng
        self.events = []
        self.reservations = Series('hotel.reservations', '{reservation}', 'Reservations confirmed')
        self.cancellations = Series('hotel.cancellations', '{reservation}', 'Reservations cancelled')
        self.revenue = Series('hotel.revenue', 'USD', 'Revenue of the reservations confirmed')
        self.occupied = Series('hotel.rooms.occupied', '{room}', 'Rooms occupied now', monotonic=False)
        self.capacity = Gauge('hotel.rooms.capacity', '{room}', 'Rooms of the hotel')
        self.available = Gauge('hotel.rooms.available', '{room}', 'Rooms free now')
        self.occupancy = Gauge('hotel.occupancy', '%', 'Rooms occupied, % of the rooms of the hotel')

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
            checkouts = poisson(self.rng, occupied * 0.04 * minutes)
            self.occupied.add(label, max(-occupied, min(rooms - occupied, new - checkouts)))
            occupied = self.occupied.values[tuple(sorted(label.items()))]
            self.capacity.set(label, rooms)
            self.available.set(label, rooms - occupied)
            self.occupancy.set(label, round(occupied / rooms * 100, 1))

    def metrics(self, start_ns: int, now_ns: int) -> list:
        return [m.otlp(start_ns, now_ns) for m in (self.reservations, self.cancellations, self.revenue, self.occupied,
                                                   self.capacity, self.available, self.occupancy)]

    def report(self) -> str:
        reservations, occupied = self.reservations.totals('hotel.name'), self.occupied.totals('hotel.name')
        return ', '.join(f'{hotel}: {int(reservations.get(hotel, 0))} reservations, {int(occupied.get(hotel, 0))}/'
                         f'{rooms} rooms occupied' for hotel, (_, rooms) in HOTELS.items())


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


def stop(signum, frame) -> None:
    raise KeyboardInterrupt


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__.split('\n\n')[0])
    # The environment variables do the same as the options (examples/demo-apps/compose.yaml uses them).
    parser.add_argument('--app', choices=['all', 'tienda-demo', 'hotel-demo'],
                        default=os.environ.get('KPI_DEMO_APP', 'all'), help='application to send (KPI_DEMO_APP)')
    parser.add_argument('--endpoint', default=os.environ.get('OTLP_ENDPOINT', 'http://127.0.0.1:4318'),
                        help='OTLP/HTTP address of the collector (OTLP_ENDPOINT)')
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

    # docker stop sends SIGTERM: stop as with Ctrl+C.
    signal.signal(signal.SIGTERM, stop)

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
