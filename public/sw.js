// Minimal service worker.
// Purpose: avoid 404s and aggressively clear legacy caches from any previous SW
// so the app doesn't serve stale HTML/JS that can cause CSP/action mismatches.

self.addEventListener('install', (event) => {
	event.waitUntil(self.skipWaiting());
});

self.addEventListener('activate', (event) => {
	event.waitUntil(
		(async () => {
			try {
				const keys = await caches.keys();
				await Promise.all(keys.map((key) => caches.delete(key)));
			} finally {
				await self.clients.claim();
			}
		})()
	);
});
