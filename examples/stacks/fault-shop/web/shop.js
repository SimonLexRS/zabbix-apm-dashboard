// What the store pages share: the cart id, the API calls and the header.
// Telemetry first: this import ends when the browser SDK is registered, so that the first request is already traced.
import './otel.js';

export const cartId = localStorage.getItem('cart.id') ?? Math.random().toString(36).slice(2, 14);
localStorage.setItem('cart.id', cartId);

export const money = (value) => value.toLocaleString('en-US', { style: 'currency', currency: 'USD' });

export async function api(path, options = {}) {
	const response = await fetch('/api' + path, options.body === undefined ? options : {
		...options, headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(options.body)
	});
	const body = await response.json().catch(() => ({ error: response.statusText }));

	if (!response.ok) {
		throw new Error(`HTTP ${response.status}: ${body.error ?? response.statusText}`);
	}

	return body;
}

export function notice(message, kind = '') {
	const element = document.getElementById('notice');

	element.className = 'notice ' + kind;
	element.textContent = message;
}

export async function addToCart(productId) {
	// The "JavaScript error" switch of the fault panel: the click handler breaks before it calls the API.
	if ((await api('/faults')).js_error) {
		const recommendations = undefined;

		recommendations.refresh(productId);
	}

	const cart = await api('/cart/' + cartId, { method: 'POST', body: { product_id: productId, quantity: 1 } });

	showCartCount(cart.items);
	notice('Added to the cart.', 'ok');
}

export function showCartCount(items) {
	document.getElementById('cart-count').textContent = Object.values(items).reduce((sum, quantity) => sum + quantity, 0);
}

document.querySelector('header').innerHTML = `<nav>
	<a class="brand" href="/">Fault Shop</a>
	<a href="/">Products</a>
	<a href="/cart.html">Cart (<span id="cart-count">0</span>)</a>
	<a href="/faults.html">Fault panel</a>
</nav>`;

api('/cart/' + cartId).then((cart) => showCartCount(cart.items)).catch(() => {});
