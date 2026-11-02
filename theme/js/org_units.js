/* Departments / designations list */
(function ($, HRM) {
	'use strict';
	$(function () {
		var $t = $('#units-table');
		if (!$t.length) { return; }
		HRM.table('#units-table', [
			{ data: 'name' }, { data: 'code' }, { data: 'extra', orderable: false }, { data: 'employees' }, { data: 'status' },
			{ data: null, orderable: false, render: function (row) {
				var html = HRM.editLink($t.data('edit-url'), row.id);
				if ($t.data('delete-url') && row.employees === 0) {
					html += ' <button type="button" class="btn btn-xs btn-danger hrm-post" data-table="#units-table" data-url="' + $t.data('delete-url') + '/' + parseInt(row.id, 10)
						+ '" data-confirm="' + $('<div>').text($t.data('confirm')).html() + '"><i class="fa fa-trash"></i></button>';
				}
				return html;
			} }
		], '#units-filter');
	});
}(window.jQuery, window.HRM));
