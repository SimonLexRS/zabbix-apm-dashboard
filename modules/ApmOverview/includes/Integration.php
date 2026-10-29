<?php declare(strict_types = 0);

namespace Modules\ApmOverview\Includes;

/**
 * How to connect an application, per language or environment: the guide of the "How it works" tab. Texts and code use
 * the PLACEHOLDERS, filled in by the page script (as text, never as HTML) from the guide form.
 *
 * The instructions follow the tested examples of the repository: examples/stacks/go (Go SDK), examples/stacks/zero-code
 * (Java, Python, Node.js, .NET, Kubernetes) and the OpenTelemetry Demo services (PHP: quote, Ruby: email).
 */
class Integration {

	public const PLACEHOLDERS = ['{namespace}', '{service}', '{collector}', '{zabbix_host}', '{zabbix_url}'];

	public const DEFAULT_COLLECTOR = 'collector.example.com';

	/**
	 * @return array  [key => ['label' => string, 'steps' => [['title' => string, 'text' => string, 'code' => string]]]]
	 */
	public static function languages(): array {
		$languages = [
			'go' => ['Go', [
				self::envStep(false),
				[
					'title' => I18n::t('Install and initialize the SDK'),
					'text' => I18n::t('Go has no agent: copy examples/stacks/go/telemetry/telemetry.go into your project and call Setup in main. It reads the variables of step 1; otelhttp names each server span after its route. For PostgreSQL with pgx add the otelpgx tracer.'),
					'code' => implode("\n", [
						'go get go.opentelemetry.io/otel@v1.47.0 go.opentelemetry.io/otel/sdk@v1.47.0 \\',
						'  go.opentelemetry.io/otel/sdk/metric@v1.47.0 go.opentelemetry.io/otel/sdk/log@v1.47.0 \\',
						'  go.opentelemetry.io/otel/exporters/otlp/otlptrace/otlptracegrpc@v1.47.0 \\',
						'  go.opentelemetry.io/otel/exporters/otlp/otlpmetric/otlpmetricgrpc@v1.47.0 \\',
						'  go.opentelemetry.io/otel/exporters/otlp/otlplog/otlploggrpc@v0.23.0 \\',
						'  go.opentelemetry.io/contrib/instrumentation/net/http/otelhttp@v0.72.0 \\',
						'  go.opentelemetry.io/contrib/instrumentation/runtime@v0.72.0 \\',
						'  go.opentelemetry.io/contrib/bridges/otelslog@v0.21.0',
						'',
						'// main.go',
						'shutdown, err := telemetry.Setup(ctx, "{service}")',
						'if err != nil {',
						'	log.Fatal(err)',
						'}',
						'defer shutdown(context.Background())',
						'slog.SetDefault(slog.New(telemetry.SlogHandler(slog.NewJSONHandler(os.Stdout, nil))))',
						'',
						'http.ListenAndServe(":8080", otelhttp.NewHandler(mux, "http"))',
						'client := &http.Client{Transport: otelhttp.NewTransport(http.DefaultTransport)}'
					])
				]
			]],
			'python' => ['Python', [
				self::envStep(false),
				[
					'title' => I18n::t('Install the agent and start the application with it'),
					'text' => I18n::t('No code changes: opentelemetry-bootstrap installs the instrumentation of the libraries in use (Django, Flask, FastAPI, requests, psycopg, SQLAlchemy…). With gunicorn or uvicorn, prefix their command the same way.'),
					'code' => implode("\n", [
						'pip install opentelemetry-distro opentelemetry-exporter-otlp',
						'opentelemetry-bootstrap -a install',
						'',
						'export OTEL_PYTHON_LOGGING_AUTO_INSTRUMENTATION_ENABLED=true',
						'opentelemetry-instrument python app.py'
					])
				]
			]],
			'java' => ['Java', [
				self::envStep(false),
				[
					'title' => I18n::t('Download the Java agent and start the JVM with it'),
					'text' => I18n::t('No code changes (Java 8+): Spring, servlet containers, JDBC, Kafka, HTTP clients and logging frameworks are instrumented by the agent.'),
					'code' => implode("\n", [
						'curl -L -o opentelemetry-javaagent.jar \\',
						'  https://github.com/open-telemetry/opentelemetry-java-instrumentation/releases/latest/download/opentelemetry-javaagent.jar',
						'',
						'java -javaagent:./opentelemetry-javaagent.jar -jar app.jar'
					])
				]
			]],
			'nodejs' => ['Node.js', [
				self::envStep(false),
				[
					'title' => I18n::t('Install the auto-instrumentation and load it at startup'),
					'text' => I18n::t('No code changes: Express, Fastify, NestJS, http, pg, mysql, redis… are instrumented when the process starts with the register hook.'),
					'code' => implode("\n", [
						'npm install @opentelemetry/api @opentelemetry/auto-instrumentations-node',
						'',
						'export NODE_OPTIONS="--require @opentelemetry/auto-instrumentations-node/register"',
						'node app.js'
					])
				]
			]],
			'dotnet' => ['.NET', [
				self::envStep(false),
				[
					'title' => I18n::t('Install the automatic instrumentation and run the application with it'),
					'text' => I18n::t('No code changes: ASP.NET Core, HttpClient, SqlClient and EF Core are instrumented. On Windows use the PowerShell module of the same release.'),
					'code' => implode("\n", [
						'curl -sSfL https://github.com/open-telemetry/opentelemetry-dotnet-instrumentation/releases/latest/download/otel-dotnet-auto-install.sh -O',
						'sh ./otel-dotnet-auto-install.sh',
						'',
						'. $HOME/.otel-dotnet-auto/instrument.sh',
						'dotnet app.dll'
					])
				]
			]],
			'php' => ['PHP', [
				self::envStep(true, ['OTEL_PHP_AUTOLOAD_ENABLED=true']),
				[
					'title' => I18n::t('Install the extension and the packages'),
					'text' => I18n::t('PHP 8.1+. The opentelemetry extension hooks the framework; there are auto-instrumentation packages for Slim (shown), Laravel, Symfony, PSR-15, PDO and others. OTLP over HTTP (port 4318), as the OpenTelemetry Demo quote service.'),
					'code' => implode("\n", [
						'pecl install opentelemetry protobuf   # then extension=opentelemetry and extension=protobuf in php.ini',
						'',
						'composer require open-telemetry/sdk open-telemetry/exporter-otlp php-http/guzzle7-adapter \\',
						'  open-telemetry/opentelemetry-auto-slim'
					])
				]
			]],
			'ruby' => ['Ruby', [
				self::envStep(true),
				[
					'title' => I18n::t('Add the gems and configure the SDK at startup'),
					'text' => I18n::t('Traces of Rails, Sinatra, Rack, Net::HTTP, pg… over OTLP/HTTP (port 4318). For metrics and logs add opentelemetry-metrics-sdk and opentelemetry-logs-sdk with their OTLP exporters, as the OpenTelemetry Demo email service.'),
					'code' => implode("\n", [
						'# Gemfile',
						'gem "opentelemetry-sdk"',
						'gem "opentelemetry-exporter-otlp"',
						'gem "opentelemetry-instrumentation-all"',
						'',
						'# at startup (Rails: config/initializers/opentelemetry.rb)',
						'require "opentelemetry/sdk"',
						'require "opentelemetry/exporter/otlp"',
						'require "opentelemetry/instrumentation/all"',
						'',
						'OpenTelemetry::SDK.configure do |c|',
						'  c.use_all',
						'end'
					])
				]
			]],
			'compose' => ['Docker Compose', [
				[
					'title' => I18n::t('Add a collector to the application'),
					'text' => I18n::t('The services send to a collector of the application, which forwards everything to Zabbix APM and adds service.namespace where a service does not set it. Save as otel-collector.yaml next to compose.yaml. The exporter is named otlp in older collector versions.'),
					'code' => implode("\n", [
						'receivers:',
						'  otlp:',
						'    protocols:',
						'      grpc:',
						'        endpoint: 0.0.0.0:4317',
						'      http:',
						'        endpoint: 0.0.0.0:4318',
						'processors:',
						'  batch: {}',
						'  resource/app:',
						'    attributes:',
						'      - key: service.namespace',
						'        value: {namespace}',
						'        action: insert',
						'exporters:',
						'  otlp_grpc/zabbix:',
						'    endpoint: {collector}:4317',
						'    tls:',
						'      insecure: true   # only inside a VPN or private network',
						'service:',
						'  pipelines:',
						'    traces:',
						'      receivers: [otlp]',
						'      processors: [resource/app, batch]',
						'      exporters: [otlp_grpc/zabbix]',
						'    metrics:',
						'      receivers: [otlp]',
						'      processors: [resource/app, batch]',
						'      exporters: [otlp_grpc/zabbix]',
						'    logs:',
						'      receivers: [otlp]',
						'      processors: [resource/app, batch]',
						'      exporters: [otlp_grpc/zabbix]'
					])
				],
				[
					'title' => I18n::t('Run the collector and point the services to it'),
					'text' => I18n::t('Each service keeps its own instrumentation (the other languages of this guide) and only changes the endpoint. Full tested example: examples/stacks/opentelemetry-demo.'),
					'code' => implode("\n", [
						'services:',
						'  otel-collector:',
						'    image: otel/opentelemetry-collector-contrib:latest',
						'    command: ["--config=/etc/otelcol/config.yaml"]',
						'    volumes:',
						'      - ./otel-collector.yaml:/etc/otelcol/config.yaml:ro',
						'',
						'  {service}:',
						'    environment:',
						'      OTEL_SERVICE_NAME: {service}',
						'      OTEL_RESOURCE_ATTRIBUTES: service.namespace={namespace}',
						'      OTEL_EXPORTER_OTLP_ENDPOINT: http://otel-collector:4317'
					])
				]
			]],
			'kubernetes' => ['Kubernetes', [
				[
					'title' => I18n::t('Create an Instrumentation resource (OpenTelemetry Operator)'),
					'text' => I18n::t('The OpenTelemetry Operator injects the agent of each language into the pods. The Python and .NET agents export over HTTP: their endpoint uses port 4318.'),
					'code' => implode("\n", [
						'apiVersion: opentelemetry.io/v1alpha1',
						'kind: Instrumentation',
						'metadata:',
						'  name: zabbix-apm',
						'spec:',
						'  exporter:',
						'    endpoint: http://{collector}:4317',
						'  propagators: [tracecontext, baggage]',
						'  resource:',
						'    resourceAttributes:',
						'      service.namespace: {namespace}',
						'  python:',
						'    env:',
						'      - name: OTEL_EXPORTER_OTLP_ENDPOINT',
						'        value: http://{collector}:4318',
						'  dotnet:',
						'    env:',
						'      - name: OTEL_EXPORTER_OTLP_ENDPOINT',
						'        value: http://{collector}:4318'
					])
				],
				[
					'title' => I18n::t('Annotate the workload'),
					'text' => I18n::t('Use the annotation of the language of the pods: inject-java, inject-python, inject-nodejs, inject-dotnet or inject-go. Without the Operator, set the variables of the other languages of this guide in the Deployment.'),
					'code' => implode("\n", [
						'kubectl apply -f instrumentation.yaml',
						'',
						'kubectl patch deployment {service} -p \\',
						'  \'{"spec":{"template":{"metadata":{"annotations":{"instrumentation.opentelemetry.io/inject-java":"true"}}}}}\''
					])
				]
			]],
			'test' => [I18n::t('Test without code'), [
				[
					'title' => I18n::t('Send test traces with telemetrygen'),
					'text' => I18n::t('Checks the whole chain (network, collector, ClickHouse, this page, the Zabbix host) before instrumenting anything: five minutes of synthetic traces for the application.'),
					'code' => implode("\n", [
						'docker run --rm ghcr.io/open-telemetry/opentelemetry-collector-contrib/telemetrygen:latest traces \\',
						'  --otlp-endpoint {collector}:4317 --otlp-insecure --duration 5m --rate 5 --child-spans 2 \\',
						'  --service {service} --otlp-attributes \'service.namespace="{namespace}"\''
					])
				],
				[
					'title' => I18n::t('Look at the result'),
					'text' => I18n::t('Within a minute the tab {namespace} appears at the top of this page (reload it) and the checks below turn green. Delete the test host when you are done; the test data expires with the telemetry retention.'),
					'code' => ''
				]
			]]
		];

		$result = [];

		foreach ($languages as $key => [$label, $steps]) {
			$result[$key] = ['label' => $label, 'steps' => array_merge($steps, self::zabbixSteps())];
		}

		return $result;
	}

	/**
	 * OpenTelemetry web SDK packages of the browser guide: one compatible set (all on @opentelemetry/api 1.9).
	 */
	public const BROWSER_PACKAGES = [
		'@opentelemetry/sdk-trace-web' => '2.12.0',
		'@opentelemetry/exporter-trace-otlp-http' => '0.223.0',
		'@opentelemetry/resources' => '2.12.0',
		'@opentelemetry/instrumentation' => '0.223.0',
		'@opentelemetry/instrumentation-document-load' => '0.68.0',
		'@opentelemetry/instrumentation-user-interaction' => '0.67.0',
		'@opentelemetry/instrumentation-fetch' => '0.223.0'
	];

	public const DEFAULT_BROWSER_COLLECTOR = 'https://otel.example.com';

	/**
	 * How to connect the browser part of an application (Real user monitoring guide): without a build step, with npm,
	 * or in Next.js. The same tracing code in every case.
	 *
	 * @return array  [key => ['label' => string, 'steps' => [['title' => string, 'text' => string, 'code' => string]]]]
	 */
	public static function browserMethods(): array {
		// The CDN shares one @opentelemetry/api and core between the packages.
		$cdn = static fn(string $package, string $version): string => 'https://esm.sh/'.$package.'@'.$version.
			'?deps=@opentelemetry/api@1.9.0,@opentelemetry/core@2.12.0';

		$import_map = [];

		foreach (self::BROWSER_PACKAGES as $package => $version) {
			$import_map[] = '    "'.$package.'": "'.$cdn($package, $version).'"';
		}

		$npm_install = 'npm install '.implode(' ', array_map(
			static fn(string $package, string $version): string => $package.'@'.$version,
			array_keys(self::BROWSER_PACKAGES), self::BROWSER_PACKAGES
		));

		$common = [
			[
				'title' => I18n::t('Let the browser reach the collector'),
				'text' => I18n::t('The browser sends OTLP over HTTP. Either publish the receiver (port 4318) through HTTPS and allow the origin of the site with CORS, or, simpler and private, forward a path of the site itself to the collector and use that URL as the collector URL above (same origin, no CORS).'),
				'code' => implode("\n", [
					'# OpenTelemetry Collector (config.yaml): allow the origin of the site',
					'receivers:',
					'  otlp:',
					'    protocols:',
					'      http:',
					'        endpoint: 0.0.0.0:4318',
					'        cors:',
					'          allowed_origins: ["https://www.example.com"]',
					'',
					'# Or, nginx of the site: same origin, the collector stays private',
					'location /otlp/ {',
					'  proxy_pass http://collector:4318/;',
					'}'
				])
			],
			[
				'title' => I18n::t('Link the requests to the backend'),
				'text' => I18n::t('Requests to the same origin carry the trace (traceparent header) by themselves. For an API on another origin, add it to propagateTraceHeaderCorsUrls and allow the traceparent and tracestate headers in its CORS policy. Each request then continues in the backend services, in the same trace, and the session timeline shows them.'),
				'code' => ''
			],
			[
				'title' => I18n::t('Check that it works'),
				'text' => I18n::t('Open a few pages and click around: the checks below turn green within a minute, and the application gets its data in the other tabs.'),
				'code' => ''
			]
		];

		$code = implode("\n", [
			'import { WebTracerProvider, BatchSpanProcessor } from \'@opentelemetry/sdk-trace-web\';',
			'import { OTLPTraceExporter } from \'@opentelemetry/exporter-trace-otlp-http\';',
			'import { defaultResource, resourceFromAttributes } from \'@opentelemetry/resources\';',
			'import { registerInstrumentations } from \'@opentelemetry/instrumentation\';',
			'import { DocumentLoadInstrumentation } from \'@opentelemetry/instrumentation-document-load\';',
			'import { UserInteractionInstrumentation } from \'@opentelemetry/instrumentation-user-interaction\';',
			'import { FetchInstrumentation } from \'@opentelemetry/instrumentation-fetch\';',
			'',
			'// One id per browser tab session, on every span.',
			'const sessionId = sessionStorage.getItem(\'otel.session.id\')',
			'  ?? crypto.randomUUID?.() ?? Math.random().toString(36).slice(2);',
			'sessionStorage.setItem(\'otel.session.id\', sessionId);',
			'',
			'new WebTracerProvider({',
			'  // The default resource carries telemetry.sdk.language = webjs, which tells browser spans apart.',
			'  resource: defaultResource().merge(',
			'    resourceFromAttributes({ \'service.name\': \'{service}\', \'service.namespace\': \'{namespace}\' })',
			'  ),',
			'  spanProcessors: [',
			'    {',
			'      onStart: (span) => span.setAttribute(\'session.id\', sessionId),',
			'      onEnd: () => {}, forceFlush: () => Promise.resolve(), shutdown: () => Promise.resolve()',
			'    },',
			'    new BatchSpanProcessor(new OTLPTraceExporter({ url: \'{collector}/v1/traces\' }))',
			'  ]',
			'}).register();',
			'',
			'registerInstrumentations({',
			'  instrumentations: [',
			'    new DocumentLoadInstrumentation(),',
			'    new UserInteractionInstrumentation(),',
			'    // An API on another origin: add it here, e.g. /^https:\\/\\/api\\.example\\.com/',
			'    new FetchInstrumentation({ propagateTraceHeaderCorsUrls: [] })',
			'  ]',
			'});'
		]);

		$indent = static fn(string $text): string => '  '.str_replace("\n", "\n  ", $text);

		return [
			'script' => [
				'label' => I18n::t('Without build (script)'),
				'steps' => array_merge([[
					'title' => I18n::t('Paste this in the <head> of every page'),
					'text' => I18n::t('Nothing to install: the browser loads the official OpenTelemetry web SDK from a CDN (pinned versions). Page loads, clicks and fetch requests become spans with the session id. Load the scripts of the application after it (as modules, or with defer), so that their clicks are traced.'),
					'code' => implode("\n", [
						'<script type="importmap">',
						'{',
						'  "imports": {',
						implode(",\n", $import_map),
						'  }',
						'}',
						'</script>',
						'<script type="module">',
						$indent($code),
						'</script>'
					])
				]], $common)
			],
			'npm' => [
				'label' => I18n::t('npm / bundler'),
				'steps' => array_merge([
					[
						'title' => I18n::t('Install the packages'),
						'text' => I18n::t('For applications built with a bundler (Vite, webpack, React, Vue, Angular…).'),
						'code' => $npm_install
					],
					[
						'title' => I18n::t('Add otel-browser.js and import it first'),
						'text' => I18n::t('Import it at the top of the entry point of the application, before anything else: import \'./otel-browser.js\';'),
						'code' => "// otel-browser.js\n".$code
					]
				], $common)
			],
			'nextjs' => [
				'label' => 'Next.js',
				'steps' => array_merge([
					[
						'title' => I18n::t('Install the packages'),
						'text' => I18n::t('Next.js 15.3 or later runs instrumentation-client.ts in the browser before the application starts.'),
						'code' => $npm_install
					],
					[
						'title' => I18n::t('Create instrumentation-client.ts in the root of the project'),
						'text' => I18n::t('Next to package.json (or in src/). The server side keeps its own instrumentation.ts.'),
						'code' => "// instrumentation-client.ts\n".$code
					]
				], $common)
			]
		];
	}

	/**
	 * The text with the placeholders replaced, for the first render (the page script does the same in the browser).
	 */
	public static function fill(string $template, array $values): string {
		return strtr($template, $values);
	}

	/**
	 * Default values of the placeholders for an application.
	 */
	public static function defaults(string $application): array {
		$namespace = $application !== '' ? $application : 'my-app';

		return [
			'{namespace}' => $namespace,
			'{service}' => $namespace.'-api',
			'{collector}' => self::DEFAULT_COLLECTOR,
			'{zabbix_host}' => self::hostName($namespace),
			'{zabbix_url}' => 'https://zabbix.example.com/zabbix'
		];
	}

	/**
	 * Technical name of the Zabbix host of an application, as scripts/zabbix-apm-app.py creates it.
	 */
	public static function hostName(string $namespace): string {
		// Characters not allowed in host names (anything but letters, digits, ".", "_", " " and "-") become "-".
		return 'apm-'.preg_replace('/[^0-9A-Za-z._ -]/', '-', $namespace);
	}

	private static function envStep(bool $http, array $extra = []): array {
		return [
			'title' => I18n::t('Set the OpenTelemetry variables'),
			'text' => $http
				? I18n::t('The same variables for every service of the application: the name of the service, the application (service.namespace, required) and the collector over OTLP/HTTP (port 4318).')
				: I18n::t('The same variables for every service of the application: the name of the service, the application (service.namespace, required) and the collector over OTLP/gRPC (port 4317).'),
			'code' => implode("\n", array_merge([
				'export OTEL_SERVICE_NAME={service}',
				'export OTEL_RESOURCE_ATTRIBUTES=service.namespace={namespace},deployment.environment.name=production',
				$http ? 'export OTEL_EXPORTER_OTLP_ENDPOINT=http://{collector}:4318' : 'export OTEL_EXPORTER_OTLP_ENDPOINT=http://{collector}:4317',
				$http ? 'export OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf' : 'export OTEL_EXPORTER_OTLP_PROTOCOL=grpc',
				'export OTEL_TRACES_EXPORTER=otlp OTEL_METRICS_EXPORTER=otlp OTEL_LOGS_EXPORTER=otlp'
			], array_map(static fn(string $line): string => 'export '.$line, $extra)))
		];
	}

	private static function zabbixSteps(): array {
		return [
			[
				'title' => I18n::t('Create the Zabbix host of the application'),
				'text' => I18n::t('Once per application, from a copy of the zabbix-apm-dashboard repository: imports the template "OpenTelemetry application by OTLP" if needed and creates the host {zabbix_host} with {$OTLP.NAMESPACE} = {namespace}. By hand: Data collection → Hosts → Create host, link that template and set the macro.'),
				'code' => implode("\n", [
					'export ZABBIX_URL={zabbix_url} ZABBIX_API_TOKEN=<token>   # User settings → API tokens',
					'python3 scripts/zabbix-apm-app.py add {namespace}'
				])
			],
			[
				'title' => I18n::t('Check that it works'),
				'text' => I18n::t('The checks below update by themselves. After 3-4 minutes the script compares the values of the Zabbix host with ClickHouse (add CH_URL, CH_USER and CH_PASSWORD for that part).'),
				'code' => 'python3 scripts/verify-app.py {namespace}'
			]
		];
	}
}
