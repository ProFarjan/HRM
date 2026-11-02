<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<div class="content-wrapper">
	<section class="content-header"><h1><?= e(t('att_unmatched_page')) ?> <small><?= e($user['branch_name']) ?></small></h1></section>
	<section class="content">
		<div class="callout callout-info"><p><?= e(t('att_unmatched_help')) ?></p></div>
		<div class="box box-warning">
			<div class="box-header with-border">
				<form class="form-inline" id="unmatched-filter">
					<input type="text" name="from_date" class="form-control input-sm datepicker" value="<?= e($from_date) ?>" style="width:105px" autocomplete="off">
					<input type="text" name="to_date" class="form-control input-sm datepicker" value="<?= e($to_date) ?>" style="width:105px" autocomplete="off">
					<button type="submit" class="btn btn-default btn-sm"><i class="fa fa-filter"></i> <?= e(t('filter')) ?></button>
				</form>
			</div>
			<div class="box-body">
				<table id="unmatched-table" class="table table-bordered table-striped" style="width:100%" data-url="<?= site_url('attendance/unmatched_list') ?>" data-can-link="<?= $employees ? '1' : '' ?>">
					<thead><tr><th><?= e(t('emp_device_pin')) ?></th><th><?= e(t('att_punch_count')) ?></th><th><?= e(t('att_first_punch')) ?></th><th><?= e(t('att_last_punch')) ?></th><th><?= e(t('att_device')) ?></th><th></th></tr></thead>
				</table>
			</div>
		</div>
		<?php if ($employees): ?>
		<div class="modal fade" id="link-modal" tabindex="-1" role="dialog"><div class="modal-dialog"><div class="modal-content">
			<form id="link-form" class="hrm-ajax-form" method="post" data-reload="1" action="<?= site_url('attendance/link_pin') ?>">
				<div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button><h4 class="modal-title"><?= e(t('att_link_pin')) ?> <code id="link-pin-label"></code></h4></div>
				<div class="modal-body">
					<input type="hidden" name="device_pin" id="link-pin">
					<div class="form-group"><label for="link-employee"><?= e(t('emp_name')) ?></label>
						<select id="link-employee" name="employee_id" class="form-control" style="width:100%" required><option value=""></option><?php foreach ($employees as $id => $name): ?><option value="<?= (int) $id ?>"><?= e($name) ?></option><?php endforeach; ?></select></div>
					<p class="help-block"><?= e(t('att_link_help')) ?></p>
				</div>
				<div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal"><?= e(t('cancel')) ?></button><button type="submit" class="btn btn-primary" id="link-save"><?= e(t('att_link_pin')) ?></button></div>
			</form>
		</div></div></div>
		<?php endif; ?>
	</section>
</div>
