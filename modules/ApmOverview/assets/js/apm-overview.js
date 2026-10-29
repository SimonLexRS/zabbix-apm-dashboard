/*
 * APM overview: loads the active tab and keeps it up to date.
 *
 * Module assets are loaded on every frontend page, so this file only declares the class; the APM overview view
 * creates the instance. Only the visible tab is queried: when it is shown and then at the chosen interval. The server
 * (apm.overview.data) returns the block already rendered and escaped by PHP (OverviewHtml.php); data values are never
 * inserted as HTML here, status texts use textContent.
 */
class CApmOverviewLive {

	constructor({data_url, labels}) {
		this._storage_key = 'apm.overview.live_interval';
		this._intervals = [10, 30, 0];
		this._request_timeout = 30000;

		this._data_url = data_url;
		this._labels = labels; // translated by the view (I18n)
		this._interval = this._loadInterval();
		this._markPaused();
		this._updated = null;
		this._attempted = null;
		this._timeout = null;
		this._loading = null;
		this._error = null;

		this._status = document.getElementById('apm-live-status');

		for (const radio of document.querySelectorAll('input[name="apm_live_interval"]')) {
			radio.checked = Number(radio.value) === this._interval;
			radio.addEventListener('change', () => {
				if (radio.checked) {
					this._setInterval(Number(radio.value));
				}
			});
		}

		document.addEventListener('visibilitychange', () => this._schedule());
		jQuery(document).on('tabsactivate', () => this._onTabChange());
		setInterval(() => this._renderStatus(), 1000);

		// After the tab view has picked the active tab.
		setTimeout(() => this._onTabChange(), 0);
	}

	/**
	 * The refreshable block of the visible tab, or null if it has none.
	 */
	_activeBlock() {
		for (const block of document.querySelectorAll('[data-apm-block]')) {
			if (block.getClientRects().length > 0) {
				return block;
			}
		}

		return null;
	}

	_onTabChange() {
		const block = this._activeBlock();

		this._updated = block !== null && block.dataset.apmLoaded ? Number(block.dataset.apmLoaded) : null;
		this._attempted = this._updated;
		this._error = null;

		// The request of the tab that was left is not needed any more.
		if (this._loading !== null) {
			this._loading.abort();
			this._loading = null;
		}

		// A tab never loaded, or older than the interval, is loaded now.
		if (block !== null && (this._updated === null
				|| Date.now() - this._updated >= Math.max(this._interval, 10) * 1000)) {
			this._refresh(block);
		}
		else {
			this._renderStatus();
			this._schedule();
		}
	}

	/**
	 * While paused, the service map stops its particles too (CSS: .apm-live-paused).
	 */
	_markPaused() {
		document.body.classList.toggle('apm-live-paused', this._interval === 0);
	}

	_loadInterval() {
		let value = null;

		try {
			value = localStorage.getItem(this._storage_key);
		}
		catch (e) {
			// Storage not available (privacy mode): use the default.
		}

		return value !== null && this._intervals.includes(Number(value)) ? Number(value) : this._intervals[0];
	}

	_setInterval(interval) {
		this._interval = interval;
		this._markPaused();

		try {
			localStorage.setItem(this._storage_key, String(interval));
		}
		catch (e) {
			// Not persisted, still applied to this page.
		}

		this._renderStatus();
		this._schedule();
	}

	/**
	 * Plan the next refresh of the visible tab. Nothing is planned while paused, while the browser tab is hidden, while
	 * a request is running or on a tab without a refreshable block.
	 */
	_schedule() {
		clearTimeout(this._timeout);
		this._timeout = null;

		const block = this._activeBlock();

		if (this._interval === 0 || this._loading !== null || block === null
				|| document.visibilityState !== 'visible') {
			return;
		}

		// From the last attempt, failed ones included: a failing tab is retried at the interval, not in a loop.
		const last = Math.max(this._updated ?? 0, this._attempted ?? 0);
		const age = last > 0 ? Date.now() - last : Infinity;

		this._timeout = setTimeout(() => this._refresh(this._activeBlock()), Math.max(0, this._interval * 1000 - age));
	}

	_refresh(block) {
		if (block === null) {
			return;
		}

		clearTimeout(this._timeout);
		this._timeout = null;

		if (this._loading !== null) {
			this._loading.abort();
		}

		const tab = block.dataset.apmBlock;
		const controller = new AbortController();
		const abort_timer = setTimeout(() => controller.abort(), this._request_timeout);

		this._loading = controller;
		this._renderStatus();

		fetch(this._data_url + '&tab=' + encodeURIComponent(tab), {
			credentials: 'same-origin',
			headers: {'Accept': 'application/json'},
			signal: controller.signal
		})
			.then((response) => {
				if (!response.ok) {
					throw new Error('HTTP ' + response.status);
				}

				return response.json();
			})
			.then((response) => {
				if (typeof response.html === 'string' && response.tab === tab) {
					// Server-rendered markup (escaped by CTag), see OverviewHtml.php. Open waterfalls stay open.
					const open = [...block.querySelectorAll('details[data-trace][open]')].map((el) => el.dataset.trace);

					block.innerHTML = response.html;

					for (const el of block.querySelectorAll('details[data-trace]')) {
						if (open.length > 0) {
							el.open = open.includes(el.dataset.trace);
						}
					}

					block.dataset.apmLoaded = String(Date.now());

					if (block === this._activeBlock()) {
						this._updated = Date.now();
					}
				}

				if (block === this._activeBlock()) {
					this._error = typeof response.html !== 'string'
						? this._labels.failed
						: (typeof response.error === 'string' && response.error !== '' ? this._labels.source_error : null);
				}
			})
			.catch((error) => {
				if (controller !== this._loading) {
					return; // replaced by a newer request (tab change)
				}

				this._error = error.name === 'AbortError' ? this._labels.no_response : this._labels.failed;
			})
			.finally(() => {
				clearTimeout(abort_timer);

				if (controller === this._loading) {
					this._loading = null;
					this._attempted = Date.now();
					this._renderStatus();
					this._schedule();
				}
			});
	}

	_renderStatus() {
		if (this._status === null) {
			return;
		}

		const parts = [];

		if (this._updated !== null) {
			const seconds = Math.max(0, Math.round((Date.now() - this._updated) / 1000));

			parts.push(seconds < 60
				? this._labels.updated_s.replace('%s', seconds)
				: this._labels.updated_min.replace('%s', Math.floor(seconds / 60))
			);
		}

		if (this._loading !== null) {
			parts.push(this._updated === null ? this._labels.loading : this._labels.refreshing);
		}
		else if (this._interval === 0) {
			parts.push(this._labels.paused);
		}

		if (this._error !== null) {
			parts.push(this._error);
		}

		this._status.textContent = parts.join(' · ');
		this._status.classList.toggle('apm-overview-live-error', this._error !== null);
	}
}

/*
 * "How it works" guide: the form fills in the steps of the chosen language. Templates live in data-apm-template and
 * are written back with textContent, so nothing typed in the form is ever interpreted as HTML.
 */
class CApmOverviewGuide {

	constructor() {
		this._root = document.getElementById('apm-guide');

		if (this._root === null) {
			return;
		}

		this._storage = {collector: 'apm.guide.collector', language: 'apm.guide.language'};
		this._namespace = document.getElementById('apm-guide-namespace');
		this._service = document.getElementById('apm-guide-service');
		this._collector = document.getElementById('apm-guide-collector');
		this._default_service = true;

		this._collector.value = this._load(this._storage.collector) ?? '';

		this._namespace.addEventListener('input', () => {
			// The service follows the application until it is edited.
			if (this._default_service) {
				this._service.value = this._namespace.value.trim() !== '' ? this._namespace.value.trim() + '-api' : '';
			}
			this._render();
		});
		this._service.addEventListener('input', () => {
			this._default_service = false;
			this._render();
		});
		this._collector.addEventListener('input', () => {
			this._save(this._storage.collector, this._collector.value.trim());
			this._render();
		});

		for (const radio of document.querySelectorAll('input[name="apm_guide_language"]')) {
			radio.addEventListener('change', () => {
				if (radio.checked) {
					this._show(radio.value);
					this._save(this._storage.language, radio.value);
				}
			});
		}

		this._root.addEventListener('click', (e) => {
			const button = e.target.closest('.apm-guide-copy');

			if (button !== null) {
				e.preventDefault();
				this._copy(button);
			}
		});

		const language = this._load(this._storage.language);
		const radio = language !== null
			? document.querySelector('input[name="apm_guide_language"][value="' + CSS.escape(language) + '"]')
			: null;

		if (radio !== null) {
			radio.checked = true;
			this._show(radio.value);
		}

		this._render();
	}

	_values() {
		const namespace = this._namespace.value.trim() || 'my-app';

		return {
			namespace,
			service: this._service.value.trim() || namespace + '-api',
			collector: this._collector.value.trim() || this._collector.placeholder,
			// Same rule as Integration::hostName() and scripts/zabbix-apm-app.py: other characters become "-".
			zabbix_host: 'apm-' + namespace.replace(/[^0-9A-Za-z._ -]/g, '-'),
			// This frontend: the part of the address before zabbix.php.
			zabbix_url: location.origin + location.pathname.replace(/\/[^/]*$/, '')
		};
	}

	_render() {
		const values = this._values();

		for (const el of this._root.querySelectorAll('[data-apm-template]')) {
			// Replaces {namespace}, {service}, {collector}, {zabbix_host} and {zabbix_url}; other braces stay as they are.
			el.textContent = el.dataset.apmTemplate.replace(/\{(namespace|service|collector|zabbix_host|zabbix_url)\}/g,
				(match, key) => values[key]
			);
		}
	}

	_show(language) {
		for (const panel of this._root.querySelectorAll('.apm-guide-panel')) {
			panel.hidden = panel.dataset.language !== language;
		}
	}

	_copy(button) {
		const text = button.parentElement.querySelector('code').textContent;
		const done = () => {
			const label = button.textContent;

			button.textContent = button.dataset.copied;
			setTimeout(() => button.textContent = label, 1500);
		};

		// The clipboard API needs a secure context (https); Zabbix is often served over http.
		if (navigator.clipboard && window.isSecureContext) {
			navigator.clipboard.writeText(text).then(done, () => this._copyFallback(text) && done());
		}
		else if (this._copyFallback(text)) {
			done();
		}
	}

	_copyFallback(text) {
		const area = document.createElement('textarea');

		area.value = text;
		area.setAttribute('readonly', '');
		area.style.position = 'fixed';
		area.style.opacity = '0';
		document.body.appendChild(area);
		area.select();

		let copied = false;

		try {
			copied = document.execCommand('copy');
		}
		catch (e) {
			// Not allowed: the text stays selectable in the block.
		}

		area.remove();

		return copied;
	}

	_load(key) {
		try {
			return localStorage.getItem(key);
		}
		catch (e) {
			return null;
		}
	}

	_save(key, value) {
		try {
			localStorage.setItem(key, value);
		}
		catch (e) {
			// Not persisted (privacy mode).
		}
	}
}

/*
 * Service map interaction, for the APM overview page and the APM service map widget: hovering a node or an arrow lights
 * its path (connected arrows and nodes) and shows its figures. Delegated on the document, so it also works for maps
 * replaced by the real-time refresh. Tooltip texts come from data-tip-title / data-tip and are written as text.
 */
class CApmServiceMapInteraction {

	static init() {
		if (CApmServiceMapInteraction._instance === undefined) {
			CApmServiceMapInteraction._instance = new CApmServiceMapInteraction();
		}
	}

	constructor() {
		this._tip = null;
		this._current = null;

		document.addEventListener('mouseover', (e) => this._over(e));
		document.addEventListener('mouseout', (e) => this._out(e));
		document.addEventListener('mousemove', (e) => this._move(e));
		document.addEventListener('focusin', (e) => this._over(e));
		document.addEventListener('focusout', (e) => this._out(e));
	}

	_element(target) {
		return target instanceof Element ? target.closest('.apm-map-svg [data-apm-node], .apm-map-svg [data-apm-edge]') : null;
	}

	_over(e) {
		const element = this._element(e.target);

		if (element === null || element === this._current) {
			return;
		}

		this._clear();
		this._current = element;

		const svg = element.closest('.apm-map-svg');
		const lit = new Set([element]);

		if (element.dataset.apmNode !== undefined) {
			const id = element.dataset.apmNode;

			for (const edge of svg.querySelectorAll('[data-apm-edge]')) {
				if (edge.dataset.from === id || edge.dataset.to === id) {
					lit.add(edge);
					lit.add(this._node(svg, edge.dataset.from === id ? edge.dataset.to : edge.dataset.from));
				}
			}
		}
		else {
			lit.add(this._node(svg, element.dataset.from));
			lit.add(this._node(svg, element.dataset.to));
		}

		svg.classList.add('apm-map-tracing');

		for (const item of lit) {
			if (item !== null) {
				item.classList.add('apm-map-lit');
			}
		}

		this._show(element, e);
	}

	_out(e) {
		const element = this._element(e.target);

		if (element === null || element !== this._current || element.contains(e.relatedTarget)) {
			return;
		}

		this._clear();
	}

	_move(e) {
		if (this._tip !== null && this._current !== null) {
			this._place(e.clientX, e.clientY);
		}
	}

	_clear() {
		if (this._current !== null) {
			const svg = this._current.closest('.apm-map-svg');

			if (svg !== null) {
				svg.classList.remove('apm-map-tracing');

				for (const item of svg.querySelectorAll('.apm-map-lit')) {
					item.classList.remove('apm-map-lit');
				}
			}
		}

		this._current = null;

		if (this._tip !== null) {
			this._tip.remove();
			this._tip = null;
		}
	}

	_node(svg, id) {
		return svg.querySelector('[data-apm-node="' + CSS.escape(id) + '"]');
	}

	_show(element, e) {
		const tip = document.createElement('div');
		const title = document.createElement('strong');

		tip.className = 'apm-map-tip';
		title.textContent = element.dataset.tipTitle ?? '';
		tip.appendChild(title);

		for (const line of (element.dataset.tip ?? '').split('\n')) {
			if (line !== '') {
				const row = document.createElement('div');

				row.textContent = line;
				tip.appendChild(row);
			}
		}

		document.body.appendChild(tip);
		this._tip = tip;

		if (e.type === 'focusin') {
			const box = element.getBoundingClientRect();

			this._place(box.right, box.top);
		}
		else {
			this._place(e.clientX, e.clientY);
		}
	}

	_place(x, y) {
		const margin = 14;
		const box = this._tip.getBoundingClientRect();
		const left = x + margin + box.width > window.innerWidth ? x - margin - box.width : x + margin;
		const top = y + margin + box.height > window.innerHeight ? y - margin - box.height : y + margin;

		this._tip.style.left = Math.max(0, left) + 'px';
		this._tip.style.top = Math.max(0, top) + 'px';
	}
}

CApmServiceMapInteraction.init();

/**
 * Side panel of the Real user monitoring page: the timeline of a session (apm.rum.session), opened from the Sessions
 * tab. The HTML comes rendered and escaped by the server.
 */
class CApmRumSessions {

	constructor({session_url, labels}) {
		this._url = session_url;
		this._labels = labels;
		this._panel = document.getElementById('apm-rum-panel');
		this._body = this._panel.querySelector('.apm-rum-panel-body');
		this._close = this._panel.querySelector('.apm-rum-panel-close');
		this._opener = null;
		this._request = null;

		document.addEventListener('click', (e) => {
			const button = e.target.closest('[data-apm-session]');

			if (button !== null) {
				this.open(button);
			}
			else if (e.target.closest('.apm-rum-panel-close') !== null) {
				this.close();
			}
		});

		document.addEventListener('keydown', (e) => {
			if (e.key === 'Escape' && !this._panel.hidden) {
				this.close();
			}
		});
	}

	open(button) {
		this._request?.abort();
		this._request = new AbortController();
		this._opener = button;
		this._body.textContent = this._labels.loading;
		this._panel.hidden = false;
		this._close.focus();

		const url = this._url
			+ '&session=' + encodeURIComponent(button.dataset.apmSession)
			+ '&from=' + encodeURIComponent(button.dataset.from)
			+ '&to=' + encodeURIComponent(button.dataset.to);

		fetch(url, {signal: this._request.signal})
			.then((response) => response.ok ? response.json() : Promise.reject(new Error(response.statusText)))
			.then((response) => {
				if (typeof response.html !== 'string') {
					throw new Error(response.error ?? '');
				}

				this._body.innerHTML = response.html;
			})
			.catch((error) => {
				if (error.name !== 'AbortError') {
					this._body.textContent = this._labels.failed;
				}
			});
	}

	close() {
		this._request?.abort();
		this._panel.hidden = true;
		this._opener?.focus();
	}
}
