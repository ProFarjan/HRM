/* Attendance list (actions by permission), punches list, calendar, form helpers */
(function ($, HRM) {
	'use strict';
	function btn(cls, url, id, icon, title, extra) {
		return ' <button type="button" class="btn btn-xs ' + cls + ' hrm-post" data-table="#attendance-table" data-url="' + url + '/' + parseInt(id, 10) + '" title="'
			+ $('<div>').text(title).html() + '"' + (extra || '') + '><i class="fa ' + icon + '"></i></button>';
	}
	$(function () {
		var $t = $('#attendance-table');
		if ($t.length) {
			HRM.table('#attendance-table', [
				{ data: 'date' }, { data: 'code' }, { data: 'name' }, { data: 'department' }, { data: 'status' }, { data: 'in' }, { data: 'out' },
				{ data: 'hours' }, { data: 'late' }, { data: 'ot' }, { data: 'approval' },
				{ data: null, orderable: false, render: function (row) {
					if (row.locked) { return '<i class="fa fa-lock text-muted"></i>'; }
					var html = HRM.editLink($t.data('edit-url'), row.id);
					if (row.pending && $t.data('approve-url') && !row.own) {
						html += btn('btn-success', $t.data('approve-url'), row.id, 'fa-check', $t.data('t-approve'));
						html += ' <button type="button" class="btn btn-xs btn-warning att-reject" data-id="' + parseInt(row.id, 10) + '" title="' + $('<div>').text($t.data('t-reject')).html() + '"><i class="fa fa-ban"></i></button>';
					}
					if ($t.data('rebuild-url')) { html += btn('btn-default', $t.data('rebuild-url'), row.id, 'fa-refresh', $t.data('t-rebuild')); }
					if ($t.data('delete-url')) { html += btn('btn-danger', $t.data('delete-url'), row.id, 'fa-trash', '', ' data-confirm="' + $('<div>').text($t.data('confirm')).html() + '"'); }
					return html;
				} }
			], '#attendance-filter', { order: [[0, 'desc']] });
			$('#missing-filter').on('change', function () { $t.DataTable().ajax.reload(); });
			$t.on('click', '.att-reject', function () {
				var note = window.prompt($t.data('reject-prompt'));
				if (!note) { return; }
				$.ajax({ url: $t.data('reject-url') + '/' + $(this).data('id'), type: 'POST', data: { approval_note: note }, dataType: 'text' }).done(function (text) {
					if (HRM.ok(text)) { window.toastr.success(HRM.successText(text)); $t.DataTable().ajax.reload(null, false); }
				});
			});
		}

		var $p = $('#punches-table');
		if ($p.length) {
			HRM.table('#punches-table', [
				{ data: 'when' }, { data: 'employee', orderable: false }, { data: 'pin' }, { data: 'device' }, { data: 'state' }, { data: 'source' }, { data: 'ignored' }
			], '#punches-filter', { order: [[0, 'desc']] });
			$('#punches-filter input[name=unmatched]').on('change', function () { $p.DataTable().ajax.reload(); });
		}

		var $cal = $('#attendance-calendar');
		if ($cal.length && $.fn.fullCalendar) {
			$cal.fullCalendar({
				header: { left: 'prev,next today', center: 'title', right: '' },
				firstDay: 1, height: 'auto',
				events: function (start, end, tz, callback) {
					$.ajax({ url: $cal.data('url'), type: 'POST', dataType: 'json', data: { start: start.format('YYYY-MM-DD'), end: end.clone().subtract(1, 'days').format('YYYY-MM-DD') } })
						.done(callback);
				}
			});
		}

		// times only for worked days
		function toggleTimes() {
			var worked = ['Present', 'Half-day'].indexOf($('#f_status').val()) !== -1;
			$('#time-fields').toggle(worked);
			$('#time-fields').next('div').find('input[name=check_out]').closest('div').toggle(worked);
		}
		$('#f_status').on('change', toggleTimes);
		if ($('#f_status').length) { toggleTimes(); }

		$(document).on('hrm:punch-added', function (e, text) {
			if (String(text).indexOf('manual') !== -1) { window.toastr.info($('#punch-form').data('kept')); }
		});
	});
}(window.jQuery, window.HRM));
