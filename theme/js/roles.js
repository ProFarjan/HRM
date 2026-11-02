/* Roles list + permission grid */
(function ($, HRM) {
	'use strict';
	$(function () {
		var $t = $('#roles-table');
		if ($t.length) {
			HRM.table('#roles-table', [
				{ data: 'role_name' },
				{ data: 'users' },
				{ data: 'permissions' },
				{ data: 'created' },
				{ data: null, orderable: false, render: function (row) {
					var html = HRM.editLink($t.data('edit-url'), row.id);
					if (!row.is_system && row.users === 0) {
						html += ' <button type="button" class="btn btn-xs btn-danger hrm-post" data-table="#roles-table" data-url="' + $t.data('delete-url') + '/' + parseInt(row.id, 10)
							+ '" data-confirm="' + $('<div>').text($t.data('confirm')).html() + '"><i class="fa fa-trash"></i></button>';
					}
					return html;
				} }
			]);
		}
		// "select all" per group: iCheck fires ifChanged, plain checkboxes fire change
		$(document).on('ifChanged change', '.group-toggle', function () {
			var on = $(this).is(':checked');
			$(this).closest('.permission-group').find('input.perm:not(:disabled)').each(function () {
				if ($.fn.iCheck && $(this).hasClass('icheck-done')) { $(this).iCheck(on ? 'check' : 'uncheck'); } else { $(this).prop('checked', on); }
			});
		});
	});
}(window.jQuery, window.HRM));
