"""shop: the API behind the store pages. Cart in Redis; products and orders are other services."""
import json
import logging
import uuid

import redis
import requests
from flask import Flask, jsonify, request
from opentelemetry import trace

import faults

CATALOG = 'http://catalog:8000'
CHECKOUT = 'http://checkout:8000'
CARDS = {'4': 'visa', '5': 'mastercard', '3': 'amex'}

app = Flask(__name__)
log = logging.getLogger('shop')
store = redis.Redis(host='redis', decode_responses=True)
http = requests.Session()


def forward(response: requests.Response):
	"""The answer of another service, as it came: the page shows the real status and message."""
	try:
		body = response.json()
	except ValueError:
		body = {'error': response.text[:200] or response.reason}

	return jsonify(body), response.status_code


@app.errorhandler(requests.RequestException)
def unreachable(error):
	log.error('Service call failed: %s', error)

	return jsonify(error=f'{type(error).__name__}: a service did not answer'), 502


@app.get('/products')
def products():
	return forward(http.get(f'{CATALOG}/products', timeout=15))


@app.get('/products/<int:product_id>')
def product(product_id: int):
	trace.get_current_span().set_attribute('app.product.id', product_id)

	return forward(http.get(f'{CATALOG}/products/{product_id}', timeout=15))


def cart_of(cart_id: str) -> dict:
	return {int(product_id): int(quantity) for product_id, quantity in store.hgetall(f'cart:{cart_id}').items()}


@app.get('/cart/<cart_id>')
def cart(cart_id: str):
	return jsonify(items=cart_of(cart_id))


@app.post('/cart/<cart_id>')
def cart_add(cart_id: str):
	body = request.get_json(force=True)
	product_id = int(body['product_id'])
	trace.get_current_span().set_attribute('app.product.id', product_id)

	store.hincrby(f'cart:{cart_id}', product_id, int(body.get('quantity', 1)))
	store.expire(f'cart:{cart_id}', 3600)

	return jsonify(items=cart_of(cart_id))


@app.delete('/cart/<cart_id>')
def cart_empty(cart_id: str):
	store.delete(f'cart:{cart_id}')

	return jsonify(items={})


@app.post('/checkout/<cart_id>')
def checkout(cart_id: str):
	body = request.get_json(force=True)
	items = cart_of(cart_id)
	card = str(body.get('card', ''))
	span = trace.get_current_span()
	span.set_attribute('app.cart.items', sum(items.values()))
	span.set_attribute('app.payment.card_type', CARDS.get(card[:1], 'unknown'))

	if not items:
		return jsonify(error='The cart is empty'), 400

	response = http.post(f'{CHECKOUT}/orders', timeout=20, json={
		'items': [{'id': product_id, 'quantity': quantity} for product_id, quantity in items.items()],
		'email': body.get('email') or f'guest-{uuid.uuid4().hex[:6]}@example.com',
		'card': card
	})

	if response.ok:
		store.delete(f'cart:{cart_id}')
	else:
		log.error('Checkout of cart %s failed: HTTP %s %s', cart_id, response.status_code, response.text[:200])

	return forward(response)


@app.get('/faults')
def faults_get():
	return jsonify(faults.get())


@app.put('/faults')
def faults_put():
	values = faults.save(request.get_json(force=True))
	log.warning('Fault switches changed: %s', json.dumps(values))

	return jsonify(values)


@app.delete('/faults')
def faults_reset():
	log.warning('Fault switches reset')

	return jsonify(faults.reset())
