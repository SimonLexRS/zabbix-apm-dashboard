// payment: charges a card through the bank, an outside service.
'use strict';

const express = require('express');
const { trace, SpanStatusCode } = require('@opentelemetry/api');
const faults = require('./faults');

const BANK = 'http://bank:8000/authorize';
const BANK_TIMEOUT_MS = 4000;
const CARDS = { 4: 'visa', 5: 'mastercard', 3: 'amex' };

class CardDeclinedError extends Error {
	constructor(message) {
		super(message);
		this.name = 'CardDeclinedError';
	}
}

class BankError extends Error {
	constructor(name, message) {
		super(message);
		this.name = name;
	}
}

const app = express();

app.use(express.json());
app.use(faults.down('payment'));

app.post('/charge', async (req, res) => {
	const card = String(req.body.card || '');

	trace.getActiveSpan()?.setAttributes({
		'app.payment.amount': Number(req.body.amount), 'app.payment.card_type': CARDS[card[0]] || 'unknown'
	});

	if (Math.random() * 100 < faults.get().payment_fail_pct) {
		throw new CardDeclinedError('Card declined by the issuer (fault panel)');
	}

	const response = await fetch(BANK, {
		method: 'POST',
		headers: { 'Content-Type': 'application/json' },
		body: JSON.stringify({ amount: req.body.amount }),
		signal: AbortSignal.timeout(BANK_TIMEOUT_MS)
	}).catch((error) => {
		// fetch reports both as a DOMException or a bare TypeError: name what happened.
		throw error.name === 'TimeoutError'
			? new BankError('BankTimeoutError', `The bank did not answer in ${BANK_TIMEOUT_MS / 1000} s`)
			: new BankError('BankUnreachableError', `The bank is not reachable: ${error.cause?.message ?? error.message}`);
	});

	if (!response.ok) {
		throw new Error(`The bank answered HTTP ${response.status}`);
	}

	res.json({ charged: req.body.amount });
});

app.use(faults.failed(trace, SpanStatusCode));
app.listen(8000, () => console.log('payment listening on 8000'));
