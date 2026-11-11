// catalog: products and stock, in PostgreSQL.
'use strict';

const express = require('express');
const { Pool } = require('pg');
const { trace, SpanStatusCode } = require('@opentelemetry/api');
const faults = require('./faults');

const db = new Pool({ max: 10 });
const app = express();

app.use(express.json());
app.use(faults.down('catalog'));

const COLUMNS = 'p.id, p.name, p.category, p.price::float AS price, p.stock';

// The slow database: the query itself takes longer, as with a missing index or a lock.
const slow = () => faults.get().db_slow_ms / 1000;

app.get('/products', async (req, res) => {
	if (faults.get().n_plus_one) {
		// The N+1 defect: one query for the ids, then one more per product.
		const ids = await db.query('SELECT id FROM products ORDER BY id');
		const rows = [];

		for (const { id } of ids.rows) {
			rows.push((await db.query(`SELECT ${COLUMNS} FROM products p WHERE p.id = $1`, [id])).rows[0]);
		}

		return res.json(rows);
	}

	res.json((await db.query(`SELECT ${COLUMNS} FROM products p, pg_sleep($1) ORDER BY p.id`, [slow()])).rows);
});

app.get('/products/:id', async (req, res) => {
	const id = Number(req.params.id);
	const { rows } = await db.query(`SELECT ${COLUMNS} FROM products p, pg_sleep($2) WHERE p.id = $1`, [id, slow()]);

	if (rows.length === 0) {
		return res.status(404).json({ error: 'No such product' });
	}

	const product = rows[0];

	if (faults.get().poison_product && id === 13) {
		// A product whose data breaks the code: the offer it points to does not exist.
		product.offer_price = product.offer.price;
	}

	res.json(product);
});

// Prices an order and checks its stock. The stock is not taken, so that the simulated customers never empty the shop.
app.post('/reserve', async (req, res) => {
	const wanted = new Map(req.body.items.map((item) => [Number(item.id), Number(item.quantity)]));
	const { rows } = await db.query(
		`SELECT ${COLUMNS} FROM products p, pg_sleep($2) WHERE p.id = ANY($1::int[])`, [[...wanted.keys()], slow()]
	);

	if (faults.get().out_of_stock) {
		return res.status(409).json({ error: `Out of stock: ${rows[0]?.name ?? 'product'}` });
	}

	const items = rows.map((row) => ({ id: row.id, name: row.name, price: row.price, quantity: wanted.get(row.id) }));
	const total = items.reduce((sum, item) => sum + item.price * item.quantity, 0);

	res.json({ items, total: Math.round(total * 100) / 100 });
});

app.use(faults.failed(trace, SpanStatusCode));
app.listen(8000, () => console.log('catalog listening on 8000'));
