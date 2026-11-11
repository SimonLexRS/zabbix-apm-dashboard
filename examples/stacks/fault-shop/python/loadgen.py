"""loadgen: simulated customers. Each one looks at the list and a product, fills a cart and, often, buys."""
import os
import random
import threading
import time
import uuid

import requests

import faults

URL = os.environ.get('SHOP_URL', 'http://web') + '/api'
MAX_WAITING = 20
CARDS = ('4242424242424242', '5555555555554444', '378282246310005')


def visit(http: requests.Session) -> None:
	cart = uuid.uuid4().hex[:12]

	http.get(f'{URL}/products', timeout=30)
	http.get(f'{URL}/products/{random.randint(1, 16)}', timeout=30)

	for product_id in random.sample(range(1, 17), random.choice((1, 1, 2, 2, 3, 4))):
		http.post(f'{URL}/cart/{cart}', json={'product_id': product_id, 'quantity': random.randint(1, 2)}, timeout=30)

	if random.random() < 0.6:
		http.post(f'{URL}/checkout/{cart}', json={'card': random.choice(CARDS)}, timeout=40)


def customer() -> None:
	try:
		visit(requests.Session())
	except requests.RequestException:
		pass


while True:
	rate = faults.get()['load_rps']

	# A slow store keeps its customers waiting, up to MAX_WAITING of them; the next ones go elsewhere.
	if rate > 0 and threading.active_count() <= MAX_WAITING:
		threading.Thread(target=customer, daemon=True).start()

	time.sleep(1 / rate if rate > 0 else 1)
