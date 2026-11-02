/*
 * Roster grid: rows = employees, columns = days. Each cell is a select (empty = normal rules, Off, or a shift); the grey
 * text under it says what applies now (from the server's Shift_calculator plan). Save posts only the changed cells.
 * All names come as JSON data and are inserted with .text() (never as HTML).
 */
(function ($, HRM) {
	'use strict';
	$(function () {
		var $form = $('#roster-filter'), $grid = $('#roster-grid');
		if (!$form.length) { return; }
		var $options = $('#roster-shift-options');

		function load() {
			$.ajax({ url: $form.data('url'), type: 'POST', data: $form.serialize(), dataType: 'json' }).done(render)
				.fail(function (xhr) { if (xhr.status === 422) { HRM.ok(xhr.responseText); } });
		}

		function render(data) {
			var $head = $('<tr>').append($('<th>').text(''));
			$.each(data.days, function (i, d) {
				$head.append($('<th class="text-center">').append($('<div>').text(d.dow), $('<small>').text(d.label)));
			});
			$grid.find('thead').empty().append($head);
			var $body = $grid.find('tbody').empty();
			$.each(data.rows, function (i, row) {
				var $tr = $('<tr>').append($('<th style="white-space:nowrap">').append($('<div>').text(row.name), $('<small class="text-muted">').text(row.code)));
				$.each(data.days, function (j, d) {
					var cell = row.cells[d.date];
					var $sel = $options.clone().removeAttr('id').show().addClass('form-control input-sm roster-cell no-icheck')
						.attr({ 'data-employee': row.id, 'data-date': d.date, 'data-original': cell.value });
					$sel.val(cell.value);
					var $td = $('<td>').addClass('roster-' + cell.type).toggleClass('roster-not-employed', !cell.employed)
						.append($sel, $('<small class="text-muted roster-applies">').text(cell.applies));
					$tr.append($td);
				});
				$body.append($tr);
			});
		}

		$grid.on('change', '.roster-cell', function () {
			$(this).closest('td').toggleClass('roster-changed', String($(this).val()) !== String($(this).data('original')));
		});

		$('#roster-save').on('click', function () {
			var cells = [];
			$grid.find('.roster-cell').each(function () {
				var v = String($(this).val() || '');
				if (v !== String($(this).data('original'))) { cells.push($(this).data('employee') + '|' + $(this).data('date') + '|' + v); }
			});
			if (!cells.length) { return; }
			var $btn = $(this).prop('disabled', true);
			$.ajax({ url: $btn.data('url'), type: 'POST', data: { cells: cells }, dataType: 'text' }).done(function (text) {
				if (HRM.ok(text)) { window.toastr.success(HRM.successText(text, $btn.data('message'))); load(); }
			}).always(function () { $btn.prop('disabled', false); });
		});

		$form.on('submit', function (e) { e.preventDefault(); load(); });
		$(document).on('hrm:roster-reload', load);
		load();
	});
}(window.jQuery, window.HRM));
