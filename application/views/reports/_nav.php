<?php defined('BASEPATH') OR exit('No direct script access allowed');
// report switcher; $active = the report key
$items = array('daily' => 'rep_daily', 'monthly' => 'rep_monthly', 'inout' => 'rep_inout', 'employee' => 'rep_employee', 'summary' => 'rep_summary',
    'late' => 'rep_late', 'absent' => 'rep_absent', 'overtime' => 'rep_overtime', 'missing' => 'rep_missing');
?>
<ul class="nav nav-pills report-nav no-print" style="margin-bottom:10px">
	<?php foreach ($items as $key => $label): ?>
		<li<?= $active === $key ? ' class="active"' : '' ?>><a href="<?= site_url('reports/' . $key) ?>"><?= e(t($label)) ?></a></li>
	<?php endforeach; ?>
</ul>
