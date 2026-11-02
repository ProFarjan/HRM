/* Shifts, shift assignments and holidays lists; night-shift hint on the shift form */
(function ($, HRM) {
	'use strict';
	function actions($t, row, tableSel) {
		var html = HRM.editLink($t.data('edit-url'), row.id);
		if ($t.data('delete-url') && !row.employees) {
			html += ' <button type="button" class="btn btn-xs btn-danger hrm-post" data-table="' + tableSel + '" data-url="' + $t.data('delete-url') + '/' + parseInt(row.id, 10)
				+ '" data-confirm="' + $('<div>').text($t.data('confirm')).html() + '"><i class="fa fa-trash"></i></button>';
		}
		return html;
	}
	$(function () {
		var $s = $('#shifts-table');
		if ($s.length) {
			HRM.table('#shifts-table', [
				{ data: 'name' }, { data: 'code' }, { data: 'start' }, { data: 'end' }, { data: 'hours', orderable: false }, { data: 'grace' },
				{ data: 'employees' }, { data: 'status' }, { data: null, orderable: false, render: function (row) { return actions($s, row, '#shifts-table'); } }
			], '#shifts-filter');
		}
		var $a = $('#assign-table');
		if ($a.length) {
			HRM.table('#assign-table', [
				{ data: 'code' },
				{ data: 'name', render: function (name, type, row) { return '<a href="' + $a.data('employee-url') + '/' + parseInt(row.id, 10) + '">' + name + '</a>'; } },
				{ data: 'department' }, { data: 'shift' }, { data: 'since' }
			], '#assign-filter');
		}
		var $h = $('#holidays-table');
		if ($h.length) {
			HRM.table('#holidays-table', [
				{ data: 'date' }, { data: 'name' }, { data: 'optional', orderable: false }, { data: 'fixed', orderable: false }, { data: 'status' },
				{ data: null, orderable: false, render: function (row) { return actions($h, row, '#holidays-table'); } }
			], '#holidays-filter', { order: [[0, 'asc']] });
		}
		// split shift: several duty periods instead of one start / end
		var $split = $('#f_split');
		if ($split.length) {
			var rows = function () { return $('#periods-table .period-row'); };
			var total = function () {
				var minutes = 0;
				rows().each(function (i) {
					$(this).find('.period-no').text(i + 1);
					var a = $(this).find('input[name="seg_start[]"]').val(), b = $(this).find('input[name="seg_end[]"]').val();
					if (a && b) {
						var d = (parseInt(b.slice(0, 2), 10) * 60 + parseInt(b.slice(3, 5), 10)) - (parseInt(a.slice(0, 2), 10) * 60 + parseInt(a.slice(3, 5), 10));
						minutes += (d + 1440) % 1440;
					}
				});
				$('#periods-total').text((minutes / 60).toFixed(2) + ' h');
				$('#period-add').prop('disabled', rows().length >= parseInt($('#period-add').data('max'), 10));
				$('.period-remove').prop('disabled', rows().length <= 2);
			};
			var toggle = function () {
				var on = $split.is(':checked');
				$('#split-fields').toggle(on).find('input').prop('disabled', !on);
				$('#single-fields').toggle(!on).find('input').prop('disabled', on);
				total();
			};
			$split.on('change', toggle);
			$(document).on('input change', '#periods-table input', total);
			$(document).on('click', '.period-remove', function () { if (rows().length > 2) { $(this).closest('tr').remove(); total(); } });
			$('#period-add').on('click', function () {
				var $last = rows().last().clone();
				$last.find('input[name="seg_start[]"]').val($last.find('input[name="seg_end[]"]').val());
				$last.find('input[name="seg_end[]"]').val('');
				$('#periods-table tbody').append($last);
				total();
			});
			toggle();
		}
		function nightHint() {
			var start = $('#f_start').val(), end = $('#f_end').val();
			$('#night-hint').toggle(!!start && !!end && end < start);
		}
		$('#f_start, #f_end').on('change input', nightHint);
		nightHint();
	});
}(window.jQuery, window.HRM));
