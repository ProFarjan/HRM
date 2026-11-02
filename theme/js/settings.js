/* Settings: keep the active tab across the reload after saving */
(function ($) {
	'use strict';
	$(function () {
		var key = 'hrm.settings.tab';
		try {
			var saved = window.sessionStorage.getItem(key);
			if (saved && $('a[href="' + saved + '"]').length) { $('a[href="' + saved + '"]').tab('show'); }
		} catch (e) { /* storage blocked: start on the first tab */ }
		$('a[data-toggle=tab]').on('shown.bs.tab', function () {
			try { window.sessionStorage.setItem(key, $(this).attr('href')); } catch (e) { /* ignore */ }
		});
	});
}(window.jQuery));
