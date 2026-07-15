<?php
// ============================================================
// エクスポート（スタッフ閲覧用 MySQL→Sheets ミラーの元データ）
//   共有シークレット必須。会員 PII を含むため公開しない。
//   GET/POST ?secret=... [&kind=members|bookings|rentals|all]
// ============================================================
declare(strict_types=1);

require __DIR__ . '/../api/db.php';

$secret = $_REQUEST['secret'] ?? (json_decode(file_get_contents('php://input'), true)['secret'] ?? '');
if ($secret !== ($CONFIG['shared_secret'] ?? "\0")) {
    http_response_code(403);
    respond(['success' => false, 'error' => 'forbidden']);
}

$kind = $_REQUEST['kind'] ?? 'all';
$out  = ['success' => true];

if ($kind === 'members' || $kind === 'all') {
    $out['members'] = db()->query(
        "SELECT COALESCE(member_no,'') AS member_no, email,
                TRIM(CONCAT(family_name,' ',first_name)) AS full_name,
                phone, DATE_FORMAT(birth_date,'%Y-%m-%d') AS birth_date,
                is_minor, (password_hash IS NOT NULL) AS has_password,
                registered_at
         FROM members ORDER BY registered_at"
    )->fetchAll();
}
if ($kind === 'bookings' || $kind === 'all') {
    $out['bookings'] = db()->query(
        "SELECT booking_no, member_no, name, bike_id,
                DATE_FORMAT(date,'%Y-%m-%d') AS date,
                TIME_FORMAT(start_time,'%H:%i') AS start_time,
                TIME_FORMAT(end_time,'%H:%i') AS end_time,
                status, course, total_paid, created_at
         FROM bookings ORDER BY date DESC, start_time DESC"
    )->fetchAll();
}
if ($kind === 'rentals' || $kind === 'all') {
    $out['rentals'] = db()->query(
        "SELECT txn_no, member_no, name, bike_id, course, helmet, locker,
                start_at, TIME_FORMAT(return_expected,'%H:%i') AS return_expected,
                status, total_paid, extra_paid, returned_at
         FROM rentals ORDER BY start_at DESC"
    )->fetchAll();
}

respond($out);
