<?php
// Draft (or edit) a stud service agreement between a stud owner and a female owner
require_once __DIR__ . '/../includes/agreement_helpers.php';
require_role('buyer', 'seller');
$user = current_user();
$me   = (int)$user['id'];

/* ---------- Which agreement / which dogs? ---------- */
$editId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$agr    = null;
if ($editId) {
    $agr = agreement_load($pdo, $editId);
    if (!$agr || !agreement_editable($agr, $me) || !$agr['stud_id'] || !$agr['request_id']) {
        flash('error', 'This agreement can no longer be edited.');
        redirect('/owner/my_agreements.php');
    }
    $studId = (int)$agr['stud_id'];
    $reqId  = (int)$agr['request_id'];
} else {
    $studId = (int)($_GET['stud_id'] ?? $_POST['stud_id'] ?? 0);
    $reqId  = (int)($_GET['request_id'] ?? $_POST['request_id'] ?? 0);
}

$getStud = function (int $id) use ($pdo) {
    $q = $pdo->prepare('SELECT s.*, b.breed_name, u.full_name AS owner_name, u.status AS owner_status
                        FROM stud_dogs s JOIN breeds b ON b.breed_id = s.breed_id JOIN users u ON u.user_id = s.owner_id WHERE s.stud_id = ?');
    $q->execute([$id]);
    return $q->fetch() ?: null;
};
$getFemale = function (int $id) use ($pdo) {
    $q = $pdo->prepare('SELECT f.*, b.breed_name, u.full_name AS owner_name, u.status AS owner_status
                        FROM female_requests f JOIN breeds b ON b.breed_id = f.breed_id JOIN users u ON u.user_id = f.owner_id WHERE f.request_id = ?');
    $q->execute([$id]);
    return $q->fetch() ?: null;
};
$stud = $studId ? $getStud($studId) : null;
$fem  = $reqId  ? $getFemale($reqId) : null;

// Am I the owner of the stud, or of the female? (the other dog belongs to the person I am drafting with)
if ($agr) {
    $role = agreement_party($agr, $me);
} elseif ($stud && (int)$stud['owner_id'] !== $me) {
    $role = 'female';
} elseif ($fem && (int)$fem['owner_id'] !== $me) {
    $role = 'stud';
} else {
    $role = null;
}
if (!$role) {
    flash('error', 'To draft an agreement, open a stud dog or a mating request that belongs to someone else and click "Draft agreement".');
    redirect('/owner/my_agreements.php');
}

// My own dogs I can choose from (create mode)
$myDogs = [];
if (!$agr) {
    if ($role === 'female') {
        $q = $pdo->prepare("SELECT f.request_id AS id, f.name, b.breed_name FROM female_requests f JOIN breeds b ON b.breed_id = f.breed_id
                            WHERE f.owner_id = ? AND f.listing_status = 'approved' AND f.request_status <> 'closed' ORDER BY f.name");
    } else {
        $q = $pdo->prepare("SELECT s.stud_id AS id, s.name, b.breed_name FROM stud_dogs s JOIN breeds b ON b.breed_id = s.breed_id
                            WHERE s.owner_id = ? AND s.listing_status = 'approved' ORDER BY s.name");
    }
    $q->execute([$me]);
    $myDogs = $q->fetchAll();
}

// The dog I have chosen
$chosen = 0;
if (!$agr) {
    $ids    = array_map('intval', array_column($myDogs, 'id'));
    $wanted = (int)($_POST['my_dog_id'] ?? ($role === 'female' ? $reqId : $studId));
    $chosen = in_array($wanted, $ids, true) ? $wanted : ($ids[0] ?? 0);
    if ($role === 'female') { $reqId = $chosen; $fem = $chosen ? $getFemale($chosen) : null; }
    else                    { $studId = $chosen; $stud = $chosen ? $getStud($chosen) : null; }
}

$problem = '';
if (!$agr) {
    if (!$myDogs) {
        $problem = $role === 'female'
            ? 'You need an approved female dog (a mating request that is open) before you can draft an agreement.'
            : 'You need an approved stud dog listing before you can draft an agreement.';
    } elseif (!$stud || !$fem) {
        $problem = 'That listing could not be found.';
    } elseif ($stud['listing_status'] !== 'approved' || $fem['listing_status'] !== 'approved') {
        $problem = 'Both dogs must be approved listings.';
    } elseif ((int)$stud['owner_id'] === (int)$fem['owner_id']) {
        $problem = 'An agreement needs two different owners.';
    } elseif ($stud['owner_status'] !== 'active' || $fem['owner_status'] !== 'active') {
        $problem = 'The other owner\'s account is not active.';
    }
}

/* ---------- Form values ---------- */
$today = date('Y-m-d');
$def = [
    'mating_date' => '', 'mating_place' => $stud['location'] ?? '', 'mating_attempts' => 2, 'repeat_service' => 1, 'repeat_months' => 6,
    'fee_type' => 'cash', 'fee_amount' => '', 'fee_terms' => '', 'payment_timing' => 'on_mating', 'health_tests' => 1, 'extra_terms' => '',
];
if ($stud && $fem && !$agr) {
    if ($stud['fee_type'] === 'cash' && (float)$stud['stud_fee'] > 0)       { $def['fee_amount'] = (int)$stud['stud_fee']; }
    elseif ($stud['fee_type'] === 'puppy_pick')                             { $def['fee_type'] = 'puppy_pick'; $def['fee_terms'] = 'First pick of the litter'; }
    elseif ($fem['offer_type'] === 'cash' && (float)$fem['offer_amount'] > 0) { $def['fee_amount'] = (int)$fem['offer_amount']; }
    elseif ($fem['offer_type'] === 'puppy_pick')                            { $def['fee_type'] = 'puppy_pick'; $def['fee_terms'] = 'First pick of the litter'; }
    if ($fem['heat_status'] === 'expected_soon' && $fem['expected_heat'] && $fem['expected_heat'] >= $today) {
        $def['mating_date'] = $fem['expected_heat'];
    }
}
$form = $agr ? array_merge($def, array_intersect_key($agr, $def)) : $def;
if ($form['fee_type'] === 'cash' && $form['fee_amount'] !== '') $form['fee_amount'] = (int)$form['fee_amount'];

/* ---------- Save ---------- */
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $clean = fn(string $k, int $max) => mb_substr(trim(agr_v($_POST[$k] ?? '')), 0, $max);

    $feeType = in_array($_POST['fee_type'] ?? '', ['cash', 'puppy_pick', 'other'], true) ? $_POST['fee_type'] : 'cash';
    $timing  = in_array($_POST['payment_timing'] ?? '', ['before_mating', 'on_mating', 'after_pregnancy'], true) ? $_POST['payment_timing'] : 'on_mating';
    $date    = trim($_POST['mating_date'] ?? '');
    $in = [
        'mating_date'     => $date,
        'mating_place'    => $clean('mating_place', 160),
        'mating_attempts' => (int)($_POST['mating_attempts'] ?? 2),
        'repeat_service'  => isset($_POST['repeat_service']) ? 1 : 0,
        'repeat_months'   => (int)($_POST['repeat_months'] ?? 6),
        'fee_type'        => $feeType,
        'fee_amount'      => $feeType === 'cash' ? (float)($_POST['fee_amount'] ?? 0) : 0,
        'fee_terms'       => $feeType === 'cash' ? '' : $clean('fee_terms', 255),
        'payment_timing'  => $feeType === 'cash' ? $timing : 'on_mating',
        'health_tests'    => isset($_POST['health_tests']) ? 1 : 0,
        'extra_terms'     => $clean('extra_terms', 1500),
    ];
    $form = array_merge($form, $in);

    if ($problem)                                                   $errors[] = $problem;
    if ($in['mating_place'] === '')                                 $errors[] = 'Say where the mating will take place.';
    if ($date !== '') {
        $ok = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
        if (!$ok)                 $errors[] = 'The mating date is not a valid date.';
        elseif ($date < $today)   $errors[] = 'The mating date cannot be in the past.';
    }
    if ($in['mating_attempts'] < 1 || $in['mating_attempts'] > 5)   $errors[] = 'Mating attempts must be between 1 and 5.';
    if ($in['repeat_service'] && ($in['repeat_months'] < 1 || $in['repeat_months'] > 12)) $errors[] = 'The repeat mating period must be between 1 and 12 months.';
    if ($feeType === 'cash' && ($in['fee_amount'] <= 0 || $in['fee_amount'] > 10000000)) $errors[] = 'Enter the stud fee in KES, or choose another type of fee.';
    if ($feeType !== 'cash' && mb_strlen($in['fee_terms']) < 3)     $errors[] = 'Describe what the stud owner will receive (for example: first pick of the litter).';

    if (!$errors) {
        $snap = agreement_snapshot($pdo, (int)$studId, (int)$reqId);
        if (!$snap) {
            $errors[] = 'Could not read the dog details. Please try again.';
        } else {
            $json = json_encode($snap, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            $vals = [$in['mating_date'] ?: null, $in['mating_place'], $in['mating_attempts'], $in['repeat_service'], $in['repeat_months'] ?: 6,
                     $in['fee_type'], $in['fee_amount'], $in['fee_terms'] ?: null, $in['payment_timing'], $in['health_tests'], $in['extra_terms'] ?: null];
            if ($agr) {
                $pdo->prepare("UPDATE stud_agreements SET snapshot = ?, mating_date = ?, mating_place = ?, mating_attempts = ?, repeat_service = ?, repeat_months = ?,
                               fee_type = ?, fee_amount = ?, fee_terms = ?, payment_timing = ?, health_tests = ?, extra_terms = ?
                               WHERE agreement_id = ? AND status = 'pending' AND stud_signed_at IS NULL AND female_signed_at IS NULL")
                    ->execute(array_merge([$json], $vals, [$agr['agreement_id']]));
                flash('success', 'Agreement updated.');
                redirect('/owner/agreement_view.php?id=' . (int)$agr['agreement_id']);
            }

            $dup = $pdo->prepare("SELECT agreement_id FROM stud_agreements WHERE stud_id = ? AND request_id = ? AND status <> 'cancelled' LIMIT 1");
            $dup->execute([$studId, $reqId]);
            if ($existing = $dup->fetchColumn()) {
                flash('warn', 'An agreement for these two dogs already exists. Here it is.');
                redirect('/owner/agreement_view.php?id=' . (int)$existing);
            }

            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO stud_agreements (agreement_no, stud_id, request_id, stud_owner_id, female_owner_id, initiated_by, snapshot,
                               mating_date, mating_place, mating_attempts, repeat_service, repeat_months, fee_type, fee_amount, fee_terms,
                               payment_timing, health_tests, extra_terms, created_at)
                           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute(array_merge(['TMP-' . bin2hex(random_bytes(8)), $studId, $reqId, $stud['owner_id'], $fem['owner_id'], $me, $json], $vals, [agr_now()]));
            $newId = (int)$pdo->lastInsertId();
            $pdo->prepare('UPDATE stud_agreements SET agreement_no = ? WHERE agreement_id = ?')
                ->execute(['HP-SA-' . date('Y') . '-' . str_pad((string)$newId, 4, '0', STR_PAD_LEFT), $newId]);
            $pdo->commit();

            flash('success', 'Agreement drafted. Read it through, sign it, then send it to the other owner to sign.');
            redirect('/owner/agreement_view.php?id=' . $newId);
        }
    }
}

$v = fn(string $k) => e($form[$k] ?? '');
$backUrl  = $agr ? '/owner/agreement_view.php?id=' . (int)$agr['agreement_id'] : ($role === 'female' ? '/studs.php' : '/females.php');
$backText = $agr ? 'Back to the agreement' : ($role === 'female' ? 'Back to stud services' : 'Back to mating requests');
$pageTitle = $agr ? 'Edit agreement' : 'Draft agreement';
require_once __DIR__ . '/../includes/header.php';
?>
<a class="back-link" href="<?= BASE_URL . $backUrl ?>">&larr; <?= e($backText) ?></a>
<div class="form-card agr-form">
    <h2><?= $agr ? 'Edit agreement ' . e($agr['agreement_no']) : 'Draft a stud service agreement' ?></h2>
    <?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>

    <?php if ($problem): ?>
        <?php if (!$errors): ?><div class="alert alert-warn"><?= e($problem) ?></div><?php endif; ?>
        <p><a class="btn btn-outline" href="<?= BASE_URL . ($role === 'female' ? '/owner/female_form.php' : '/seller/stud_form.php') ?>">
            <?= $role === 'female' ? 'Post a female dog' : 'List a stud dog' ?></a></p>
    <?php else: ?>

    <div class="agr-pair">
        <div><span class="muted">Stud dog</span><strong><?= e($stud['name'] ?? '') ?></strong>
            <span class="muted"><?= e($stud['breed_name'] ?? '') ?> &bull; owner: <?= e($stud['owner_name'] ?? '') ?></span></div>
        <div class="agr-x">&times;</div>
        <div><span class="muted">Female dog</span><strong><?= e($fem['name'] ?? '') ?></strong>
            <span class="muted"><?= e($fem['breed_name'] ?? '') ?> &bull; owner: <?= e($fem['owner_name'] ?? '') ?></span></div>
    </div>

    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$editId ?>">
        <?php if (!$agr && $role === 'female'): ?><input type="hidden" name="stud_id" value="<?= (int)$studId ?>"><?php endif; ?>
        <?php if (!$agr && $role === 'stud'): ?><input type="hidden" name="request_id" value="<?= (int)$reqId ?>"><?php endif; ?>

        <?php if (!$agr): ?>
        <label><?= $role === 'female' ? 'Which of your female dogs is this for?' : 'Which of your stud dogs is this for?' ?>
            <select name="my_dog_id" onchange="location.href='<?= BASE_URL ?>/owner/agreement_new.php?<?= $role === 'female' ? 'stud_id=' . (int)$studId . '&request_id=' : 'request_id=' . (int)$reqId . '&stud_id=' ?>' + this.value">
                <?php foreach ($myDogs as $d): ?>
                    <option value="<?= (int)$d['id'] ?>" <?= (int)$d['id'] === $chosen ? 'selected' : '' ?>><?= e($d['name']) ?> (<?= e($d['breed_name']) ?>)</option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php endif; ?>

        <h3 class="agr-h">The mating</h3>
        <div class="two-col">
            <label>Mating date (optional) <input type="date" name="mating_date" min="<?= e($today) ?>" value="<?= $v('mating_date') ?>"></label>
            <label>Where will the mating take place? <input type="text" name="mating_place" maxlength="160" required value="<?= $v('mating_place') ?>"></label>
        </div>
        <div class="two-col">
            <label>Mating attempts allowed <input type="number" name="mating_attempts" min="1" max="5" required value="<?= $v('mating_attempts') ?>"></label>
            <label class="check check-pad"><input type="checkbox" name="health_tests" <?= !empty($form['health_tests']) ? 'checked' : '' ?>> Both dogs must show vet test results first (brucellosis etc.)</label>
        </div>
        <div class="two-col">
            <label class="check check-pad"><input type="checkbox" name="repeat_service" id="repeatService" <?= !empty($form['repeat_service']) ? 'checked' : '' ?>> One free repeat mating if she does not conceive</label>
            <label id="repeatWrap">Repeat mating must be requested within (months)
                <input type="number" name="repeat_months" min="1" max="12" value="<?= $v('repeat_months') ?>"></label>
        </div>

        <h3 class="agr-h">Stud fee</h3>
        <div class="two-col">
            <label>Type of fee
                <select name="fee_type" id="feeType">
                    <option value="cash"       <?= $form['fee_type'] === 'cash' ? 'selected' : '' ?>>Cash (KES)</option>
                    <option value="puppy_pick" <?= $form['fee_type'] === 'puppy_pick' ? 'selected' : '' ?>>Pick of the litter</option>
                    <option value="other"      <?= $form['fee_type'] === 'other' ? 'selected' : '' ?>>Other arrangement</option>
                </select>
            </label>
            <label id="feeAmountWrap">Stud fee (KES) <input type="number" name="fee_amount" min="0" step="1" value="<?= $v('fee_amount') ?>"></label>
            <label id="feeTermsWrap">What will the stud owner receive?
                <input type="text" name="fee_terms" maxlength="255" placeholder="e.g. First pick of the litter" value="<?= $v('fee_terms') ?>"></label>
        </div>
        <label id="timingWrap">When is the cash fee paid?
            <select name="payment_timing">
                <option value="before_mating"   <?= $form['payment_timing'] === 'before_mating' ? 'selected' : '' ?>>Before the first mating</option>
                <option value="on_mating"       <?= $form['payment_timing'] === 'on_mating' ? 'selected' : '' ?>>On the day of the first mating</option>
                <option value="after_pregnancy" <?= $form['payment_timing'] === 'after_pregnancy' ? 'selected' : '' ?>>After a vet confirms pregnancy</option>
            </select>
        </label>

        <label>Additional terms (optional)
            <textarea name="extra_terms" rows="4" maxlength="1500" placeholder="Anything else you agree, e.g. boarding charges, who transports the dog, how many puppies..."><?= $v('extra_terms') ?></textarea>
        </label>

        <p class="notice">The standard clauses (health, care, puppies, cancellation, disputes under Kenyan law) are added automatically. After you save, the other owner will see the agreement under <strong>My agreements</strong> and can sign it.</p>
        <button class="btn btn-primary btn-block" type="submit"><?= $agr ? 'Save changes' : 'Create agreement' ?></button>
    </form>
    <?php endif; ?>
</div>
<script>
// Show only the fee fields that apply
(function () {
    var type = document.getElementById('feeType'); if (!type) return;
    var amt = document.getElementById('feeAmountWrap'), terms = document.getElementById('feeTermsWrap'), timing = document.getElementById('timingWrap');
    var rep = document.getElementById('repeatService'), repWrap = document.getElementById('repeatWrap');
    function sync() {
        var cash = type.value === 'cash';
        amt.style.display = cash ? '' : 'none'; timing.style.display = cash ? '' : 'none'; terms.style.display = cash ? 'none' : '';
        repWrap.style.display = rep.checked ? '' : 'none';
    }
    type.addEventListener('change', sync); rep.addEventListener('change', sync); sync();
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
