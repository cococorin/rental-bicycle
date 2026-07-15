<?php
// ============================================================
// 会員 upsert webhook（Looper）
//   Google フォーム送信時に GAS（form_webhook.gs）から POST される。
//   共有シークレットで認証。email を一意キーに upsert（再登録は情報更新）。
//   ★登録の一気通貫: 未パスワードの会員には、その場でパスワード設定メールを
//     即送信する（利用者はフォーム後すぐにメールのリンクで設定を完了できる）。
// ============================================================
declare(strict_types=1);

require __DIR__ . '/../api/db.php';
require __DIR__ . '/../api/helpers.php';
require __DIR__ . '/../api/mail.php';

$raw = file_get_contents('php://input');
$in  = json_decode((string)$raw, true) ?: $_POST;

// --- 認証（共有シークレット）---
if (($in['secret'] ?? '') !== ($CONFIG['shared_secret'] ?? "\0")) {
    http_response_code(403);
    respond(['success' => false, 'error' => 'forbidden']);
}

$email = mb_strtolower(trim((string)($in['email'] ?? '')));
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(['success' => false, 'error' => 'メールアドレスが不正です']);
}

// 生年月日は 8桁数値(YYYYMMDD) or YYYY-MM-DD を許容 → DATE へ
$birthRaw = preg_replace('/[^0-9]/', '', (string)($in['birthDate'] ?? ''));
$birthDate = null;
$isMinor = !empty($in['isMinor']) ? 1 : 0;
if (strlen($birthRaw) === 8) {
    $birthDate = substr($birthRaw, 0, 4) . '-' . substr($birthRaw, 4, 2) . '-' . substr($birthRaw, 6, 2);
    // 生年月日から16歳未満を自動判定（自己申告より優先）
    $ts = strtotime($birthDate);
    if ($ts !== false) {
        $age = (int)((time() - $ts) / (365.25 * 86400));
        $isMinor = $age < 16 ? 1 : 0;
    }
}

$fields = [
    'email'       => $email,
    'family_name' => trim((string)($in['familyName'] ?? '')),
    'first_name'  => trim((string)($in['firstName'] ?? '')),
    'kana_family' => trim((string)($in['kanaFamily'] ?? '')),
    'kana_first'  => trim((string)($in['kanaFirst'] ?? '')),
    'birth_date'  => $birthDate,
    'company'     => trim((string)($in['company'] ?? '')),
    'zip'         => trim((string)($in['zip'] ?? '')),
    'address1'    => trim((string)($in['address1'] ?? '')),
    'address2'    => trim((string)($in['address2'] ?? '')),
    'phone'       => trim((string)($in['phone'] ?? '')),
    'agreed'      => !empty($in['agreed'])    ? 1 : 0,
    'qualified'   => !empty($in['qualified']) ? 1 : 0,
    'is_minor'    => $isMinor,
    'memo'        => trim((string)($in['memo'] ?? '')),
];

// email を一意キーに upsert（再登録時は会員情報を更新。password_hash / member_no は保持）
$cols = array_keys($fields);
$place = implode(',', array_fill(0, count($cols), '?'));
$updates = implode(', ', array_map(fn($c) => "$c = VALUES($c)", array_filter($cols, fn($c) => $c !== 'email')));
$sql = 'INSERT INTO members (' . implode(',', $cols) . ') VALUES (' . $place . ')
        ON DUPLICATE KEY UPDATE ' . $updates;
db()->prepare($sql)->execute(array_values($fields));

// 一気通貫: 未パスワードなら設定メールを即送信
$member = member_by_email($email);
$mailSent = false;
if ($member && empty($member['password_hash'])) {
    $token = issue_password_token($email);
    $name  = trim(((string)$member['family_name']) . ' ' . ((string)$member['first_name']));
    $mailSent = send_password_mail($email, $name, $token);
}

respond([
    'success'  => true,
    'email'    => $email,
    'name'     => trim($fields['family_name'] . ' ' . $fields['first_name']),
    'mailSent' => $mailSent,
]);
