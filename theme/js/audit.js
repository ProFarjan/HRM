/* Audit log list + detail modal (values are inserted as text, never as HTML) */
(function ($, HRM) {
	'use strict';
	function show(value) {
		if (value === undefined) { return ''; }
		return typeof value === 'object' && value !== null ? JSON.stringify(value) : String(value);
	}
	$(function () {
		var $t = $('#audit-table');
		if (!$t.length) { return; }
		HRM.table('#audit-table', [
			{ data: 'when' }, { data: 'user' }, { data: 'module' }, { data: 'action' }, { data: 'record_id' }, { data: 'ip' },
			{ data: null, orderable: false, render: function (row) {
				return '<button type="button" class="btn btn-xs btn-default audit-view" data-id="' + parseInt(row.id, 10) + '"><i class="fa fa-search"></i></button>';
			} }
		], '#audit-filter', { order: [[0, 'desc']] });

		$t.on('click', '.audit-view', function () {
			$.getJSON($t.data('view-url') + '/' + $(this).data('id'), function (d) {
				$('#audit-meta').text(d.when + ' - ' + (d.user || '-') + ' - ' + d.module + ' / ' + d.action + ' #' + (d.record_id || '') + (d.ip ? ' - ' + d.ip : ''));
				var oldV = d.old || {}, newV = d.new || {}, keys = {}, $body = $('#audit-diff tbody').empty();
				$.each(oldV, function (k) { keys[k] = 1; });
				$.each(newV, function (k) { keys[k] = 1; });
				$.each(Object.keys(keys).sort(), function (i, k) {
					var a = show(oldV[k]), b = show(newV[k]);
					var $tr = $('<tr>').toggleClass('warning', a !== b);
					$tr.append($('<th>').text(k), $('<td>').text(a), $('<td>').text(b));
					$body.append($tr);
				});
				$('#audit-modal').modal('show');
			});
		});
	});
}(window.jQuery, window.HRM));
