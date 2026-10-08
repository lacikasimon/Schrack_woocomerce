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
	function wrapTemplate(View) {
		if (!View || !View.prototype.template) return;
		const nativeTemplate = View.prototype.template;
		View.prototype.template = function (data) {
			const previews = data.schrackMediaPreview;
			if (!previews || !previews.detail || data.type !== 'image') return nativeTemplate.call(this, data);
			const copy = Object.assign({}, data, {size: Object.assign({}, previews.detail)});
			copy.sizes = Object.assign({}, data.sizes, {full: Object.assign({}, previews.detail)});
			return nativeTemplate.call(this, copy);
		};
	}
	wrapTemplate(Attachment.Details);
	wrapTemplate(Attachment.Details && Attachment.Details.TwoColumn);
})();
