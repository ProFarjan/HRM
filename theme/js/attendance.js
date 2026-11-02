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

		var $u = $('#unmatched-table');
		if ($u.length) {
			HRM.table('#unmatched-table', [
				{ data: 'pin' }, { data: 'count' }, { data: 'first', orderable: false }, { data: 'last', orderable: false }, { data: 'devices', orderable: false },
				{ data: null, orderable: false, render: function (row) {
					return $u.data('can-link') ? '<button type="button" class="btn btn-xs btn-primary link-pin" data-pin="' + $('<div>').text(row.raw_pin).html() + '"><i class="fa fa-link"></i></button>' : '';
				} }
			], '#unmatched-filter', { order: [[1, 'desc']] });
			if ($.fn.select2) { $('#link-employee').select2({ dropdownParent: $('#link-modal') }); }
			$(document).on('click', '.link-pin', function () {
				$('#link-pin').val($(this).data('pin'));
				$('#link-pin-label').text($(this).data('pin'));
				$('#link-modal').modal('show');
			});
		}

		if ($('#closed-table').length) {
			HRM.table('#closed-table', [
				{ data: 'when' }, { data: 'employee', orderable: false }, { data: 'pin' }, { data: 'device' }, { data: 'source' }, { data: 'day', orderable: false }
			], '#closed-filter', { order: [[0, 'desc']] });
		}

		// import punches: preview first (nothing is written), then confirm
		var $imp = $('#import-form');
		if ($imp.length) {
			var row = function (label, value) { return $('<tr>').append($('<th>').text(label), $('<td>').text(value)); };
			$imp.on('submit', function (e) {
				e.preventDefault();
				var $btn = $('#import-preview').prop('disabled', true);
				$.ajax({ url: $imp.attr('action'), type: 'POST', data: new window.FormData(this), processData: false, contentType: false, dataType: 'json' })
					.done(function (r) {
						if (!r.ok) { window.toastr.error($('<div>').text(r.message).html().replace(/\n/g, '<br>')); $('#preview-box').hide(); return; }
						var L = window.HRM_LABELS || {};
						$('#preview-summary').empty().append(
							row(L.device || 'Device', r.device), row(L.rows || 'Rows', r.rows), row(L.valid || 'Valid punches', r.valid), row(L.invalid || 'Invalid rows', r.invalid),
							row(L.range || 'Period', r.from + ' - ' + r.to), row(L.newp || 'New', r.new), row(L.already || 'Already stored', r.already)
						);
						var $list = $('#preview-unmatched-list').empty();
						$.each(r.unmatched, function (i, u) { $list.append($('<span class="label label-warning" style="margin-right:4px;display:inline-block">').text(u.pin + ' (' + u.punches + ')')); });
						$('#preview-unmatched').toggle(r.unmatched_total > 0);
						$('#preview-sample').empty();
						$.each(r.sample, function (i, s) { $('#preview-sample').append($('<tr>').append($('<td>').text(s.pin), $('<td>').text(s.at))); });
						$('#commit-token').val(r.token);
						$('#preview-box').show();
					})
					.fail(function (xhr) { if (xhr.status !== 401 && xhr.status !== 403) { window.toastr.error('Error'); } })
					.always(function () { $btn.prop('disabled', false); });
			});
			$('#commit-form').on('submit', function (e) {
				e.preventDefault();
				var $btn = $('#import-commit').prop('disabled', true);
				$.ajax({ url: $(this).attr('action'), type: 'POST', data: { token: $('#commit-token').val() }, dataType: 'text' }).done(function (text) {
					if (!HRM.ok(text)) { return; }
					var n = String(text).split('<<<###>>>')[1].split('/');
					window.toastr.success(String($('#commit-form').data('done') || '%d new, %d already stored, %d unmatched').replace(/%d/g, function () { return n.shift() || '0'; }));
					$('#preview-box').hide();
					$imp[0].reset();
				}).always(function () { $btn.prop('disabled', false); });
			});
		}

		// mark / edit form: an employee on a split shift gets one in / out pair per duty period instead of a single pair
		var $form = $('#attendance-form');
		if ($form.length) {
			var showPeriods = function (periods) {
				var $box = $('#period-rows');
				var signature = JSON.stringify($.map(periods || [], function (p) { return p.start + '-' + p.end; }));
				if ($box.data('signature') === signature && $box.children().length === (periods || []).length) {
					return toggleTimes();                                         // the same periods again: keep what has been typed
				}
				$box.data('signature', signature).empty();
				$('#period-title').text($form.data('t-split'));
				$.each(periods || [], function (i, p) {
					var $row = $('<div class="row period-input-row" style="margin-bottom:6px">');
					$row.append($('<div class="col-sm-4">').append($('<strong>').text($form.data('t-period') + ' ' + (i + 1)), $('<br>'), $('<small class="text-muted">').text(p.start + ' - ' + p.end)));
					$row.append($('<div class="col-sm-4">').append($('<input type="time" name="seg_in[]" class="form-control">').val(p.in || '')));
					$row.append($('<div class="col-sm-4">').append($('<input type="time" name="seg_out[]" class="form-control">').val(p.out || '')));
					$box.append($row);
				});
				toggleTimes();
			};
			var lookups = 0;
			var lookup = function () {
				var url = $form.data('day-info-url');
				var employee = $('#f_employee').val(), date = $('#f_date').val();
				var mine = ++lookups;                                             // only the answer to the latest question counts
				if (!url || !employee || !date) { return showPeriods(null); }
				$.ajax({ url: url, type: 'POST', data: { employee_id: employee, attendance_date: date }, dataType: 'json' })
					.done(function (r) { if (mine === lookups) { showPeriods(r && r.ok && r.split ? r.periods : null); } })
					.fail(function () { if (mine === lookups) { showPeriods(null); } });
			};
			var existing = $form.data('periods');
			if (existing && existing.length) {
				showPeriods(existing);
			} else {
				$('#f_employee, #f_date').on('change', lookup);
				$('#f_date').on('hide', lookup);
			}
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
			var split = $('#period-rows').children().length > 0;          // a split shift: one in / out pair per duty period
			$('#time-fields').toggle(worked && !split).find('input').prop('disabled', split);
			$('#period-fields').toggle(worked && split);
			$('#single-times-help').toggle(!split);
			$('#time-fields').next('div').find('input[name=check_out]').closest('div').toggle(worked && !split);
		}
		$('#f_status').on('change', toggleTimes);
		if ($('#f_status').length) { toggleTimes(); }

		$(document).on('hrm:punch-added', function (e, text) {
			if (String(text).indexOf('manual') !== -1) { window.toastr.info($('#punch-form').data('kept')); }
		});
	});
}(window.jQuery, window.HRM));
