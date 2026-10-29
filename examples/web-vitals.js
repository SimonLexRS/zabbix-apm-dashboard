/*
 * Real-user monitoring for the "User experience" tab: sends the Core Web Vitals of real browsers as OTLP/HTTP log
 * records with the rum.* attributes the module reads (rum.metric, rum.value, rum.rating, rum.page, rum.device,
 * rum.id). Uses the web-vitals library (npm i web-vitals).
 *
 * ENDPOINT: the collector's OTLP/HTTP logs URL. Best behind your own site (e.g. a reverse proxy path /otlp/v1/logs to
 * http://collector:4318/v1/logs) so the browser posts same-origin; to post to the collector directly, enable CORS in
 * its otlp http receiver (cors: allowed_origins: ["https://your.site"]).
 *
 * Privacy: send only the route template of the page (no ids, tokens or query strings) and nothing about the person.
 */
import {onCLS, onFCP, onINP, onLCP, onTTFB} from 'web-vitals';

const ENDPOINT = '/otlp/v1/logs';
const SERVICE = 'my-app-browser'; // shown as a service in Zabbix APM; any name works

// Replace ids in the path with placeholders, e.g. /orders/8f2c… -> /orders/{id}. Adapt to your routes.
const page = location.pathname.replace(/\/[0-9a-f-]{8,}|\/\d+/gi, '/{id}');
const device = matchMedia('(max-width: 768px), (pointer: coarse)').matches ? 'mobile' : 'desktop';
const queue = [];

function attr(key, value) {
	return {key, value: typeof value === 'number' ? {doubleValue: value} : {stringValue: String(value)}};
}

function flush() {
	if (queue.length === 0) {
		return;
	}

	const body = JSON.stringify({
		resourceLogs: [{
			resource: {attributes: [attr('service.name', SERVICE)]},
			scopeLogs: [{
				scope: {name: 'rum'},
				logRecords: queue.splice(0).map((m) => ({
					timeUnixNano: String(Date.now() * 1e6),
					severityText: 'INFO',
					body: {stringValue: 'web-vital ' + m.name},
					attributes: [
						attr('rum.metric', m.name),
						attr('rum.value', m.value),
						attr('rum.rating', m.rating), // good | needs-improvement | poor
						attr('rum.page', page),
						attr('rum.device', device),
						attr('rum.nav', m.navigationType),
						attr('rum.id', m.id) // one value per page load: CLS/INP are reported again when the tab is hidden
					]
				}))
			}]
		}]
	});

	navigator.sendBeacon(ENDPOINT, new Blob([body], {type: 'application/json'}));
}

for (const on of [onLCP, onINP, onCLS, onFCP, onTTFB]) {
	on((metric) => queue.push(metric));
}

addEventListener('visibilitychange', () => document.visibilityState === 'hidden' && flush());
addEventListener('pagehide', flush);
