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

	// ---- model results: "success", "success<<<###>>>id" or an error text (one problem per line) --------------------
	cfg.ok = function (text) {
		text = String(text);
		if (text.indexOf('success') === 0) { return true; }
		if (window.toastr) { window.toastr.error($('<div>').text(text).html().replace(/\n/g, '<br>')); }
		return false;
	};

	// ---- forms posted by AJAX: <form class="hrm-ajax-form" data-redirect="..." | data-reload="1"> ---------------------
	$(document).on('submit', 'form.hrm-ajax-form', function (e) {
		e.preventDefault();
		var $form = $(this), $btn = $form.find('[type=submit]').prop('disabled', true);
		$.ajax({ url: $form.attr('action'), type: 'POST', data: new window.FormData(this), processData: false, contentType: false, dataType: 'text' })
			.done(function (text) {
				if (!cfg.ok(text)) { return; }
				window.toastr.success(cfg.savedText || 'Saved');
				if ($form.data('redirect')) { window.location = $form.data('redirect'); }
				else if ($form.data('reload')) { window.setTimeout(function () { window.location.reload(); }, 400); }
				else { $form[0].reset(); }
			})
			.fail(function (xhr) { if (xhr.status !== 401 && xhr.status !== 403 && xhr.status !== 405) { cfg.ok(xhr.responseText || 'Error'); } })
			.always(function () { $btn.prop('disabled', false); });
	});

	// ---- action buttons: <button class="hrm-post" data-url data-confirm data-redirect data-reload> ----------------
	$(document).on('click', '.hrm-post', function () {
		var $b = $(this);
		if ($b.data('confirm') && !window.confirm($b.data('confirm'))) { return; }
		$.ajax({ url: $b.data('url'), type: 'POST', dataType: 'text' }).done(function (text) {
			if (!cfg.ok(text)) { return; }
			window.toastr.success(cfg.savedText || 'Saved');
			if ($b.data('redirect')) { window.location = $b.data('redirect'); }
			else if ($b.data('reload')) { window.setTimeout(function () { window.location.reload(); }, 400); }
			else if ($b.data('table')) { $($b.data('table')).DataTable().ajax.reload(null, false); }
		});
	});

	// ---- server-side list: cfg.table('#id', columns, '#filter-form') ------------------------------------------------
	cfg.table = function (selector, columns, filterForm, options) {
		var $t = $(selector);
		var dt = $t.DataTable($.extend({
			ajax: {
				url: $t.data('url'), type: 'POST',
				data: function (d) {
					if (filterForm) { $.each($(filterForm).serializeArray(), function (i, f) { d[f.name] = f.value; }); }
				},
				error: function (xhr) { if (xhr.status === 422) { cfg.ok(xhr.responseText); } }
			},
			columns: columns
		}, options || {}));
		if (filterForm) {
			$(filterForm).on('change', 'select', function () { dt.ajax.reload(); });
			$(filterForm).on('submit', function (e) { e.preventDefault(); dt.ajax.reload(); });
		}
		return dt;
	};

	// action cell helpers (ids are integers from the server; urls come from data-* attributes)
	cfg.editLink = function (url, id) {
		return url ? '<a class="btn btn-xs btn-default" href="' + url + '/' + parseInt(id, 10) + '"><i class="fa fa-pencil"></i></a>' : '';
	};

	// PHP date format (setting date_format) -> bootstrap-datepicker format
	function pickerFormat(php) {
		return String(php || 'd-m-Y').replace('d', 'dd').replace('m', 'mm').replace('Y', 'yyyy');
	}

	$(function () {
		initICheck(document);
		if (cfg.idleMinutes > 0) { startIdleTimer(cfg.idleMinutes); }
		if ($.fn.datepicker) {
			$('.datepicker').datepicker({ format: pickerFormat(cfg.dateFormat), autoclose: true, todayHighlight: true });
			// the picker re-reads the field only on keyup: a pasted or autofilled value would be wiped when the field loses focus
			$(document).on('input paste', '.datepicker', function () { var el = this; window.setTimeout(function () { $(el).datepicker('update'); }, 0); });
		}
		if ($.fn.select2) { $('.select2').select2(); }
		$('#branch-switch').on('change', function () { $('#branch-switch-form').submit(); });
	});
}(window.jQuery, window));
