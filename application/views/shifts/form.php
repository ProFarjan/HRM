<?php defined('BASEPATH') OR exit('No direct script access allowed');
$is_edit = $row !== NULL;
$val = function ($k, $default = '') use ($row) { return $row !== NULL && $row[$k] !== NULL ? $row[$k] : $default; };
$is_split = $is_edit && (int) $row['is_split'] === 1;
$periods = $is_split ? $row['segments'] : array(array('start_time' => '10:00:00', 'end_time' => '14:00:00'), array('start_time' => '18:00:00', 'end_time' => '22:00:00'));
?>
<div class="content-wrapper">
	<section class="content-header"><h1><?= e($is_edit ? t('shifts_edit') : t('shifts_add')) ?><?php if ($is_edit): ?> <small><?= e($row['shift_name']) ?></small><?php endif; ?></h1></section>
	<section class="content">
		<div class="box box-primary" style="max-width:760px">
			<form id="shift-form" class="hrm-ajax-form" method="post" data-redirect="<?= site_url('shifts') ?>" action="<?= $is_edit ? site_url('shifts/update/' . (int) $row['id']) : site_url('shifts/save') ?>">
				<div class="box-body">
					<div class="row">
						<div class="form-group col-sm-8"><label for="f_shift_name"><?= e(t('shift_name')) ?></label><input type="text" id="f_shift_name" name="shift_name" class="form-control" maxlength="100" required value="<?= e($val('shift_name')) ?>"></div>
						<div class="form-group col-sm-4"><label for="f_shift_code"><?= e(t('org_code')) ?></label><input type="text" id="f_shift_code" name="shift_code" class="form-control" maxlength="20" required value="<?= e($val('shift_code')) ?>"></div>
					</div>

					<div class="checkbox"><label><input type="checkbox" id="f_split" name="is_split" value="1" class="no-icheck"<?= $is_split ? ' checked' : '' ?>> <strong><?= e(t('shift_split_label')) ?></strong></label>
						<p class="help-block"><?= e(t('shift_split_help')) ?></p></div>

					<div id="single-fields">
						<div class="row">
							<div class="form-group col-sm-4"><label for="f_start"><?= e(t('shift_start')) ?></label><input type="time" id="f_start" name="start_time" class="form-control" required value="<?= e(substr($val('start_time', '09:00'), 0, 5)) ?>"></div>
							<div class="form-group col-sm-4"><label for="f_end"><?= e(t('shift_end')) ?></label><input type="time" id="f_end" name="end_time" class="form-control" required value="<?= e(substr($val('end_time', '18:00'), 0, 5)) ?>"></div>
							<div class="form-group col-sm-4"><label for="f_break"><?= e(t('shift_break')) ?></label><input type="number" id="f_break" name="break_duration" class="form-control" min="0" max="720" value="<?= (int) $val('break_duration', 60) ?>"></div>
						</div>
						<p class="help-block" id="night-hint" style="display:none"><i class="fa fa-moon-o"></i> <?= e(t('shift_night_help')) ?></p>
						<div class="form-group" style="max-width:240px"><label for="f_hours"><?= e(t('shift_hours')) ?></label><input type="number" id="f_hours" name="working_hours" class="form-control" min="0" max="24" step="0.01" value="<?= e($val('working_hours', '0')) ?>"></div>
					</div>

					<div id="split-fields" style="display:none">
						<label><?= e(t('shift_periods')) ?></label>
						<table class="table table-condensed" id="periods-table" style="max-width:520px">
							<thead><tr><th style="width:30px">#</th><th><?= e(t('shift_start')) ?></th><th><?= e(t('shift_end')) ?></th><th style="width:40px"></th></tr></thead>
							<tbody>
							<?php foreach ($periods as $i => $p): ?>
								<tr class="period-row"><td class="period-no"><?= $i + 1 ?></td>
									<td><input type="time" name="seg_start[]" class="form-control input-sm" value="<?= e(substr($p['start_time'], 0, 5)) ?>"></td>
									<td><input type="time" name="seg_end[]" class="form-control input-sm" value="<?= e(substr($p['end_time'], 0, 5)) ?>"></td>
									<td><button type="button" class="btn btn-xs btn-danger period-remove" title="<?= e(t('shift_period_remove')) ?>"><i class="fa fa-times"></i></button></td></tr>
							<?php endforeach; ?>
							</tbody>
						</table>
						<p><button type="button" class="btn btn-default btn-sm" id="period-add" data-max="<?= (int) Shift_calculator::MAX_PERIODS ?>"><i class="fa fa-plus"></i> <?= e(t('shift_period_add')) ?></button>
							<span class="text-muted" id="periods-total" style="margin-left:10px"></span></p>
						<p class="help-block"><?= e(t('shift_periods_help')) ?></p>
					</div>

					<div class="row">
						<div class="form-group col-sm-6"><label for="f_grace"><?= e(t('shift_grace')) ?></label><input type="number" id="f_grace" name="grace_time" class="form-control" min="0" max="240" value="<?= (int) $val('grace_time', 10) ?>"></div>
						<div class="form-group col-sm-6"><label for="f_allowance"><?= e(t('shift_allowance')) ?></label><input type="number" id="f_allowance" name="shift_allowance" class="form-control" min="0" step="0.01" value="<?= e($val('shift_allowance', '0')) ?>"></div>
					</div>
					<div class="form-group" style="max-width:240px"><label for="f_status"><?= e(t('status')) ?></label>
						<select id="f_status" name="status" class="form-control"><?php foreach (array('Active' => 'status_active', 'Inactive' => 'status_inactive') as $sv => $sl): ?><option value="<?= $sv ?>"<?= $val('status', 'Active') === $sv ? ' selected' : '' ?>><?= e(t($sl)) ?></option><?php endforeach; ?></select></div>
				</div>
				<div class="box-footer"><a href="<?= site_url('shifts') ?>" class="btn btn-default"><?= e(t('back')) ?></a><button type="submit" class="btn btn-primary pull-right" id="shift-save"><?= e(t('save')) ?></button></div>
			</form>
		</div>
	</section>
</div>
