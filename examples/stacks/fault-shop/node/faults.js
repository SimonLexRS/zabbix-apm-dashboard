// The switches of the fault panel: a JSON file on a volume shared by every service (written by "shop").
'use strict';

const fs = require('fs');

const PATH = process.env.FAULTS_FILE || '/faults/faults.json';
const DEFAULTS = {
	db_slow_ms: 0, n_plus_one: false, poison_product: false, out_of_stock: false,
	payment_fail_pct: 0, bank_slow_ms: 0, down: []
};

let cache = { mtime: null, values: DEFAULTS };

function get() {
	try {
		const mtime = fs.statSync(PATH).mtimeMs;

		if (mtime !== cache.mtime) {
			cache = { mtime, values: { ...DEFAULTS, ...JSON.parse(fs.readFileSync(PATH, 'utf8')) } };
		}

		return cache.values;
	}
	catch {
		return cache.mtime === null ? DEFAULTS : cache.values;
	}
}

// Express middleware: the service answers 503 while the panel has it switched off.
function down(service) {
	return (req, res, next) => get().down.includes(service)
		? res.status(503).json({ error: `${service} is down (fault panel)` })
		: next();
}

// Express error handler: the exception goes to the span of the request, so that Zabbix groups it by type and message.
function failed(trace, SpanStatusCode) {
	return (error, req, res, next) => {
		const span = trace.getActiveSpan();
		const status = error.status || 500;

		if (span && status >= 500) {
			span.recordException(error);
			span.setStatus({ code: SpanStatusCode.ERROR, message: error.message });
		}

		console.error(`${req.method} ${req.path}: ${error.name}: ${error.message}`);
		res.status(status).json({ error: error.message });
	};
}

module.exports = { get, down, failed };
