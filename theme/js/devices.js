/* Devices: list with health, pending serial numbers, API keys, command queue, call log */
(function ($, HRM) {
	'use strict';
	function esc(text) { return $('<div>').text(text).html(); }
	$(function () {
		var $d = $('#devices-table');
		if ($d.length) {
			HRM.table('#devices-table', [
				{ data: 'name' }, { data: 'mode' }, { data: 'serial' }, { data: 'status' }, { data: 'health', orderable: false }, { data: 'last_seen' }, { data: 'last_punch' },
				{ data: 'today', orderable: false }, { data: 'flags', orderable: false },
				{ data: null, orderable: false, render: function (row) {
					var html = HRM.editLink($d.data('edit-url'), row.id);
					if ($d.data('allow-url') && row.blocked) {
						html += ' <button type="button" class="btn btn-xs btn-warning hrm-post" data-table="#devices-table" data-url="' + $d.data('allow-url') + '/' + parseInt(row.id, 10) + '" title="' + esc($d.data('allow-title') || '') + '"><i class="fa fa-unlock"></i></button>';
					}
					if ($d.data('delete-url')) {
						html += ' <button type="button" class="btn btn-xs btn-danger hrm-post" data-table="#devices-table" data-url="' + $d.data('delete-url') + '/' + parseInt(row.id, 10)
							+ '" data-confirm="' + esc($d.data('confirm')) + '"><i class="fa fa-trash"></i></button>';
					}
					return html;
				} }
			], null, { order: [[0, 'asc']] });
			window.setInterval(function () { $d.DataTable().ajax.reload(null, false); }, 30000);   // health refreshes itself
		}

		$(document).on('click', '.hrm-post-fields', function () {
			var $b = $(this);
			if ($b.data('confirm') && !window.confirm($b.data('confirm'))) { return; }
			$.ajax({ url: $b.data('url'), type: 'POST', data: { serial_number: $b.data('serial') }, dataType: 'text' }).done(function (text) {
				if (window.HRM && window.HRM.ok(text)) { window.location.reload(); }
			});
		});

		var $k = $('#keys-table');
		if ($k.length) {
			var keys = HRM.table('#keys-table', [
				{ data: 'label' }, { data: 'device', orderable: false }, { data: 'hint', orderable: false }, { data: 'created' }, { data: 'last_used' }, { data: 'status', orderable: false },
				{ data: null, orderable: false, render: function (row) {
					return row.revoked ? '' : '<button type="button" class="btn btn-xs btn-danger hrm-post" data-table="#keys-table" data-url="' + $k.data('revoke-url') + '/' + parseInt(row.id, 10)
						+ '" data-confirm="' + esc($k.data('confirm')) + '"><i class="fa fa-ban"></i></button>';
				} }
			], null, { order: [[3, 'desc']] });
			$(document).on('hrm:key-created', function (e, text) {
				$('#new-key').text(String(text).split('<<<###>>>')[1] || '');
				$('#new-key-box').show();
				keys.ajax.reload(null, false);
			});
		}

		if ($('#commands-table').length) {
			HRM.table('#commands-table', [
				{ data: 'id' }, { data: 'device' }, { data: 'type' }, { data: 'payload', orderable: false }, { data: 'status' }, { data: 'created' }, { data: 'done' }, { data: 'result', orderable: false }
			], '#commands-filter', { order: [[0, 'desc']] });
			var toggle = function () { $('#employee-group').toggle(/^(create_user|update_user|delete_user)$/.test($('#f_type').val())); };
			$('#f_type').on('change', toggle);
			toggle();
		}

		if ($('#logs-table').length) {
			HRM.table('#logs-table', [
				{ data: 'time' }, { data: 'device' }, { data: 'dir', orderable: false }, { data: 'endpoint' }, { data: 'code' }, { data: 'records' }, { data: 'ip' }, { data: 'message', orderable: false }
			], '#logs-filter', { order: [[0, 'desc']] });
			$('#errors-only').on('ifChanged change', function () { $('#logs-table').DataTable().ajax.reload(); });
		}
	});
}(window.jQuery, window.HRM));
