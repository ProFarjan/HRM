/* Attendance reports: employee report mode switch, client-side sorting of the summary, the daily trend chart */
(function ($) {
	'use strict';
	$(function () {
		var $mode = $('#r_mode');
		if ($mode.length) {
			var toggle = function () {
				var month = $mode.val() === 'month';
				$('.mode-month').toggle(month).find('input,select').prop('disabled', !month);
				$('.mode-range').toggle(!month).find('input,select').prop('disabled', month);
			};
			$mode.on('change', toggle);
			toggle();
		}
		$('.js-datatable').each(function () {
			$(this).DataTable({ serverSide: false, processing: false, paging: false, info: false, order: [], searching: true });   // app.js makes server side the default
		});
		var $c = $('#trend-chart');
		if ($c.length && window.Chart) {
			var d = $c.data('chart');
			new window.Chart($c[0], {
				type: 'bar',
				data: { labels: d.labels, datasets: [
					{ label: $c.data('present'), data: d.present, backgroundColor: '#00a65a' },
					{ label: $c.data('absent'), data: d.absent, backgroundColor: '#dd4b39' }
				] },
				options: { maintainAspectRatio: false, scales: { xAxes: [{ stacked: true }], yAxes: [{ stacked: true, ticks: { beginAtZero: true, max: 100 } }] } }
			});
		}
	});
}(window.jQuery));
