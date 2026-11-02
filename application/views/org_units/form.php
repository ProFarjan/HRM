<?php defined('BASEPATH') OR exit('No direct script access allowed');
$plural = $unit . 's';
$is_edit = $row !== NULL;
$val = function ($k, $default = '') use ($row) { return $row !== NULL && $row[$k] !== NULL ? $row[$k] : $default; };
?>
<div class="content-wrapper">
	<section class="content-header"><h1><?= e(t($plural . ($is_edit ? '_edit' : '_add'))) ?><?php if ($is_edit): ?> <small><?= e($row[$unit . '_name']) ?></small><?php endif; ?></h1></section>
	<section class="content">
		<div class="box box-primary" style="max-width:760px">
			<form id="unit-form" class="hrm-ajax-form" method="post" data-redirect="<?= site_url($plural) ?>"
				action="<?= $is_edit ? site_url($plural . '/update/' . (int) $row['id']) : site_url($plural . '/save') ?>">
				<div class="box-body">
					<div class="row">
						<div class="form-group col-sm-8"><label for="f_name"><?= e(t('org_name_' . $unit)) ?></label>
							<input type="text" id="f_name" name="<?= $unit ?>_name" class="form-control" maxlength="120" required value="<?= e($val($unit . '_name')) ?>"></div>
						<div class="form-group col-sm-4"><label for="f_code"><?= e(t('org_code')) ?></label>
							<input type="text" id="f_code" name="<?= $unit ?>_code" class="form-control" maxlength="20" required value="<?= e($val($unit . '_code')) ?>"></div>
					</div>
					<?php if ($unit === 'department'): ?>
					<div class="form-group"><label for="f_head"><?= e(t('org_head')) ?></label>
						<select id="f_head" name="head_employee_id" class="form-control select2" style="width:100%">
							<option value=""><?= e(t('org_none')) ?></option>
							<?php foreach ($heads as $hid => $hname): ?>
								<option value="<?= (int) $hid ?>"<?= (int) $val('head_employee_id', 0) === (int) $hid ? ' selected' : '' ?>><?= e($hname) ?></option>
							<?php endforeach; ?>
						</select></div>
					<?php else: ?>
					<div class="form-group" style="max-width:200px"><label for="f_level"><?= e(t('org_level')) ?></label>
						<input type="number" id="f_level" name="level" class="form-control" min="0" max="999" value="<?= (int) $val('level', 0) ?>"></div>
					<?php endif; ?>
					<div class="form-group"><label for="f_description"><?= e(t('org_description')) ?></label>
						<input type="text" id="f_description" name="description" class="form-control" maxlength="255" value="<?= e($val('description')) ?>"></div>
					<div class="form-group" style="max-width:240px"><label for="f_status"><?= e(t('status')) ?></label>
						<select id="f_status" name="status" class="form-control">
							<?php foreach (array('Active' => 'status_active', 'Inactive' => 'status_inactive') as $sv => $sl): ?>
								<option value="<?= $sv ?>"<?= $val('status', 'Active') === $sv ? ' selected' : '' ?>><?= e(t($sl)) ?></option>
							<?php endforeach; ?>
						</select></div>
				</div>
				<div class="box-footer">
					<a href="<?= site_url($plural) ?>" class="btn btn-default"><?= e(t('back')) ?></a>
					<button type="submit" class="btn btn-primary pull-right" id="unit-save"><?= e(t('save')) ?></button>
				</div>
			</form>
		</div>
	</section>
</div>
