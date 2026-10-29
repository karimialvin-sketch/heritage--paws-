<?php
/**
 * Stud service agreements: shared helpers + the PDF builder.
 * The same clause text (agreement_sections) is used on the web page and in the PDF,
 * so what people read and sign on screen is exactly what is printed.
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/pdf.php';

/* ---------- Dates (always Nairobi time, whatever the server's PHP timezone is) ---------- */
function agr_now(): string {
    return (new DateTime('now', new DateTimeZone('Africa/Nairobi')))->format('Y-m-d H:i:s');
}

function agr_dt(?string $dt): string {
    return $dt ? date('d M Y, H:i', strtotime($dt)) . ' EAT' : '';
}

function agr_d(?string $d): string {
    return $d ? date('d M Y', strtotime($d)) : '';
}

/* ---------- Loading and access ---------- */
function agreement_load(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare('SELECT * FROM stud_agreements WHERE agreement_id = ?');
    $st->execute([$id]);
    $a = $st->fetch();
    if (!$a) return null;
    $a['snap'] = json_decode((string)$a['snapshot'], true) ?: [];
    return $a;
}

function agreement_party(array $a, int $userId): ?string {
    if ((int)$a['stud_owner_id'] === $userId)   return 'stud';
    if ((int)$a['female_owner_id'] === $userId) return 'female';
    return null;
}

function agreement_can_view(array $a, array $user): bool {
    return $user['role'] === 'admin' || agreement_party($a, (int)$user['id']) !== null;
}

function agreement_signed(array $a, string $party): bool {
    return !empty($a[$party . '_signed_at']);
}

// Terms can be edited by the person who drafted it, until anyone has signed
function agreement_editable(array $a, int $userId): bool {
    return $a['status'] === 'pending'
        && (int)$a['initiated_by'] === $userId
        && !agreement_signed($a, 'stud') && !agreement_signed($a, 'female');
}

function agreement_status_label(array $a): array {      // [text, badge class]
    return match ($a['status']) {
        'signed'    => ['Fully signed', 'ok'],
        'cancelled' => ['Cancelled', 'bad'],
        default     => ['Awaiting signatures', 'warn'],
    };
}

function agreement_file_name(array $a): string {
    return 'Stud-Agreement-' . preg_replace('/[^A-Za-z0-9\-]/', '', $a['agreement_no']) . '.pdf';
}

function agreement_url(array $a): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . BASE_URL . '/owner/agreement_view.php?id=' . (int)$a['agreement_id'];
}

/* ---------- Snapshot: both owners and both dogs, frozen when the agreement is drafted ---------- */
function agreement_snapshot(PDO $pdo, int $studId, int $requestId): ?array {
    $s = $pdo->prepare('SELECT s.*, b.breed_name, u.full_name, u.phone, u.email, u.location AS owner_location, u.kennel_name
                        FROM stud_dogs s JOIN breeds b ON b.breed_id = s.breed_id JOIN users u ON u.user_id = s.owner_id
                        WHERE s.stud_id = ?');
    $s->execute([$studId]);
    $stud = $s->fetch();

    $f = $pdo->prepare('SELECT f.*, b.breed_name, u.full_name, u.phone, u.email, u.location AS owner_location, u.kennel_name
                        FROM female_requests f JOIN breeds b ON b.breed_id = f.breed_id JOIN users u ON u.user_id = f.owner_id
                        WHERE f.request_id = ?');
    $f->execute([$requestId]);
    $fem = $f->fetch();
    if (!$stud || !$fem) return null;

    return [
        'stud_owner'   => ['name' => $stud['full_name'], 'phone' => $stud['phone'], 'email' => $stud['email'],
                           'location' => $stud['location'] ?: $stud['owner_location'], 'kennel' => $stud['kennel_name']],
        'female_owner' => ['name' => $fem['full_name'], 'phone' => $fem['phone'], 'email' => $fem['email'],
                           'location' => $fem['location'] ?: $fem['owner_location'], 'kennel' => $fem['kennel_name']],
        'stud'   => ['name' => $stud['name'], 'breed' => $stud['breed_name'], 'age_months' => (int)$stud['age_months'], 'color' => $stud['color'],
                     'proven' => (int)$stud['proven_sire'], 'litters' => (int)$stud['litters_sired'], 'health' => (int)$stud['health_tested'],
                     'vaccinated' => (int)$stud['vaccinated'], 'pedigree' => (int)$stud['pedigree']],
        'female' => ['name' => $fem['name'], 'breed' => $fem['breed_name'], 'age_months' => (int)$fem['age_months'], 'color' => $fem['color'],
                     'vaccinated' => (int)$fem['vaccinated'], 'litters' => (int)$fem['previous_litters'],
                     'heat' => heat_label($fem['heat_status'], $fem['expected_heat'])],
    ];
}

// Rows shown for each party and each dog (web page and PDF)
function agreement_party_rows(array $p): array {
    $rows = [['Name', $p['name']], ['Phone', $p['phone']], ['Email', $p['email']]];
    if (!empty($p['kennel'])) $rows[] = ['Kennel', $p['kennel']];
    $rows[] = ['Location', $p['location'] ?: '-'];
    return $rows;
}

function agreement_dog_rows(array $d, string $kind): array {
    $rows = [['Name', $d['name']], ['Breed', $d['breed']], ['Age', age_label((int)$d['age_months'])], ['Colour', $d['color'] ?: '-']];
    $tags = [];
    if ($kind === 'stud') {
        if ($d['proven']) $tags[] = 'Proven sire' . ($d['litters'] > 0 ? ' (' . $d['litters'] . ' litters)' : '');
        if ($d['health'])     $tags[] = 'Health tested';
        if ($d['vaccinated']) $tags[] = 'Vaccinated';
        if ($d['pedigree'])   $tags[] = 'Pedigree papers';
    } else {
        if ($d['vaccinated']) $tags[] = 'Vaccinated';
        $tags[] = $d['litters'] > 0 ? $d['litters'] . ' previous litter' . ($d['litters'] === 1 ? '' : 's') : 'First litter';
        $tags[] = $d['heat'];
    }
    $rows[] = ['Details', $tags ? implode(', ', $tags) : 'None stated'];
    return $rows;
}

/* ---------- The terms ---------- */
function agreement_fee_text(array $a): string {
    return $a['fee_type'] === 'cash' ? money($a['fee_amount']) : (string)$a['fee_terms'];
}

// Returns [ ['title' => '3. The mating', 'items' => ['3.1 text with **bold**', ...]], ... ]
function agreement_sections(array $a): array {
    $s    = $a['snap'];
    $stud = '**' . agr_v($s['stud']['name']) . '**';
    $fem  = '**' . agr_v($s['female']['name']) . '**';
    $place = agr_v($a['mating_place']);
    $terms = agr_v($a['fee_terms']);
    $sec  = [];

    // 3. The mating
    $when = $a['mating_date']
        ? 'on or about **' . agr_d($a['mating_date']) . '**'
        : 'on a date the Parties agree when the Female Dog is ready to be mated (in standing heat)';
    $i = [
        "The Stud Owner agrees that the Stud Dog, {$stud}, will mate with the Female Dog, {$fem}, on the terms of this Agreement.",
        "The mating will take place {$when}, at **{$place}**.",
        'Up to **' . (int)$a['mating_attempts'] . '** natural mating attempt' . ((int)$a['mating_attempts'] === 1 ? '' : 's')
            . ' may be made during the same heat cycle, unless the Parties agree otherwise in writing.',
    ];
    if ($a['repeat_service']) {
        $i[] = 'If the Female Dog does not become pregnant, the Stud Owner will allow **one repeat mating** in her next heat cycle at no extra Stud Fee. '
             . 'The Female Owner must ask for it within **' . (int)$a['repeat_months'] . ' months** of the first mating and show a vet\'s confirmation that she is not pregnant.';
        $i[] = 'A mating does not guarantee a pregnancy or any number of puppies. Apart from the repeat mating above, the Stud Owner is not responsible if no puppies are born.';
    } else {
        $i[] = '**No repeat mating is included.** Any further mating must be agreed and paid for separately.';
        $i[] = 'A mating does not guarantee a pregnancy or any number of puppies. The Stud Owner is not responsible if no puppies are born.';
    }
    $sec[] = ['title' => 'The mating', 'items' => $i];

    // 4. Stud fee
    $i = [];
    if ($a['fee_type'] === 'cash') {
        $i[] = 'The Female Owner will pay the Stud Owner a stud fee of **' . money($a['fee_amount']) . '** (the "Stud Fee").';
        $i[] = 'The Stud Fee is payable ' . match ($a['payment_timing']) {
            'before_mating'   => '**before the first mating**.',
            'after_pregnancy' => '**within 7 days after a vet confirms the Female Dog is pregnant**.',
            default           => '**on the day of the first mating**.',
        };
    } else {
        $i[] = 'Instead of cash, the Female Owner will give the Stud Owner the following (the "Stud Fee"): **' . $terms . '**.';
        $i[] = 'Unless the Additional Terms say otherwise, the Stud Fee is due when the puppies are about 8 weeks old and ready to leave their mother.';
    }
    $i[] = 'The Parties should keep proof of any payment (for example an M-Pesa message or receipt). Heritage Paws does not handle or hold any payment between the Parties.';
    $i[] = 'The Stud Fee is **not refundable** once the first mating has taken place. If the mating does not take place because of the Stud Dog\'s health, '
         . 'the Stud Dog being unavailable, or the Stud Owner cancelling, the Stud Owner will return in full anything already received within 7 days.';
    $sec[] = ['title' => 'Stud fee and payment', 'items' => $i];

    // 5. Health
    $i = [
        'Each Party confirms that their dog is in good health, is up to date with vaccinations (including rabies) and deworming, and, to the best of their knowledge, '
        . 'has no contagious or hereditary disease. Each Party will tell the other at once about any health problem affecting their dog.',
    ];
    $i[] = $a['health_tests']
        ? 'Before the mating, each Party will show a recent veterinary certificate or test result (including for canine brucellosis) for their dog. If a Party cannot, the mating may be postponed without either Party being in breach. Each Party pays for the tests on their own dog.'
        : 'The Parties have chosen not to require veterinary tests before the mating, and each accepts the risk of that choice.';
    $i[] = 'The Female Owner confirms that, to the best of their knowledge, the Female Dog is not already pregnant and has not been mated with another dog during this heat cycle.';
    $sec[] = ['title' => 'Health and vaccination', 'items' => $i];

    // 6. Care
    $sec[] = ['title' => 'Care, costs and responsibility', 'items' => [
        'Each Party remains responsible for their own dog, including food, vet bills and any injury or illness, unless it is caused by the other Party\'s deliberate act or serious carelessness.',
        'If the Female Dog stays at the Stud Owner\'s premises, the Stud Owner will feed, shelter and care for her properly, keep her away from other male dogs, and return her promptly after the mating. Any boarding charge must be agreed first and written under Additional Terms.',
        'Each Party confirms they are the lawful owner of their dog (or have the owner\'s permission) and have the right to sign this Agreement.',
    ]];

    // 7. Puppies
    $sec[] = ['title' => 'Pregnancy, puppies and papers', 'items' => [
        'The Female Owner will tell the Stud Owner when a pregnancy is confirmed, and within 7 days after the puppies are born.',
        'Subject to the Stud Fee in clause 4, all puppies born belong to the Female Owner.',
        'If the Stud Dog has pedigree or registration papers, the Stud Owner will, within 7 days of the mating, give a copy of them and sign any kennel club mating or litter registration forms that are needed.',
        'The Female Owner will look after the puppies properly, including deworming and vaccination before they are sold or given away, and will rehome them responsibly.',
    ]];

    // 8. Cancellation
    $sec[] = ['title' => 'Cancellation', 'items' => [
        'Either Party may cancel this Agreement before the first mating by telling the other Party in writing (a WhatsApp message or email is enough).',
        'If the Stud Owner cancels, anything already paid is returned in full. If the Female Owner cancels after paying, the Stud Owner may keep only the reasonable costs actually incurred and returns the rest within 7 days.',
    ]];

    // 9. Disputes
    $sec[] = ['title' => 'Disputes and governing law', 'items' => [
        'The Parties will first try to settle any disagreement by talking to each other within 14 days.',
        'If that does not work, they will try mediation before going to court.',
        'This Agreement is governed by the laws of Kenya, and the courts of Kenya have jurisdiction.',
    ]];

    // 10. Heritage Paws
    $sec[] = ['title' => 'Role of Heritage Paws', 'items' => [
        'Heritage Paws is an online platform that helps dog owners find each other. It is not a party to this Agreement. It does not hold money, check ownership, health, pedigree or papers, or guarantee that either Party will do what they have promised.',
        'Heritage Paws is not responsible for any loss, injury, illness or dispute arising from this Agreement or the mating. It keeps a record of this Agreement and its signatures for the Parties to download.',
    ]];

    // 11. Additional terms
    $extra = trim(agr_v($a['extra_terms']));
    $sec[] = ['title' => 'Additional terms', 'items' => [$extra !== '' ? $extra : 'None agreed.']];

    // 12. Signing
    $sec[] = ['title' => 'Electronic signing', 'items' => [
        'Each Party signs by typing their full name and confirming with their Heritage Paws account password. The Parties agree that this is their signature, with the same effect as a handwritten signature, as far as the law of Kenya allows.',
        'This Agreement takes effect when both Parties have signed. The date, time and IP address of each signing are recorded below.',
        'Once both Parties have signed, any change must be agreed in writing and signed by both Parties.',
    ]];

    // number them: sections start at 3, clauses 3.1, 3.2 ...
    foreach ($sec as $n => &$sc) {
        $sc['no'] = $n + 3;
        foreach ($sc['items'] as $k => $text) {
            $sc['items'][$k] = ['no' => ($n + 3) . '.' . ($k + 1), 'text' => $text];
        }
    }
    unset($sc);
    return $sec;
}

// Removes the ** bold marker from user-typed text so people cannot inject formatting
function agr_v($s): string {
    return str_replace('**', '', (string)$s);
}

// **bold** markers -> <strong> for the web page (escapes everything else)
function agr_html(string $text): string {
    return nl2br(preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', e($text)));
}

/* ---------- Fingerprint (detects any later change to the terms or signatures) ---------- */
function agreement_fingerprint(array $a): string {
    $keys = ['agreement_no', 'snapshot', 'mating_date', 'mating_place', 'mating_attempts', 'repeat_service', 'repeat_months', 'fee_type', 'fee_amount',
             'fee_terms', 'payment_timing', 'health_tests', 'extra_terms', 'stud_signed_name', 'stud_signed_at', 'stud_signed_ip',
             'female_signed_name', 'female_signed_at', 'female_signed_ip'];
    $parts = [];
    foreach ($keys as $k) $parts[] = $k . '=' . (string)($a[$k] ?? '');
    return hash('sha256', implode('|', $parts));
}

/* ---------- PDF ---------- */
function agreement_pdf(array $a): string {
    $BROWN = [107, 68, 35]; $DARK = [43, 33, 24]; $GREY = [122, 108, 93]; $GOLD = [200, 146, 58];
    $SAND  = [239, 230, 216]; $GREEN = [47, 125, 79]; $AMBER = [185, 119, 14]; $RED = [179, 55, 44];

    $logo = HP_PDF::loadImage(__DIR__ . '/../assets/img/logo-white.png', [380, 170, 780, 550], 700);

    $pdf = new HP_PDF();
    $pdf->title = 'Stud Service Agreement ' . $a['agreement_no'];
    $pdf->watermark = $a['status'] === 'signed' ? '' : ($a['status'] === 'cancelled' ? 'CANCELLED' : 'NOT YET SIGNED');
    $contentW = HP_PDF::W - $pdf->ml - $pdf->mr;

    // Header on every page: big logo and title on page 1, a small running header after that
    $pdf->headerFn = function (HP_PDF $p) use ($logo, $a, $GREY, $GOLD, $DARK) {
        if ($p->pageNo() === 1) {
            $lw = 118; $lh = $lw * 550 / 780;
            if ($logo) $p->image($logo, (HP_PDF::W - $lw) / 2, 40, $lw, $lh);
            $p->y = 40 + $lh + 16;
        } else {
            if ($logo) $p->image($logo, $p->ml, 28, 46, 46 * 550 / 780);
            $p->text($p->ml, 46, 'Stud Service Agreement', 'F2', 10, $DARK, 'R', HP_PDF::W - $p->ml - $p->mr);
            $p->text($p->ml, 59, $a['agreement_no'], 'F1', 9, $GREY, 'R', HP_PDF::W - $p->ml - $p->mr);
            $p->line($p->ml, 70, HP_PDF::W - $p->mr, 70, $GOLD, 1);
            $p->y = 84;
        }
    };
    $pdf->footerFn = function (HP_PDF $p, int $page, int $total) use ($a, $GREY) {
        $y = HP_PDF::H - 34;
        $p->line($p->ml, $y - 12, HP_PDF::W - $p->mr, $y - 12, [215, 205, 190], 0.6);
        $p->text($p->ml, $y, 'Heritage Paws  |  ' . $a['agreement_no'] . '  |  Stud Service Agreement', 'F1', 8, $GREY);
        $p->text($p->ml, $y, 'Page ' . $page . ' of ' . $total, 'F1', 8, $GREY, 'R', HP_PDF::W - $p->ml - $p->mr);
    };
    $pdf->addPage();

    // Title block
    $pdf->text($pdf->ml, $pdf->y + 18, 'STUD SERVICE AGREEMENT', 'F2', 20, $DARK, 'C', $contentW);
    $pdf->y += 28;
    $pdf->text($pdf->ml, $pdf->y + 9, 'Agreement No. ' . $a['agreement_no'] . '   |   Drafted ' . agr_d($a['created_at']), 'F1', 9.5, $GREY, 'C', $contentW);
    $pdf->y += 18;
    [$stText, $stCol] = match ($a['status']) {
        'signed'    => ['FULLY SIGNED BY BOTH PARTIES', $GREEN],
        'cancelled' => ['CANCELLED', $RED],
        default     => ['AWAITING SIGNATURES', $AMBER],
    };
    $tw = $pdf->strWidth(HP_PDF::latin($stText), 'F2', 9) + 24;
    $pdf->rect((HP_PDF::W - $tw) / 2, $pdf->y, $tw, 18, null, $stCol, 1);
    $pdf->text((HP_PDF::W - $tw) / 2, $pdf->y + 12.5, $stText, 'F2', 9, $stCol, 'C', $tw);
    $pdf->y += 34;

    $pdf->para('This Agreement is made through the Heritage Paws marketplace between the two dog owners named below (the "Parties"). '
             . 'It sets out the terms on which the Stud Dog will mate with the Female Dog.', ['size' => 10, 'after' => 8]);

    $heading = function (string $title) use ($pdf, $BROWN, $GOLD, $contentW) {
        $pdf->ensure(60);
        $pdf->y += 8;
        $pdf->text($pdf->ml, $pdf->y + 11, strtoupper($title), 'F2', 11, $BROWN);
        $pdf->y += 17;
        $pdf->line($pdf->ml, $pdf->y, $pdf->ml + $contentW, $pdf->y, $GOLD, 0.8);
        $pdf->y += 8;
    };

    // Two boxes side by side (parties / dogs)
    $twoBoxes = function (string $lt, array $lrows, string $rt, array $rrows) use ($pdf, $SAND, $GREY, $DARK, $contentW) {
        $gap = 14; $bw = ($contentW - $gap) / 2; $labelW = 52; $size = 9.5; $lh = 13.5; $pad = 8;
        $measure = function (array $rows) use ($pdf, $bw, $labelW, $pad, $size, $lh) {
            $out = []; $h = 0;
            foreach ($rows as [$label, $value]) {
                $lines = $pdf->wrap((string)$value, $size, $bw - $labelW - $pad * 2);
                $out[] = [$label, $lines]; $h += max(1, count($lines)) * $lh + 3;
            }
            return [$out, $h];
        };
        [$L, $hl] = $measure($lrows); [$R, $hr] = $measure($rrows);
        $boxH = 24 + max($hl, $hr) + 6;
        $pdf->ensure($boxH + 6);
        foreach ([[$pdf->ml, $lt, $L], [$pdf->ml + $bw + $gap, $rt, $R]] as [$x, $title, $rows]) {
            $pdf->rect($x, $pdf->y, $bw, $boxH, null, [215, 205, 190], 0.8);
            $pdf->rect($x, $pdf->y, $bw, 20, $SAND);
            $pdf->text($x + $pad, $pdf->y + 14, strtoupper($title), 'F2', 9, [74, 46, 22]);
            $yy = $pdf->y + 24;
            foreach ($rows as [$label, $lines]) {
                $pdf->text($x + $pad, $yy + $size, $label, 'F1', 8.5, $GREY);
                foreach ($lines as $k => $line) {
                    $pdf->drawLine($line, $x + $pad + $labelW, $yy + $size + $k * $lh, $size, $DARK);
                }
                $yy += max(1, count($lines)) * $lh + 3;
            }
        }
        $pdf->y += $boxH + 6;
    };

    $snap = $a['snap'];
    $heading('1. The parties');
    $twoBoxes('Stud Owner', agreement_party_rows($snap['stud_owner']), 'Female Owner', agreement_party_rows($snap['female_owner']));
    $heading('2. The dogs');
    $twoBoxes('Stud Dog', agreement_dog_rows($snap['stud'], 'stud'), 'Female Dog', agreement_dog_rows($snap['female'], 'female'));

    // Clauses
    foreach (agreement_sections($a) as $sc) {
        $heading($sc['no'] . '. ' . $sc['title']);
        foreach ($sc['items'] as $it) {
            $pdf->para($it['text'], ['x' => $pdf->ml + 30, 'numw' => 30, 'num' => $it['no'], 'size' => 9.8, 'after' => 4.5, 'w' => $contentW - 30]);
        }
    }

    // Signatures (kept together on one page)
    $pdf->ensure(250);
    $heading('13. Signatures');
    $gap = 14; $bw = ($contentW - $gap) / 2; $bh = 122;
    $top = $pdf->y;
    foreach ([['stud', 'Stud Owner', $pdf->ml], ['female', 'Female Owner', $pdf->ml + $bw + $gap]] as [$who, $label, $x]) {
        $signed = agreement_signed($a, $who);
        $name   = $snap[$who . '_owner']['name'];
        $pdf->rect($x, $top, $bw, $bh, null, $signed ? $GREEN : [200, 190, 175], 0.9);
        $pdf->rect($x, $top, $bw, 20, $signed ? [220, 241, 228] : $SAND);
        $pdf->text($x + 8, $top + 14, strtoupper($label), 'F2', 9, $signed ? $GREEN : [74, 46, 22]);
        if ($signed) {
            $pdf->text($x + 8, $top + 52, $a[$who . '_signed_name'], 'F3', 21, [20, 30, 70]);
            $pdf->line($x + 8, $top + 60, $x + $bw - 8, $top + 60, [90, 90, 90], 0.7);
            $pdf->text($x + 8, $top + 73, 'Signed electronically by ' . $name, 'F1', 8, $DARK);
            $pdf->text($x + 8, $top + 85, agr_dt($a[$who . '_signed_at']), 'F1', 8, $DARK);
            $pdf->text($x + 8, $top + 97, 'IP address: ' . ($a[$who . '_signed_ip'] ?: 'not recorded'), 'F1', 8, $GREY);
            $pdf->text($x + 8, $top + 110, 'Identity confirmed by account password', 'F4', 7.5, $GREY);
        } else {
            $pdf->line($x + 8, $top + 60, $x + $bw - 8, $top + 60, [150, 150, 150], 0.7, true);
            $pdf->text($x + 8, $top + 50, 'Awaiting signature', 'F4', 10, $GREY);
            $pdf->text($x + 8, $top + 73, $name, 'F1', 9, $DARK);
        }
    }
    $pdf->y = $top + $bh + 14;

    // Fingerprint
    $fp = agreement_fingerprint($a);
    $pdf->ensure(64);
    $pdf->text($pdf->ml, $pdf->y + 8, 'Document fingerprint (SHA-256). Any change to the terms or signatures changes this code.', 'F1', 7.5, $GREY);
    $pdf->text($pdf->ml, $pdf->y + 20, substr($fp, 0, 32), 'F5', 8, $DARK);
    $pdf->text($pdf->ml, $pdf->y + 31, substr($fp, 32), 'F5', 8, $DARK);
    $pdf->y += 40;
    $pdf->para('Copy generated on ' . agr_dt(agr_now()) . '. This agreement is a template provided by Heritage Paws. It is not legal advice; the Parties are free to take legal advice before signing.',
               ['size' => 7.5, 'font' => 'F4', 'color' => $GREY, 'after' => 0]);

    return $pdf->output();
}
