<?php
require_once __DIR__ . '/includes/functions.php';

if (is_logged_in()) {
    redirect(dashboard_path(current_user()['role']));
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email    = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';

    $st = $pdo->prepare('SELECT * FROM users WHERE email = ?');
    $st->execute([$email]);
    $u = $st->fetch();

    if (!$u || !password_verify($password, $u['password_hash'])) {
        $error = 'Incorrect email or password.';
    } elseif ($u['status'] === 'suspended') {
        $error = 'This account has been suspended. Please contact Heritage Paws support.';
    } elseif ($u['status'] === 'pending') {
        $error = 'Your seller account is still awaiting admin approval.';
    } else {
        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id'   => (int)$u['user_id'],
            'name' => $u['full_name'],
            'role' => $u['role'],
        ];
        redirect(dashboard_path($u['role']));
    }
}

$pageTitle = 'Login';
require_once __DIR__ . '/includes/header.php';
?>
<div class="form-card narrow">
    <div class="logo-full" role="img" aria-label="Heritage Paws logo"></div>
    <h2 class="center">Welcome back</h2>
    <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
    <form method="post">
        <?= csrf_field() ?>
        <label>Email
            <input type="email" name="email" required autofocus>
        </label>
        <label>Password
            <input type="password" name="password" required>
        </label>
        <button class="btn btn-primary btn-block" type="submit">Log in</button>
    </form>
    <p class="muted center">New here? <a href="<?= BASE_URL ?>/register.php">Create an account</a></p>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>