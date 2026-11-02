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
		function nightHint() {
			var start = $('#f_start').val(), end = $('#f_end').val();
			$('#night-hint').toggle(!!start && !!end && end < start);
		}
		$('#f_start, #f_end').on('change input', nightHint);
		nightHint();
	});
}(window.jQuery, window.HRM));
