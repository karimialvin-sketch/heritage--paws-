<?php $here = basename($_SERVER['SCRIPT_NAME']); ?>
<nav class="subnav">
    <a class="<?= $here === 'dashboard.php' ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/dashboard.php">Overview</a>
    <a class="<?= $here === 'users.php' ? 'active' : '' ?>"     href="<?= BASE_URL ?>/admin/users.php">Users</a>
    <a class="<?= $here === 'dogs.php' ? 'active' : '' ?>"      href="<?= BASE_URL ?>/admin/dogs.php">Dog listings</a>
    <a class="<?= $here === 'breeds.php' ? 'active' : '' ?>"    href="<?= BASE_URL ?>/admin/breeds.php">Breeds</a>
    <a class="<?= $here === 'studs.php' ? 'active' : '' ?>"     href="<?= BASE_URL ?>/admin/studs.php">Stud dogs</a>
    <a class="<?= $here === 'females.php' ? 'active' : '' ?>"   href="<?= BASE_URL ?>/admin/females.php">Female requests</a>
    <a class="<?= $here === 'manage_agreements.php' ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/manage_agreements.php">Agreements</a>
    <a class="<?= $here === 'reports.php' ? 'active' : '' ?>"   href="<?= BASE_URL ?>/admin/reports.php">Reports</a>
</nav>