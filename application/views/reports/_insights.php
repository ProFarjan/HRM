<?php defined('BASEPATH') OR exit('No direct script access allowed');
// plain-language findings: $insights = list of array(type, icon, key, args)
$colors = array('success' => 'text-green', 'info' => 'text-aqua', 'warning' => 'text-yellow', 'danger' => 'text-red');
if ($insights): ?>
<div class="box box-default insights" id="insights">
	<div class="box-header with-border"><h3 class="box-title"><i class="fa fa-lightbulb-o"></i> <?= e(t('rep_insights')) ?></h3></div>
	<div class="box-body"><ul class="list-unstyled" style="margin:0">
		<?php foreach ($insights as $i): ?>
			<li class="insight-<?= e($i['type']) ?>" style="padding:3px 0"><i class="fa <?= e($i['icon']) ?> fa-fw <?= $colors[$i['type']] ?>"></i> <?= e(vsprintf(t($i['key']), $i['args'])) ?></li>
		<?php endforeach; ?>
	</ul></div>
</div>
<?php endif; ?>
