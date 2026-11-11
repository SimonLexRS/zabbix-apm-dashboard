"""bank: the card issuer. An outside service: no OpenTelemetry here, the store only sees how long it takes."""
import json
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

import faults


class Handler(BaseHTTPRequestHandler):
	def do_POST(self):
		self.rfile.read(int(self.headers.get('Content-Length', 0)))
		switches = faults.get()

		if 'bank' in switches['down']:
			# Closes without answering, as a host that went away.
			self.close_connection = True
			return

		time.sleep(0.03 + switches['bank_slow_ms'] / 1000)
		body = json.dumps({'approved': True}).encode()

		try:
			self.send_response(200)
			self.send_header('Content-Type', 'application/json')
			self.send_header('Content-Length', str(len(body)))
			self.end_headers()
			self.wfile.write(body)
		except OSError:
			pass

	def log_message(self, *args):
		pass


ThreadingHTTPServer(('', 8000), Handler).serve_forever()
