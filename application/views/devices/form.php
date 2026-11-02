<?php defined('BASEPATH') OR exit('No direct script access allowed');
$is_edit = $row !== NULL;
$val = function ($k, $default = '') use ($row) { return $row !== NULL && $row[$k] !== NULL ? $row[$k] : $default; };
?>
<div class="content-wrapper">
	<section class="content-header"><h1><?= e($is_edit ? t('dev_edit') : t('dev_add')) ?></h1></section>
	<section class="content">
		<div class="box box-primary" style="max-width:720px">
			<form id="device-form" class="hrm-ajax-form" method="post" data-redirect="<?= site_url('devices') ?>" action="<?= $is_edit ? site_url('devices/update/' . (int) $row['id']) : site_url('devices/save') ?>">
				<div class="box-body">
					<div class="row">
						<div class="form-group col-sm-7"><label for="f_name"><?= e(t('dev_name')) ?></label><input type="text" id="f_name" name="name" class="form-control" maxlength="100" required value="<?= e($val('name')) ?>"></div>
						<div class="form-group col-sm-5"><label for="f_mode"><?= e(t('dev_mode')) ?></label>
							<select id="f_mode" name="comm_mode" class="form-control"><?php foreach (Devices_model::comm_modes() as $m): ?><option value="<?= e($m) ?>"<?= $val('comm_mode', 'windows_service') === $m ? ' selected' : '' ?>><?= e(t('dev_mode_' . $m)) ?></option><?php endforeach; ?></select></div>
					</div>
					<div class="form-group"><label for="f_serial"><?= e(t('dev_serial')) ?></label><input type="text" id="f_serial" name="serial_number" class="form-control" maxlength="40" value="<?= e($val('serial_number')) ?>"><p class="help-block"><?= e(t('dev_serial_help')) ?></p></div>
					<div class="row">
						<div class="form-group col-sm-5"><label for="f_ip"><?= e(t('dev_ip')) ?></label><input type="text" id="f_ip" name="ip" class="form-control" maxlength="45" value="<?= e($val('ip')) ?>"></div>
						<div class="form-group col-sm-3"><label for="f_port"><?= e(t('dev_port')) ?></label><input type="number" id="f_port" name="port" class="form-control" min="1" max="65535" value="<?= (int) $val('port', 4370) ?>"></div>
						<div class="form-group col-sm-4"><label for="f_protocol"><?= e(t('dev_protocol')) ?></label><select id="f_protocol" name="protocol" class="form-control"><?php foreach (array('TCP', 'UDP') as $p): ?><option<?= $val('protocol', 'TCP') === $p ? ' selected' : '' ?>><?= $p ?></option><?php endforeach; ?></select></div>
					</div>
					<div class="form-group"><label for="f_tz"><?= e(t('dev_timezone')) ?></label><input type="text" id="f_tz" name="timezone" class="form-control" maxlength="64" placeholder="<?= e($user['branch_timezone']) ?>" value="<?= e($val('timezone')) ?>"><p class="help-block"><?= e(t('dev_timezone_help')) ?></p></div>
					<div class="form-group"><label for="f_allowed"><?= e(t('dev_allowed_ips')) ?></label><input type="text" id="f_allowed" name="allowed_ips" class="form-control" maxlength="255" value="<?= e($val('allowed_ips')) ?>"><p class="help-block"><?= e(t('dev_allowed_help')) ?></p></div>
					<div class="form-group" style="max-width:240px"><label for="f_status"><?= e(t('status')) ?></label><select id="f_status" name="status" class="form-control"><?php foreach (array('active' => 'dev_active', 'disabled' => 'dev_disabled') as $sv => $sl): ?><option value="<?= $sv ?>"<?= $val('status', 'active') === $sv ? ' selected' : '' ?>><?= e(t($sl)) ?></option><?php endforeach; ?></select></div>
				</div>
				<div class="box-footer"><a href="<?= site_url('devices') ?>" class="btn btn-default"><?= e(t('back')) ?></a><button type="submit" class="btn btn-primary pull-right" id="device-save"><?= e(t('save')) ?></button></div>
			</form>
		</div>
	</section>
</div>
