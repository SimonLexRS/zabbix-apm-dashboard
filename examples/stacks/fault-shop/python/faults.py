"""The switches of the fault panel: one JSON file on a volume shared by every service.

A file, not Redis or HTTP, so that reading a switch adds no span to the traces. Only "shop" writes it.
"""
import json
import os

PATH = os.environ.get('FAULTS_FILE', '/faults/faults.json')
SERVICES = ('catalog', 'checkout', 'payment', 'bank')

# key: (default, lowest, highest); booleans have no range, "down" is a list of services.
DEFINITIONS = {
	'db_slow_ms': (0, 0, 10000),
	'n_plus_one': (False,),
	'poison_product': (False,),
	'out_of_stock': (False,),
	'payment_fail_pct': (0, 0, 100),
	'bank_slow_ms': (0, 0, 10000),
	'cpu_burn_ms': (0, 0, 2000),
	'notifier_slow_ms': (0, 0, 10000),
	'notifier_fail': (False,),
	'js_error': (False,),
	'load_rps': (1, 0, 5),
	'down': ([],)
}

_cache = {'mtime': None, 'values': None}


def defaults() -> dict:
	return {key: definition[0] for key, definition in DEFINITIONS.items()}


def get() -> dict:
	try:
		mtime = os.stat(PATH).st_mtime_ns
	except OSError:
		return defaults()

	if mtime != _cache['mtime']:
		try:
			with open(PATH, encoding='utf-8') as file:
				_cache['values'] = {**defaults(), **json.load(file)}
			_cache['mtime'] = mtime
		except (OSError, ValueError):
			return _cache['values'] or defaults()

	return _cache['values']


def is_down(service: str) -> bool:
	return service in get()['down']


def clean(values: dict) -> dict:
	"""Keeps known switches only, each within its range."""
	result = {}

	for key, definition in DEFINITIONS.items():
		if key not in values:
			continue

		value = values[key]

		if key == 'down':
			result[key] = [service for service in SERVICES if isinstance(value, list) and service in value]
		elif len(definition) == 1:
			result[key] = bool(value)
		else:
			try:
				result[key] = max(definition[1], min(definition[2], int(value)))
			except (TypeError, ValueError):
				pass

	return result


def save(values: dict) -> dict:
	merged = {**get(), **clean(values)}
	temporary = PATH + '.tmp'

	with open(temporary, 'w', encoding='utf-8') as file:
		json.dump(merged, file)

	os.replace(temporary, PATH)

	return merged


def reset() -> dict:
	_cache['mtime'] = None

	try:
		os.remove(PATH)
	except OSError:
		pass

	return defaults()
