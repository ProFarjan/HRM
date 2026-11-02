<?php defined('BASEPATH') OR exit('No direct script access allowed');
$is_edit = $row !== NULL;
$val = function ($k, $default = '') use ($row) { return $row !== NULL && $row[$k] !== NULL ? $row[$k] : $default; };
?>
<div class="content-wrapper">
	<section class="content-header"><h1><?= e($is_edit ? t('holidays_edit') : t('holidays_add')) ?></h1></section>
	<section class="content">
		<div class="box box-primary" style="max-width:640px">
			<form id="holiday-form" class="hrm-ajax-form" method="post" data-redirect="<?= site_url('holidays') ?>" action="<?= $is_edit ? site_url('holidays/update/' . (int) $row['id']) : site_url('holidays/save') ?>">
				<div class="box-body">
					<div class="form-group"><label for="f_hname"><?= e(t('holiday_name')) ?></label><input type="text" id="f_hname" name="holiday_name" class="form-control" maxlength="150" required value="<?= e($val('holiday_name')) ?>"></div>
					<div class="form-group" style="max-width:200px"><label for="f_hdate"><?= e(t('holiday_date')) ?></label><input type="text" id="f_hdate" name="holiday_date" class="form-control datepicker" autocomplete="off" required value="<?= e(show_date($val('holiday_date'))) ?>"></div>
					<div class="checkbox"><label><input type="checkbox" name="is_fixed_date" value="1"<?= (int) $val('is_fixed_date', 0) ? ' checked' : '' ?>> <?= e(t('holiday_fixed')) ?></label></div>
					<div class="checkbox"><label><input type="checkbox" name="is_optional" value="1"<?= (int) $val('is_optional', 0) ? ' checked' : '' ?>> <?= e(t('holiday_optional')) ?></label><p class="help-block"><?= e(t('holiday_optional_help')) ?></p></div>
					<div class="form-group"><label for="f_hdesc"><?= e(t('org_description')) ?></label><input type="text" id="f_hdesc" name="description" class="form-control" maxlength="255" value="<?= e($val('description')) ?>"></div>
					<div class="form-group" style="max-width:240px"><label for="f_hstatus"><?= e(t('status')) ?></label><select id="f_hstatus" name="status" class="form-control"><?php foreach (array('Active' => 'status_active', 'Inactive' => 'status_inactive') as $sv => $sl): ?><option value="<?= $sv ?>"<?= $val('status', 'Active') === $sv ? ' selected' : '' ?>><?= e(t($sl)) ?></option><?php endforeach; ?></select></div>
				</div>
				<div class="box-footer"><a href="<?= site_url('holidays') ?>" class="btn btn-default"><?= e(t('back')) ?></a><button type="submit" class="btn btn-primary pull-right" id="holiday-save"><?= e(t('save')) ?></button></div>
			</form>
		</div>
	</section>
</div>
