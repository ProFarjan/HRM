/* Branches list + form */
(function ($, HRM) {
	'use strict';
	$(function () {
		var $t = $('#branches-table');
		if ($t.length) {
			HRM.table('#branches-table', [
				{ data: 'name' }, { data: 'code' }, { data: 'timezone' }, { data: 'weekly_offs' }, { data: 'users' }, { data: 'status' },
				{ data: null, orderable: false, render: function (row) { return HRM.editLink($t.data('edit-url'), row.id); } }
			]);
		}
		function toggleDays() {
			var custom = $('input[name=weekly_offs_mode]:checked').val() === 'custom';
			$('#weekly-days').toggle(custom);
		}
		$(document).on('ifChanged change', 'input[name=weekly_offs_mode]', toggleDays);
		toggleDays();
	});
}(window.jQuery, window.HRM));
