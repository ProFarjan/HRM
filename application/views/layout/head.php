<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta http-equiv="X-UA-Compatible" content="IE=edge">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="csrf-name" content="<?= e($csrf_name) ?>">
	<meta name="csrf-hash" content="<?= e($csrf_hash) ?>">
	<title><?= e($page_title) ?> | <?= e($company_name) ?></title>
	<link rel="stylesheet" href="<?= base_url('theme/bootstrap/css/bootstrap.min.css') ?>">
	<link rel="stylesheet" href="<?= base_url('theme/font-awesome/css/font-awesome.min.css') ?>">
	<link rel="stylesheet" href="<?= base_url('theme/adminlte/css/AdminLTE.min.css') ?>">
	<link rel="stylesheet" href="<?= base_url('theme/adminlte/css/skin-blue.min.css') ?>">
	<link rel="stylesheet" href="<?= base_url('theme/datatables/dataTables.bootstrap.min.css') ?>">
	<link rel="stylesheet" href="<?= base_url('theme/select2/select2.min.css') ?>">
	<link rel="stylesheet" href="<?= base_url('theme/datepicker/bootstrap-datepicker3.min.css') ?>">
	<link rel="stylesheet" href="<?= base_url('theme/icheck/square/blue.css') ?>">
	<link rel="stylesheet" href="<?= base_url('theme/toastr/toastr.min.css') ?>">
	<?php foreach (isset($page_vendor_css) ? (array) $page_vendor_css : array() as $css): ?>
	<link rel="stylesheet" href="<?= base_url('theme/' . $css) ?>">
	<?php endforeach; ?>
	<link rel="stylesheet" href="<?= base_url('theme/css/app.css') ?>?v=<?= filemtime(FCPATH . 'theme/css/app.css') ?>">
</head>
<body class="hold-transition skin-blue sidebar-mini">
<div class="wrapper">
