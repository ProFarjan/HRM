<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<script src="<?= base_url('theme/jquery/jquery.min.js') ?>"></script>
<script src="<?= base_url('theme/bootstrap/js/bootstrap.min.js') ?>"></script>
<script src="<?= base_url('theme/adminlte/js/adminlte.min.js') ?>"></script>
<script src="<?= base_url('theme/datatables/jquery.dataTables.min.js') ?>"></script>
<script src="<?= base_url('theme/datatables/dataTables.bootstrap.min.js') ?>"></script>
<script src="<?= base_url('theme/select2/select2.full.min.js') ?>"></script>
<script src="<?= base_url('theme/datepicker/bootstrap-datepicker.min.js') ?>"></script>
<script src="<?= base_url('theme/icheck/icheck.min.js') ?>"></script>
<script src="<?= base_url('theme/toastr/toastr.min.js') ?>"></script>
<script>
	window.HRM = {
		csrfName: <?= json_encode($csrf_name) ?>,
		csrfHash: <?= json_encode($csrf_hash) ?>,
		idleMinutes: <?= (int) $idle_minutes ?>,
		loginUrl: <?= json_encode(site_url('auth/login')) ?>,
		logoutUrl: <?= json_encode(site_url('auth/logout')) ?>,
		pingUrl: <?= json_encode(site_url('dashboard/ping')) ?>,
		dateFormat: <?= json_encode(hrm_date_format()) ?>
	};
</script>
<script src="<?= base_url('theme/js/app.js') ?>?v=<?= filemtime(FCPATH . 'theme/js/app.js') ?>"></script>
<?php if (isset($page_js)): foreach ((array) $page_js as $js): ?>
<script src="<?= base_url('theme/js/' . $js . '.js') ?>?v=<?= filemtime(FCPATH . 'theme/js/' . $js . '.js') ?>"></script>
<?php endforeach; endif; ?>
</body>
</html>
