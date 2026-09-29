(function () {
	'use strict';

	const selector = 'img[data-schrack-image-src]';

	function start() {
		// One observer also serves cards inserted by filtering, pagination or Elementor.
		if (document.documentElement.hasAttribute('data-schrack-lazy-images')) return;
		document.documentElement.setAttribute('data-schrack-lazy-images', '');

		const fallbackImages = new WeakSet();
		function prepareFallback(image) {
			const original = image.getAttribute('data-schrack-image-fallback');
			if (!original || fallbackImages.has(image)) return;
			fallbackImages.add(image);
			function finish(failed) {
				// The placeholder may finish loading before the card is visible.
				if (image.getAttribute('data-schrack-image-src') !== null) return;
				image.removeEventListener('error', onError);
				image.removeEventListener('load', onLoad);
				image.removeAttribute('data-schrack-image-fallback');
				fallbackImages.delete(image);
				if (failed) {
					image.removeAttribute('srcset');
					image.removeAttribute('sizes');
					image.setAttribute('src', original);
				}
			}
			function onError() { finish(true); }
			function onLoad() { finish(image.naturalWidth <= 1 || image.naturalHeight <= 1); }
			image.addEventListener('error', onError);
			image.addEventListener('load', onLoad);
		}

		function load(image) {
			const src = image.getAttribute('data-schrack-image-src');
			if (!src) return;

			// Sizes must precede srcset so the browser chooses the correct local thumbnail.
			image.setAttribute('loading', 'eager');
			['sizes', 'srcset', 'src'].forEach(function (attribute) {
				const key = 'data-schrack-image-' + attribute;
				const value = image.getAttribute(key);
				if (value !== null) image.setAttribute(attribute, value);
				image.removeAttribute(key);
			});
		}

		const observer = 'IntersectionObserver' in window ? new IntersectionObserver(function (entries) {
			entries.forEach(function (entry) {
				if (entry.isIntersecting && entry.target.isConnected) {
					observer.unobserve(entry.target);
					load(entry.target);
				}
			});
		}, { rootMargin: '100px 0px', threshold: 0 }) : null;

		function visit(node, callback) {
			if (node.nodeType !== 1) return;
			if (node.matches(selector)) callback(node);
			node.querySelectorAll(selector).forEach(callback);
		}

		function observe(image) {
			prepareFallback(image);
			if (observer) {
				observer.observe(image);
			} else {
				load(image);
			}
		}

		visit(document.documentElement, observe);
		new MutationObserver(function (records) {
			records.forEach(function (record) {
				if (observer) record.removedNodes.forEach(function (node) {
					visit(node, function (image) { observer.unobserve(image); });
				});
			});
			records.forEach(function (record) {
				record.addedNodes.forEach(function (node) {
					if (node.isConnected) visit(node, observe);
				});
			});
		}).observe(document.documentElement, { childList: true, subtree: true });
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', start, { once: true });
	} else {
		start();
	}
}());
