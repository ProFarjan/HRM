<?php defined('BASEPATH') OR exit('No direct script access allowed');
// summary cards: $cards = list of array(label key, value, colour)
?>
<div class="row report-cards">
	<?php foreach ($cards as $c): ?>
	<div class="col-lg-2 col-md-3 col-sm-4 col-xs-6">
		<div class="small-box bg-<?= e($c[2]) ?>" style="margin-bottom:10px"><div class="inner" style="padding:6px 10px"><h3 style="font-size:24px;margin:0"><?= e($c[1]) ?></h3><p style="margin:0"><?= e(t($c[0])) ?></p></div></div>
	</div>
	<?php endforeach; ?>
</div>
