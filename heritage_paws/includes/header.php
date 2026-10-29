<?php
require_once __DIR__ . '/functions.php';
$user      = current_user();
$pageTitle = $pageTitle ?? 'Find your companion';
track_visit($trackDogId ?? null);   // logs this page view for the admin Reports

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> | <?= SITE_NAME ?></title>
    <link rel="icon" type="image/x-icon" href="<?= BASE_URL ?>/assets/img/heritage paws black png.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= BASE_URL ?>/assets/img/heritage paws black png.png">
    <link rel="apple-touch-icon" href="<?= BASE_URL ?>/assets/img/heritage paws black png.png">
    <meta name="theme-color" content="#4a2e16">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body>
<header class="site-header">
    <div class="container nav">
        <a class="brand" href="<?= BASE_URL ?>/index.php">
            <span class="logo-paw" role="img" aria-label="Heritage paws black png.png"></span>
            <span class="brand-text">Heritage Paws</span>
        </a>
        <button class="nav-toggle" id="navToggle" aria-label="Menu">&#9776;</button>
        <nav id="mainNav">
            <a href="<?= BASE_URL ?>/index.php">Browse Dogs</a>
             <a href="<?= BASE_URL ?>/studs.php">Stud Services</a>
            <a href="<?= BASE_URL ?>/females.php">Mating Requests</a>

            <?php if ($user): ?>
                <a href="<?= BASE_URL . dashboard_path($user['role']) ?>">Dashboard</a>
                <span class="nav-user">Hi, <?= e(explode(' ', $user['name'])[0]) ?> (<?= e($user['role']) ?>)</span>
                <a class="btn btn-outline btn-sm" href="<?= BASE_URL ?>/logout.php">Logout</a>
            <?php else: ?>
                <a href="<?= BASE_URL ?>/login.php">Login</a>
                <a class="btn btn-primary btn-sm" href="<?= BASE_URL ?>/register.php">Register</a>
            <?php endif; ?>
        </nav>
    </div>
</header>
<main class="container main-content">
<?php show_flash(); ?>