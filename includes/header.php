<?php
/**
 * Shared page header: <head>, Bootstrap 5, navbar and the opening container.
 *
 * Set $page_title before including this file.
 * Set $page_theme = 'admin'|'student'|'public' to force a theme.
 */

require_once __DIR__ . '/functions.php';

$page_title = isset($page_title) ? $page_title : 'Online Examination System';
$theme = page_theme();
$bodyClass = 'theme-' . $theme;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title><?php echo e($page_title); ?> &middot; Online Examination System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?php echo url('assets/css/style.css'); ?>" rel="stylesheet">
</head>
<body class="<?php echo e($bodyClass); ?>">
<?php require __DIR__ . '/navbar.php'; ?>

<main class="container py-4">
<?php foreach (get_flash() as $flash): ?>
    <div class="alert alert-<?php echo e($flash['type']); ?> alert-dismissible fade show" role="alert">
        <?php echo e($flash['message']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endforeach; ?>
