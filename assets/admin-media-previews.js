(function () {
	'use strict';
	if (!window.wp || !wp.media || !wp.media.view || !wp.media.view.Attachment) return;
	const Attachment = wp.media.view.Attachment;
	if (Attachment.prototype.schrackPreviewInstalled) return;
	Attachment.prototype.schrackPreviewInstalled = true;
	const nativeImageSize = Attachment.prototype.imageSize;
	Attachment.prototype.imageSize = function (size) {
		const preview = this.model.get('schrackMediaPreview');
		return preview && preview.small ? Object.assign({}, preview.small) : nativeImageSize.call(this, size);
	};
	// Only clone render data. The model's full URL and insertion-size choices stay native.
	const Details = Attachment.Details;
	if (Details && Details.prototype.render) {
		const nativeRender = Details.prototype.render;
		Details.prototype.render = function () {
			// media-grid registers TwoColumn after this asset. Resolve its template at render time.
			const nativeTemplate = this.template;
			const ownTemplate = Object.prototype.hasOwnProperty.call(this, 'template');
			this.template = function (data) {
				const previews = data.schrackMediaPreview;
				if (!previews || !previews.detail || data.type !== 'image') return nativeTemplate.call(this, data);
				const copy = Object.assign({}, data, {size: Object.assign({}, previews.detail)});
				copy.sizes = Object.assign({}, data.sizes, {full: Object.assign({}, previews.detail)});
				const html = nativeTemplate.call(this, copy);
				if (typeof html !== 'string') return html;
				// Some core templates read data.url directly. Rewrite images before DOM insertion;
				// inert template contents cannot fetch originals. File/download URLs stay native.
				const markup = document.createElement('template');
				markup.innerHTML = html;
				markup.content.querySelectorAll('img').forEach(image => {
					image.setAttribute('src', previews.detail.url);
					image.removeAttribute('srcset');
					image.removeAttribute('sizes');
				});
				return markup.innerHTML;
			};
			try { return nativeRender.apply(this, arguments); }
			finally {
				if (ownTemplate) this.template = nativeTemplate;
				else delete this.template;
			}
		};
	}
	// WooCommerce builds new gallery <img> elements by mapping its selection toJSON().
	// Give that admin renderer a clone, preserving the model and every other insertion flow.
	const Selection = wp.media.model && wp.media.model.Selection;
	const Model = wp.media.model && wp.media.model.Attachment;
	if (Selection && Model && Selection.prototype.map && Model.prototype.toJSON) {
		let galleryRenderDepth = 0;
		const nativeMap = Selection.prototype.map;
		const nativeJSON = Model.prototype.toJSON;
		Selection.prototype.map = function () {
			const frame = wp.media.frames && wp.media.frames.product_gallery;
			const state = frame && typeof frame.state === 'function' ? frame.state() : null;
			if (!state || !state.get || state.get('selection') !== this) return nativeMap.apply(this, arguments);
			galleryRenderDepth++;
			try { return nativeMap.apply(this, arguments); }
			finally { galleryRenderDepth--; }
		};
		Model.prototype.toJSON = function () {
			const data = nativeJSON.apply(this, arguments);
			const preview = data.schrackMediaPreview;
			if (!galleryRenderDepth || data.type !== 'image' || !preview || !preview.small) return data;
			return Object.assign({}, data, {sizes: Object.assign({}, data.sizes, {thumbnail: Object.assign({}, preview.small)})});
		};
	}
})();
