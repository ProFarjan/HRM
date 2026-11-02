/*
 * HRM shell: CSRF on every non-GET ajax call, iCheck, toastr defaults, DataTables defaults, idle-logout timer.
 * Configuration comes from window.HRM (layout/scripts.php).
 */
(function ($, window) {
	'use strict';
	var cfg = window.HRM || {};

	// ---- CSRF: CodeIgniter reads the token from the POST body, so add it to every non-GET request -------------
	$.ajaxPrefilter(function (options) {
		var type = (options.type || 'GET').toUpperCase();
		if (options.crossDomain || type === 'GET' || type === 'HEAD' || !cfg.csrfName) { return; }
		if (window.FormData && options.data instanceof window.FormData) {
			if (!options.data.has(cfg.csrfName)) { options.data.append(cfg.csrfName, cfg.csrfHash); }
		} else if (typeof options.data === 'string' && options.data.indexOf(encodeURIComponent(cfg.csrfName) + '=') === -1) {
			options.data = (options.data ? options.data + '&' : '') + encodeURIComponent(cfg.csrfName) + '=' + encodeURIComponent(cfg.csrfHash);
		} else if (options.data === undefined || options.data === null) {
			options.data = encodeURIComponent(cfg.csrfName) + '=' + encodeURIComponent(cfg.csrfHash);
		}
	});

	// ---- toastr ------------------------------------------------------------------------------------------------
	if (window.toastr) {
		window.toastr.options = { closeButton: true, progressBar: true, positionClass: 'toast-top-right', timeOut: 5000 };
	}

	// A model returns "success", "success<<<###>>>id" or an error text; pages show it with HRM.notify().
	window.HRM_notify = function (result) {
		var text = String(result);
		if (text.indexOf('success') === 0) { window.toastr.success('Saved'); return true; }
		window.toastr.error(text.replace(/\n/g, '<br>'));
		return false;
	};

	// ---- global ajax errors --------------------------------------------------------------------------------------
	$(document).ajaxError(function (event, xhr, settings) {
		if (xhr.status === 401) {
			window.location = cfg.loginUrl + '?r=idle';
		} else if ((xhr.status === 403 || xhr.status === 405) && window.toastr && !(settings && settings.suppressErrors)) {
			window.toastr.error(xhr.responseText || 'Access denied');
		}
	});

	// ---- iCheck: bind 'ifChanged' (not only 'change') for logic; read :checked at submit ---------------------------
	function initICheck(root) {
		if (!$.fn.iCheck) { return; }
		$(root || document).find('input[type=checkbox],input[type=radio]').not('.no-icheck,.icheck-done').each(function () {
			$(this).addClass('icheck-done').iCheck({ checkboxClass: 'icheckbox_square-blue', radioClass: 'iradio_square-blue' });
		});
	}
	window.HRM_initICheck = initICheck;

	// ---- DataTables: server side defaults ---------------------------------------------------------------------
	if ($.fn.dataTable) {
		$.extend(true, $.fn.dataTable.defaults, {
			processing: true, serverSide: true, pageLength: 25, lengthMenu: [10, 25, 50, 100], autoWidth: false
		});
	}

	// ---- idle logout --------------------------------------------------------------------------------------------
	function startIdleTimer(minutes) {
		var lastActivity = Date.now(), lastPing = Date.now(), limit = minutes * 60000;
		function touch() { lastActivity = Date.now(); }
		$(document).on('mousemove keydown click scroll touchstart', touch);
		window.setInterval(function () {
			var now = Date.now();
			if (now - lastActivity > limit) {
				$.post(cfg.logoutUrl).always(function () { window.location = cfg.loginUrl + '?r=idle'; });
			} else if (now - lastActivity < 60000 && now - lastPing > 60000) {   // active: keep the server side timer alive
				lastPing = now;
				$.ajax({ url: cfg.pingUrl, type: 'POST', suppressErrors: true });
			}
		}, 15000);
	}

	$(function () {
		initICheck(document);
		if (cfg.idleMinutes > 0) { startIdleTimer(cfg.idleMinutes); }
	});
}(window.jQuery, window));
