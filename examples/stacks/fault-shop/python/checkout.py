"""checkout: prices the order (catalog), charges it (payment), stores it (PostgreSQL) and queues the e-mail (Redis)."""
import json
import logging
import os
import time

import psycopg2
import redis
import requests
from flask import Flask, jsonify, request
from opentelemetry import propagate, trace
from opentelemetry.trace import SpanKind, Status, StatusCode
from werkzeug.exceptions import HTTPException

import faults

VERSION = tuple(int(part) for part in os.environ.get('APP_VERSION', '1.0.0').split('.'))

app = Flask(__name__)
log = logging.getLogger('checkout')
tracer = trace.get_tracer('checkout')
queue = redis.Redis(host='redis')
http = requests.Session()


class OrderRejected(Exception):
	def __init__(self, status: int, message: str):
		super().__init__(message)
		self.status = status


@app.before_request
def down():
	if faults.is_down('checkout'):
		return jsonify(error='checkout is down (fault panel)'), 503


@app.errorhandler(OrderRejected)
def rejected(error: OrderRejected):
	return jsonify(error=str(error)), error.status


@app.errorhandler(requests.RequestException)
def unreachable(error):
	log.error('Service call failed: %s', error)

	return jsonify(error=f'{type(error).__name__}: a service did not answer'), 502


@app.errorhandler(Exception)
def failed(error):
	if isinstance(error, HTTPException):
		return error

	# Handled here to answer JSON; the exception still goes to the span, where Zabbix groups it by type and message.
	span = trace.get_current_span()
	span.record_exception(error)
	span.set_status(Status(StatusCode.ERROR, str(error)))
	log.error('Order failed: %s: %s', type(error).__name__, error)

	return jsonify(error=f'{type(error).__name__}: {error}'), 500


def call(service: str, path: str, body: dict) -> dict:
	response = http.post(f'http://{service}:8000{path}', json=body, timeout=12)

	if not response.ok:
		try:
			message = response.json().get('error', response.reason)
		except ValueError:
			message = response.reason

		# 4xx: the order is refused and it is nobody's failure. 5xx: a service failed.
		if response.status_code < 500:
			raise OrderRejected(response.status_code, message)

		raise RuntimeError(f'{service} answered HTTP {response.status_code}: {message}')

	return response.json()


def bulk_discount(order: dict) -> float:
	# The defect of release 1.1.0: the discount table has no row for three or more different products.
	rates = {1: 0.0, 2: 0.05}

	return order['total'] * rates[len(order['items'])]


@app.post('/orders')
def orders():
	body = request.get_json(force=True)
	span = trace.get_current_span()
	burn = faults.get()['cpu_burn_ms']

	if burn:
		with tracer.start_as_current_span('validate order'):
			until = time.perf_counter() + burn / 1000
			while time.perf_counter() < until:
				pass

	order = call('catalog', '/reserve', {'items': body['items']})

	if VERSION >= (1, 1, 0):
		order['total'] = round(order['total'] - bulk_discount(order), 2)

	span.set_attribute('app.order.total', order['total'])
	span.set_attribute('app.order.products', len(order['items']))

	call('payment', '/charge', {'amount': order['total'], 'card': body.get('card', '')})

	with psycopg2.connect(os.environ['DATABASE_URL']) as connection, connection.cursor() as cursor:
		cursor.execute(
			'INSERT INTO orders (email, items, total) VALUES (%s, %s, %s) RETURNING id',
			(body['email'], sum(item['quantity'] for item in order['items']), order['total'])
		)
		order_id = cursor.fetchone()[0]

	connection.close()

	with tracer.start_as_current_span('orders publish', kind=SpanKind.PRODUCER, attributes={
		'messaging.system': 'redis', 'messaging.destination.name': 'orders', 'messaging.operation.type': 'send'
	}):
		message = {'order_id': order_id, 'email': body['email'], 'total': order['total'], 'trace': {}}
		propagate.inject(message['trace'])
		queue.lpush('orders', json.dumps(message))

	log.info('Order %s placed: %s', order_id, order['total'])

	return jsonify(order_id=order_id, total=order['total'])
