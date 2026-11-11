// Browser telemetry (RUM) of the store, as in Zabbix: APM -> Real user monitoring -> Set up -> "Without build".
// Page loads, clicks, requests and page errors go to the Collector through this site (/otlp, see nginx.conf).
// Without Internet access to the CDN the store still works, only without browser telemetry.
try {
	const cdn = (name, version) =>
		`https://esm.sh/@opentelemetry/${name}@${version}?deps=@opentelemetry/api@1.9.0,@opentelemetry/core@2.12.0`;

	const [
		{ WebTracerProvider, BatchSpanProcessor }, { OTLPTraceExporter }, { defaultResource, resourceFromAttributes },
		{ registerInstrumentations }, { DocumentLoadInstrumentation }, { UserInteractionInstrumentation },
		{ FetchInstrumentation }
	] = await Promise.all([
		import(cdn('sdk-trace-web', '2.12.0')), import(cdn('exporter-trace-otlp-http', '0.223.0')),
		import(cdn('resources', '2.12.0')), import(cdn('instrumentation', '0.223.0')),
		import(cdn('instrumentation-document-load', '0.68.0')), import(cdn('instrumentation-user-interaction', '0.67.0')),
		import(cdn('instrumentation-fetch', '0.223.0'))
	]);

	// One id per browser tab session and one per page view, on every span.
	const sessionId = sessionStorage.getItem('otel.session.id') ?? crypto.randomUUID?.() ?? Math.random().toString(36).slice(2);
	sessionStorage.setItem('otel.session.id', sessionId);
	const viewId = crypto.randomUUID?.() ?? Math.random().toString(36).slice(2);

	const provider = new WebTracerProvider({
		// The default resource carries telemetry.sdk.language = webjs, which tells browser spans apart.
		resource: defaultResource().merge(resourceFromAttributes({
			'service.name': 'shop-web', 'service.namespace': 'fault-shop', 'service.version': '1.0.0'
		})),
		spanProcessors: [
			{
				onStart: (span) => span.setAttributes({
					'session.id': sessionId, 'view.id': viewId, 'view.path': location.pathname
				}),
				onEnd: () => {}, forceFlush: () => Promise.resolve(), shutdown: () => Promise.resolve()
			},
			new BatchSpanProcessor(new OTLPTraceExporter({ url: location.origin + '/otlp/v1/traces' }))
		]
	});
	provider.register();

	// Errors of the page itself: uncaught exceptions and rejected promises become failed spans.
	const tracer = provider.getTracer('page-errors');
	const reportError = (error, source) => {
		const span = tracer.startSpan(source, { attributes: { 'url.full': location.href } });
		span.recordException(error instanceof Error ? error : new Error(String(error)));
		span.setStatus({ code: 2, message: String(error?.message ?? error) });
		span.end();
	};
	addEventListener('error', (event) => reportError(event.error ?? event.message, 'window.onerror'));
	addEventListener('unhandledrejection', (event) => reportError(event.reason, 'unhandledrejection'));

	registerInstrumentations({
		tracerProvider: provider,
		instrumentations: [
			new DocumentLoadInstrumentation(),
			new UserInteractionInstrumentation({ eventNames: ['click', 'submit'] }),
			new FetchInstrumentation({ ignoreUrls: [/\/otlp\//] })
		]
	});

	addEventListener('pagehide', () => provider.forceFlush());
}
catch (error) {
	console.warn('Browser telemetry is off:', error);
}
