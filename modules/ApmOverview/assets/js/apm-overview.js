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
	 * The block of the visible tab, or null on the static tab.
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
	 * a request is running or on the static tab.
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
