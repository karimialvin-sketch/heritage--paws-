<?php
require_once __DIR__ . '/includes/functions.php';

if (is_logged_in()) {
    redirect(dashboard_path(current_user()['role']));
}

$role   = in_array($_GET['role'] ?? '', ['buyer', 'seller'], true) ? $_GET['role'] : 'buyer';
$errors = [];
$old    = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $role     = in_array($_POST['role'] ?? '', ['buyer', 'seller'], true) ? $_POST['role'] : 'buyer';
    $name     = trim($_POST['full_name'] ?? '');
    $email    = strtolower(trim($_POST['email'] ?? ''));
    $phone    = trim($_POST['phone'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $kennel   = trim($_POST['kennel_name'] ?? '');
    $bio      = trim($_POST['bio'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm'] ?? '';
    $old      = compact('name', 'email', 'phone', 'location', 'kennel', 'bio');

    if ($name === '' || strlen($name) > 120)                     $errors[] = 'Please enter your full name.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))              $errors[] = 'Please enter a valid email address.';
    if (!preg_match('/^[0-9+\s-]{9,20}$/', $phone))              $errors[] = 'Please enter a valid phone number.';
    if ($location === '')                                        $errors[] = 'Please enter your location.';
    if (strlen($password) < 8)                                   $errors[] = 'Password must be at least 8 characters.';
    if ($password !== $confirm)                                  $errors[] = 'Passwords do not match.';
    if ($role === 'seller' && $kennel === '')                    $errors[] = 'Sellers must provide a kennel or business name.';

    if (!$errors) {
        $st = $pdo->prepare('SELECT 1 FROM users WHERE email = ?');
        $st->execute([$email]);
        if ($st->fetch()) {
            $errors[] = 'An account with that email already exists.';
        }
    }

    if (!$errors) {
        // Sellers need admin approval before they can list; buyers are active right away.
        $status = $role === 'seller' ? 'pending' : 'active';
        $st = $pdo->prepare(
            'INSERT INTO users (full_name, email, phone, password_hash, role, location, kennel_name, bio, status)
             VALUES (?,?,?,?,?,?,?,?,?)'
        );
        $st->execute([
            $name, $email, $phone, password_hash($password, PASSWORD_DEFAULT), $role,
            $location, $role === 'seller' ? $kennel : null, $role === 'seller' ? $bio : null, $status,
        ]);
        if ($role === 'seller') {
            flash('success', 'Registration received! An admin will review and approve your seller account shortly.');
        } else {
            flash('success', 'Account created. You can now log in.');
        }
        redirect('/login.php');
    }
}

$pageTitle = 'Register';
require_once __DIR__ . '/includes/header.php';
?>
<div class="form-card">
    <div class="logo-full" role="img" aria-label="Heritage Paws logo"></div>
    <h2 class="center">Create your account</h2>

    <div class="tabs">
               <a class="<?= $role === 'buyer' ? 'active' : '' ?>"  href="?role=buyer">I want to buy / find a stud</a>
        <a class="<?= $role === 'seller' ? 'active' : '' ?>" href="?role=seller">I want to sell / offer stud service</a></div>

    <?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>

    <form method="post" id="registerForm" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="role" value="<?= e($role) ?>">

        <label>Full name
            <input type="text" name="full_name" required value="<?= e($old['name'] ?? '') ?>">
        </label>
        <label>Email
            <input type="email" name="email" required value="<?= e($old['email'] ?? '') ?>">
        </label>
        <label>Phone number
            <input type="tel" name="phone" required placeholder="07XX XXX XXX" value="<?= e($old['phone'] ?? '') ?>">
        </label>
        <label>Location (town / county)
            <input type="text" name="location" required value="<?= e($old['location'] ?? '') ?>">
        </label>

        <?php if ($role === 'seller'): ?>
            <label>Kennel / business name
                <input type="text" name="kennel_name" required value="<?= e($old['kennel'] ?? '') ?>">
            </label>
            <label>About you and your dogs
                <textarea name="bio" rows="3"><?= e($old['bio'] ?? '') ?></textarea>
            </label>
            <p class="notice">Your phone number is shown publicly on your dog listings so buyers can call or WhatsApp you directly. Use a number that is on WhatsApp.</p>
        <?php endif; ?>

        <label>Password
            <input type="password" name="password" required minlength="8">
        </label>
        <label>Confirm password
            <input type="password" name="confirm" required minlength="8">
        </label>

        <button class="btn btn-primary btn-block" type="submit">Create <?= e($role) ?> account</button>
    </form>
    <p class="muted center">Already registered? <a href="<?= BASE_URL ?>/login.php">Log in</a></p>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>