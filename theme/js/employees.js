/* Employees list, profile tabs, live gross/net preview of a salary structure (the server recalculates it) */
(function ($, HRM) {
	'use strict';
	$(function () {
		var $t = $('#employees-table');
		if ($t.length) {
			HRM.table('#employees-table', [
				{ data: 'code' },
				{ data: 'name', render: function (name, type, row) { return '<a href="' + $t.data('view-url') + '/' + parseInt(row.id, 10) + '">' + name + '</a>'; } },
				{ data: 'department' }, { data: 'designation' }, { data: 'type' }, { data: 'join_date' }, { data: 'status' }, { data: 'pin' }
			], '#employees-filter');
		}

		function preview() {
			var plus = 0, minus = 0;
			$('.salary-part').each(function () {
				var v = parseFloat($(this).val()) || 0;
				if ($(this).data('kind') === 'minus') { minus += v; } else { plus += v; }
			});
			$('#salary-gross').text(plus.toFixed(2));
			$('#salary-net').text((plus - minus).toFixed(2));
		}
		$(document).on('input change', '.salary-part', preview);

		// keep the open tab across the reload after an action on the profile page
		var key = 'hrm.employee.tab';
		try {
			var saved = window.sessionStorage.getItem(key);
			if (saved && $('a[href="' + saved + '"]').length) { $('a[href="' + saved + '"]').tab('show'); }
		} catch (e) { /* storage blocked */ }
		$('a[data-toggle=tab]').on('shown.bs.tab', function () {
			try { window.sessionStorage.setItem(key, $(this).attr('href')); } catch (e) { /* ignore */ }
		});
	});
}(window.jQuery, window.HRM));
