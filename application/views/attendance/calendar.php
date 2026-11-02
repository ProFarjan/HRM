<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<div class="content-wrapper">
	<section class="content-header"><h1><?= e(t('att_calendar')) ?> <small><?= e($emp['employee_code'] . ' - ' . trim($emp['first_name'] . ' ' . $emp['last_name'])) ?></small></h1></section>
	<section class="content">
		<div class="box box-primary"><div class="box-body">
			<div id="attendance-calendar" data-url="<?= site_url('attendance/calendar_events/' . (int) $emp['id']) ?>"></div>
		</div></div>
	</section>
</div>
