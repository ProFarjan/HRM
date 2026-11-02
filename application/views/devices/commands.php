<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<div class="content-wrapper">
	<section class="content-header"><h1><?= e(t('dev_commands')) ?> <small><?= e($user['branch_name']) ?></small></h1></section>
	<section class="content">
		<div class="row">
			<div class="col-md-8">
				<div class="box box-primary"><div class="box-body">
					<form id="commands-filter" class="form-inline" style="margin-bottom:10px">
						<select name="status" class="form-control"><option value=""><?= e(t('status')) ?>: <?= e(t('all')) ?></option><?php foreach (array('queued', 'sent', 'done', 'failed') as $s): ?><option value="<?= $s ?>"><?= e(t('cmd_status_' . $s)) ?></option><?php endforeach; ?></select>
					</form>
					<table id="commands-table" class="table table-bordered table-striped" style="width:100%" data-url="<?= site_url('devices/commands_list') ?>">
						<thead><tr><th>#</th><th><?= e(t('dev_name')) ?></th><th><?= e(t('cmd_type')) ?></th><th><?= e(t('cmd_payload')) ?></th><th><?= e(t('status')) ?></th><th><?= e(t('key_created')) ?></th><th><?= e(t('cmd_done')) ?></th><th><?= e(t('cmd_result')) ?></th></tr></thead>
					</table>
				</div></div>
			</div>
			<div class="col-md-4">
				<div class="box box-default">
					<div class="box-header with-border"><h3 class="box-title"><?= e(t('cmd_queue')) ?></h3></div>
					<form id="queue-form" class="hrm-ajax-form" method="post" data-reload="1" action="<?= site_url('devices/queue') ?>">
						<div class="box-body">
							<div class="form-group"><label for="f_device"><?= e(t('dev_name')) ?></label>
								<select id="f_device" name="device_id" class="form-control" required><?php foreach ($devices as $id => $name): ?><option value="<?= (int) $id ?>"><?= e($name) ?></option><?php endforeach; ?></select></div>
							<div class="form-group"><label for="f_type"><?= e(t('cmd_type')) ?></label>
								<select id="f_type" name="command_type" class="form-control"><?php foreach ($types as $k => $label): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach; ?></select></div>
							<div class="form-group" id="employee-group"><label for="f_employee"><?= e(t('emp_name')) ?></label>
								<select id="f_employee" name="employee_id" class="form-control select2" style="width:100%"><option value=""></option><?php foreach ($employees as $id => $name): ?><option value="<?= (int) $id ?>"><?= e($name) ?></option><?php endforeach; ?></select><p class="help-block"><?= e(t('cmd_employee_help')) ?></p></div>
						</div>
						<div class="box-footer"><button type="submit" class="btn btn-primary" id="queue-save"><?= e(t('cmd_queue')) ?></button></div>
					</form>
				</div>
			</div>
		</div>
	</section>
</div>
