/* Users list */
(function ($, HRM) {
	'use strict';
	$(function () {
		var $t = $('#users-table');
		if (!$t.length) { return; }
		HRM.table('#users-table', [
			{ data: 'username' },
			{ data: 'role' },
			{ data: 'status' },
			{ data: 'last_login' },
			{ data: 'created' },
			{ data: null, orderable: false, render: function (row) { return HRM.editLink($t.data('edit-url'), row.id) + ' ' + row.flags; } }
		], '#users-filter');
	});
}(window.jQuery, window.HRM));
