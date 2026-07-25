<?php
// ============================================================
// Looper  PHP API ルーター（ikewaki/handanotane 両GAS の統合置換）
//   現行フロント（booking/admin/reception）の ?action=... を
//   JSONP(callback) 付きでそのまま受ける。応答フィールドは現行に一致。
// ============================================================
declare(strict_types=1);

require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/mail.php';

// GET/POST どちらのパラメータも受ける（現行フロントは GET クエリ + body= の JSON）
$params = $_REQUEST;
$action = $params['action'] ?? '';

// 書き込み系は body=（JSON文字列）で渡ってくる（現行 JSONP 方式）。POST本文にも対応。
$body = [];
if (isset($params['body'])) {
    $body = json_decode((string)$params['body'], true) ?: [];
} elseif (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $raw = file_get_contents('php://input');
    $body = json_decode((string)$raw, true) ?: $_POST;
}

// 管理者トークンはクエリ/ボディどちらでも受ける（JSONPのGETにも対応）
$auth = array_merge(is_array($body) ? $body : [], $params);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    respond(['success' => true]);
}

try {
    switch ($action) {
        // --- 読み取り系 ---
        case 'ping':             respond(['status' => 'ok', 'account' => 'looper-php', 'timestamp' => date('c')]); break;
        case 'getSettings':      respond(load_settings()); break;
        case 'getAvailability':  respond(getAvailability($params['date'] ?? '')); break;
        case 'getBikes':         respond(getBikes()); break;
        case 'getBookings':      respond(getBookings($params['from'] ?? '', $params['to'] ?? '')); break;
        case 'getActiveRentals': respond(getActiveRentals()); break;
        case 'getRentals':       respond(getRentals($params['from'] ?? '', $params['to'] ?? '', false)); break;
        case 'getAllRentals':    respond(getRentals($params['from'] ?? '', $params['to'] ?? '', true)); break;
        case 'getMonthlyCount':  respond(getMonthlyCount($params['year'] ?? '', $params['month'] ?? '')); break;
        case 'getMember':        respond(getMember($params['id'] ?? '')); break;
        case 'searchByEmail':    respond(searchByEmail($params['email'] ?? ($body['email'] ?? ''))); break;
        // 会員一覧は事務局(staff)も閲覧可。ただし編集・削除は管理者のみ（下記）。
        case 'getMemberList':    require_staff($auth); respond(getMemberList(false)); break;
        case 'getMemberListFull':require_staff($auth); respond(getMemberList(true)); break;

        // --- 管理者認証・会員管理（要トークン）---
        case 'adminLogin':       respond(adminLogin($body)); break;
        case 'adminLogout':      respond(adminLogout($auth)); break;
        case 'adminMe':          respond(adminMe($auth)); break;
        case 'updateMember':     respond(updateMember($body, require_admin($auth))); break;
        case 'deleteMember':     respond(deleteMember($body, require_admin($auth))); break;
        case 'getMemberChangeLogs': require_admin($auth); respond(getMemberChangeLogs($params['email'] ?? '')); break;
        // 会員カルテ（登録情報＋予約/利用履歴＋サマリー）。事務局も閲覧可。
        case 'getMemberProfile':   require_staff($auth); respond(getMemberProfile($params['email'] ?? ($body['email'] ?? ''))); break;
        // 管理画面から「パスワード設定メール」を再送（未設定の会員向け）。事務局も可・監査記録。
        case 'resendPasswordSetup':respond(resendPasswordSetup($body, require_staff($auth)['user'])); break;

        // --- 管理者アカウントの管理（要トークン）---
        case 'listAdmins':          require_admin($auth); respond(listAdmins()); break;
        case 'addAdmin':            respond(addAdmin($body, require_admin($auth))); break;
        case 'updateAdmin':         respond(updateAdmin($body, require_admin($auth))); break;
        case 'deleteAdmin':         respond(deleteAdmin($body, require_admin($auth))); break;
        case 'getAdminChangeLogs':  require_admin($auth); respond(getAdminChangeLogs()); break;

        // --- 書き込み系 ---
        case 'addBooking':       respond(addBooking($body)); break;
        case 'cancelBooking':    respond(cancelBooking($body)); break;
        case 'addRental':        respond(addRental($body)); break;
        case 'updateRental':     respond(updateRental($body)); break;
        case 'saveSettings':     save_settings($body); respond(['success' => true]); break;
        case 'saveSpecialDays':  respond(saveSpecialDays($body)); break;

        // --- 認証・会員 ---
        case 'login':               respond(loginMember($body)); break;
        case 'sendVerification':    respond(sendVerification($body)); break;
        // パスワードを忘れた場合の再設定リクエスト（公開・存在は明かさない）
        case 'requestPasswordReset':respond(requestPasswordReset($body)); break;
        case 'setPasswordByToken':  respond(setPasswordByToken($body)); break;
        case 'changePassword':      respond(changePassword($body)); break;
        // カード（会員番号）付与は事務局メンバー(staff)でも可
        case 'getPendingMembers':  require_staff($auth); respond(getPendingMembers()); break;
        case 'assignCard':         respond(assignCard($body, require_staff($auth)['user'])); break;

        default: respond(['error' => 'unknown action: ' . $action]);
    }
} catch (Throwable $e) {
    respond(['success' => false, 'error' => $e->getMessage()]);
}

// ============================================================
//  空き状況・予約
// ============================================================
function getAvailability(string $dateStr): array
{
    if ($dateStr === '') return ['error' => 'date required'];
    $s   = load_settings();
    $dow = (int)date('w', strtotime($dateStr . ' 01:00:00'));
    $closed = is_closed_day($dateStr, $s);

    $result = [
        'date' => $dateStr, 'isClosedDay' => $closed,
        'closedDayName' => $closed ? day_name($dow) : null,
        'openTime' => $s['openTime'], 'closeTime' => $s['closeTime'],
        'bufferMinutes' => $s['bufferMinutes'], 'bikes' => [],
    ];

    // 当日の非キャンセル予約をまとめて取得
    $stmt = db()->prepare(
        "SELECT bike_id, booking_no, TIME_FORMAT(start_time,'%H:%i') AS s, TIME_FORMAT(end_time,'%H:%i') AS e
         FROM bookings WHERE date = ? AND status <> 'cancelled'"
    );
    $stmt->execute([$dateStr]);
    $byBike = [];
    foreach ($stmt->fetchAll() as $r) {
        $byBike[$r['bike_id']][] = [
            'bookingId' => $r['booking_no'],
            'start'     => $r['s'],
            'end'       => $r['e'],
            'bufferEnd' => add_min_to_time($r['e'], (int)$s['bufferMinutes']),
        ];
    }
    foreach (activeBikes() as $b) {
        $result['bikes'][] = [
            'id' => $b['bike_id'], 'label' => $b['label'], 'type' => $b['type'],
            'color' => $b['color'], 'sub' => $b['sub'],
            'bookings' => $byBike[$b['bike_id']] ?? [],
        ];
    }
    return $result;
}

/** 有効な自転車マスタ（表示定義込み）。全画面の表示はこのテーブルが正。 */
function activeBikes(): array
{
    return db()->query(
        'SELECT bike_id, label, type, color, sub FROM bikes WHERE active = 1 ORDER BY sort'
    )->fetchAll();
}

/** GET ?action=getBikes … 受付画面など、空き状況が不要な画面の表示定義取得用 */
function getBikes(): array
{
    $bikes = [];
    foreach (activeBikes() as $b) {
        $bikes[] = [
            'id' => $b['bike_id'], 'label' => $b['label'], 'type' => $b['type'],
            'color' => $b['color'], 'sub' => $b['sub'],
        ];
    }
    return ['bikes' => $bikes, 'count' => count($bikes)];
}

function getBookings(string $from, string $to): array
{
    $sql = "SELECT booking_no, member_no, member_email, name, bike_id,
                   DATE_FORMAT(date,'%Y-%m-%d') AS d,
                   TIME_FORMAT(start_time,'%H:%i') AS st, TIME_FORMAT(end_time,'%H:%i') AS et,
                   status, course, total_paid, memo, created_at
            FROM bookings WHERE 1=1";
    $args = [];
    if ($from !== '') { $sql .= ' AND date >= ?'; $args[] = $from; }
    if ($to   !== '') { $sql .= ' AND date <= ?'; $args[] = $to; }
    $sql .= ' ORDER BY date DESC, start_time DESC';
    $stmt = db()->prepare($sql);
    $stmt->execute($args);
    $bookings = [];
    foreach ($stmt->fetchAll() as $r) {
        $bookings[] = [
            'bookingId' => $r['booking_no'], 'memberId' => (string)$r['member_no'],
            'memberEmail' => (string)$r['member_email'], 'name' => $r['name'],
            'bikeId' => $r['bike_id'], 'date' => $r['d'], 'startTime' => $r['st'], 'endTime' => $r['et'],
            'status' => $r['status'], 'course' => $r['course'], 'totalPaid' => (int)$r['total_paid'],
            'memo' => $r['memo'], 'createdAt' => $r['created_at'],
        ];
    }
    return ['bookings' => $bookings, 'count' => count($bookings)];
}

function addBooking(array $body): array
{
    foreach (['memberId','bikeId','date','startTime','endTime'] as $k) {
        // memberId は member_no（カード番号）。email があれば会員紐付けに使う。
        if ($k === 'memberId') continue;
        if (empty($body[$k])) return ['success' => false, 'error' => '必須項目が不足しています'];
    }
    $reqId = isset($body['reqId']) ? (string)$body['reqId'] : '';

    // 冪等: 同一 reqId は既存予約をそのまま返す（多重送信/プリフェッチ対策）
    if ($reqId !== '') {
        $stmt = db()->prepare('SELECT booking_no FROM bookings WHERE req_id = ? LIMIT 1');
        $stmt->execute([$reqId]);
        if ($no = $stmt->fetchColumn()) {
            return ['success' => true, 'bookingId' => $no, 'duplicate' => true];
        }
    }

    $s    = load_settings();
    $date = (string)$body['date'];
    $st   = (string)$body['startTime'];
    $et   = (string)$body['endTime'];

    if (is_closed_day($date, $s)) {
        $dow = (int)date('w', strtotime($date . ' 01:00:00'));
        return ['success' => false, 'error' => '定休日のため予約できません（' . day_name($dow) . '曜日）'];
    }
    if (t_min($st) < t_min($s['openTime']) || t_min($et) > t_min($s['closeTime'])) {
        return ['success' => false, 'error' => '営業時間外です（' . $s['openTime'] . '〜' . $s['closeTime'] . '）'];
    }
    if (t_min($st) >= t_min($et)) {
        return ['success' => false, 'error' => '終了時刻は開始時刻より後に設定してください'];
    }
    $conflict = check_conflict((string)$body['bikeId'], $date, $st, $et, (int)$s['bufferMinutes']);
    if ($conflict) {
        return ['success' => false, 'error' => 'この時間帯は予約済みです（' . $conflict['start'] . '〜' . $conflict['bufferEnd'] . ' 清掃バッファ含む）'];
    }

    // 会員を解決する。不変キー email 優先。memberId は 'PENDING'（カード未発行）の場合が
    // あるので、member_no 列には「実カード番号だけ」を格納する（'PENDING' 文字列は保存しない）。
    // これにより WEB予約でも email で本人に紐づき、受付・会員カルテで正しく照合できる。
    $email  = isset($body['email']) ? mb_strtolower(trim((string)$body['email'])) : '';
    $rawNo  = trim((string)($body['memberId'] ?? ''));
    $member = null;
    if ($email !== '')                                         $member = member_by_email($email);
    if (!$member && $rawNo !== '' && strtoupper($rawNo) !== 'PENDING') $member = member_by_no($rawNo);
    if ($member) $email = (string)($member['email'] ?? $email);
    $memberNo = $member
        ? (string)($member['member_no'] ?? '')                                  // 実カード番号（未発行なら空）
        : (($rawNo !== '' && strtoupper($rawNo) !== 'PENDING') ? $rawNo : '');   // 会員未特定でも数値番号なら尊重

    $bookingNo = gen_booking_id();
    try {
        db()->prepare(
            'INSERT INTO bookings
             (booking_no, member_email, member_no, name, bike_id, date, start_time, end_time,
              status, course, total_paid, memo, req_id)
             VALUES (?,?,?,?,?,?,?,?, "confirmed", ?, ?, ?, ?)'
        )->execute([
            $bookingNo, $email, $memberNo, (string)($body['name'] ?? ''),
            (string)$body['bikeId'], $date, $st, $et,
            (string)($body['course'] ?? ''), (int)($body['totalPaid'] ?? 0),
            (string)($body['memo'] ?? ''), $reqId !== '' ? $reqId : null,
        ]);
    } catch (PDOException $e) {
        // req_id の一意制約違反 = 並行多重送信。既存を返す。
        if ($reqId !== '') {
            $stmt = db()->prepare('SELECT booking_no FROM bookings WHERE req_id = ? LIMIT 1');
            $stmt->execute([$reqId]);
            if ($no = $stmt->fetchColumn()) return ['success' => true, 'bookingId' => $no, 'duplicate' => true];
        }
        throw $e;
    }

    // 管理者通知メール
    if (!empty($s['notifyEmail'])) {
        $bl = db()->prepare('SELECT label FROM bikes WHERE bike_id = ?');
        $bl->execute([(string)$body['bikeId']]);
        $label = $bl->fetchColumn() ?: (string)$body['bikeId'];
        $mailBody = nl2br(htmlspecialchars(
            '予約番号: ' . $bookingNo . "\n会員番号: " . ($body['memberId'] ?? '') . "\nお名前: " . ($body['name'] ?? '') .
            "\n自転車: " . $label . "\n日時: " . $date . ' ' . $st . '〜' . $et .
            "\n料金: ¥" . (int)($body['totalPaid'] ?? 0), ENT_QUOTES, 'UTF-8'));
        @send_html_mail((string)$s['notifyEmail'], '【Looper】新規予約: ' . ($body['name'] ?? '') . ' 様 / ' . $date, $mailBody);
    }
    return ['success' => true, 'bookingId' => $bookingNo];
}

function cancelBooking(array $body): array
{
    $no = (string)($body['bookingId'] ?? '');
    if ($no === '') return ['success' => false, 'error' => 'bookingId required'];
    $stmt = db()->prepare('SELECT member_no, member_email FROM bookings WHERE booking_no = ? LIMIT 1');
    $stmt->execute([$no]);
    $row = $stmt->fetch();
    if (!$row) return ['success' => false, 'error' => '予約が見つかりません'];
    if (empty($body['isAdmin'])) {
        $reqMember = normalize_member_no((string)($body['memberId'] ?? ''));
        if (normalize_member_no((string)$row['member_no']) !== $reqMember) {
            return ['success' => false, 'error' => '他の会員の予約はキャンセルできません'];
        }
    }
    db()->prepare("UPDATE bookings SET status = 'cancelled' WHERE booking_no = ?")->execute([$no]);
    return ['success' => true];
}

// ============================================================
//  利用記録
// ============================================================
function rentalRow(array $r): array
{
    return [
        'txnId' => $r['txn_no'], 'memberId' => (string)$r['member_no'], 'name' => $r['name'],
        'bike' => $r['bike_id'], 'course' => $r['course'],
        'helmet' => (int)$r['helmet'] === 1, 'locker' => (int)$r['locker'] === 1,
        'startTime' => $r['start_at'], 'returnTime' => $r['return_expected'] ? fmt_time($r['return_expected']) : '',
        'status' => $r['status'], 'totalPaid' => (int)$r['total_paid'], 'extraPaid' => (int)$r['extra_paid'],
        'returnedAt' => $r['returned_at'],
    ];
}

function getActiveRentals(): array
{
    $rows = db()->query("SELECT * FROM rentals WHERE status = 'active' ORDER BY start_at")->fetchAll();
    $rentals = array_map('rentalRow', $rows);
    $today = date('Y-m-d');
    $todayCount = (int)db()->query(
        "SELECT COUNT(*) FROM rentals WHERE DATE(start_at) = '" . $today . "'"
    )->fetchColumn();
    return ['rentals' => $rentals, 'count' => count($rentals), 'todayCount' => $todayCount];
}

function getRentals(string $from, string $to, bool $all): array
{
    $sql = 'SELECT * FROM rentals WHERE 1=1';
    $args = [];
    if ($from !== '') { $sql .= ' AND DATE(start_at) >= ?'; $args[] = $from; }
    if ($to   !== '') { $sql .= ' AND DATE(start_at) <= ?'; $args[] = $to; }
    $sql .= ' ORDER BY start_at DESC';
    $stmt = db()->prepare($sql);
    $stmt->execute($args);
    $rentals = array_map('rentalRow', $stmt->fetchAll());
    return ['rentals' => $rentals, 'count' => count($rentals)];
}

function getMonthlyCount(string $year, string $month): array
{
    $y = $year !== '' ? $year : date('Y');
    $m = $month !== '' ? str_pad($month, 2, '0', STR_PAD_LEFT) : date('m');
    $prefix = $y . '-' . $m;
    $stmt = db()->prepare(
        "SELECT COUNT(*) c, COALESCE(SUM(total_paid + extra_paid),0) t
         FROM rentals WHERE DATE_FORMAT(start_at,'%Y-%m') = ?"
    );
    $stmt->execute([$prefix]);
    $r = $stmt->fetch();
    return ['count' => (int)$r['c'], 'totalAmount' => (int)$r['t'], 'month' => $y . '年' . (int)$m . '月'];
}

function addRental(array $body): array
{
    $txn = (string)($body['txnId'] ?? '');
    if ($txn === '') return ['success' => false, 'error' => 'txnId required'];
    $stmt = db()->prepare('SELECT 1 FROM rentals WHERE txn_no = ? LIMIT 1');
    $stmt->execute([$txn]);
    if ($stmt->fetchColumn()) return ['success' => true, 'note' => 'already exists'];

    $email = '';
    if (!empty($body['memberId'])) { $m = member_by_no((string)$body['memberId']); if ($m) $email = (string)$m['email']; }
    $startAt = !empty($body['startTime']) ? date('Y-m-d H:i:s', strtotime((string)$body['startTime'])) : date('Y-m-d H:i:s');

    db()->prepare(
        'INSERT INTO rentals
         (txn_no, member_email, member_no, name, bike_id, course, helmet, locker, start_at, return_expected, status, total_paid, extra_paid, memo)
         VALUES (?,?,?,?,?,?,?,?,?,?, "active", ?, 0, ?)'
    )->execute([
        $txn, $email, (string)($body['memberId'] ?? ''), (string)($body['name'] ?? ''),
        (string)($body['bike'] ?? ''), (string)($body['course'] ?? ''),
        !empty($body['helmet']) ? 1 : 0, !empty($body['locker']) ? 1 : 0,
        $startAt, !empty($body['returnTime']) ? fmt_time((string)$body['returnTime']) : null,
        (int)($body['totalPaid'] ?? 0), (string)($body['memo'] ?? ''),
    ]);
    return ['success' => true, 'txnId' => $txn];
}

function updateRental(array $body): array
{
    $txn = (string)($body['txnId'] ?? '');
    if ($txn === '') return ['success' => false, 'error' => 'txnId required'];
    $stmt = db()->prepare('SELECT 1 FROM rentals WHERE txn_no = ? LIMIT 1');
    $stmt->execute([$txn]);
    if (!$stmt->fetchColumn()) return ['success' => false, 'error' => 'txnId not found'];

    $sets = ['status = ?'];
    $args = [(string)($body['status'] ?? 'returned')];
    if (array_key_exists('extraPaid', $body))  { $sets[] = 'extra_paid = ?';  $args[] = (int)$body['extraPaid']; }
    if (array_key_exists('returnedAt', $body)) { $sets[] = 'returned_at = ?'; $args[] = $body['returnedAt'] ? date('Y-m-d H:i:s', strtotime((string)$body['returnedAt'])) : null; }
    $args[] = $txn;
    db()->prepare('UPDATE rentals SET ' . implode(', ', $sets) . ' WHERE txn_no = ?')->execute($args);
    return ['success' => true, 'txnId' => $txn];
}

function saveSpecialDays(array $body): array
{
    $open  = isset($body['specialOpen'])  ? trim((string)$body['specialOpen'])  : '';
    $close = isset($body['specialClose']) ? trim((string)$body['specialClose']) : '';
    save_settings(['specialOpen' => $open, 'specialClose' => $close]);
    return ['success' => true];
}

// ============================================================
//  会員・認証（現行 handanotane の移植）
// ============================================================
function getMember(string $id): array
{
    if ($id === '') return ['found' => false, 'error' => 'id required'];
    $row = member_by_no($id);
    if (!$row) return ['found' => false];
    $obj = build_member_object($row);
    $obj['found'] = true;
    return $obj;
}

/**
 * 次に付与するカード番号の候補（既存の数値カード番号の最大+1 から連番）。
 * 旧GASはフォーム回答シートQ列の採番を使っていたが、MySQL移行後は
 * 「未付与の会員に、空いている番号を順に提案する」方式にする（スタッフが上書き可）。
 */
function next_card_numbers(int $count): array
{
    $max = 0;
    foreach (db()->query("SELECT member_no FROM members WHERE member_no IS NOT NULL")->fetchAll() as $r) {
        $n = normalize_member_no((string)$r['member_no']);
        if (preg_match('/^\d+$/', $n)) $max = max($max, (int)$n);
    }
    $out = [];
    for ($i = 1; $i <= $count; $i++) $out[] = $max + $i;
    return $out;
}

/**
 * PENDING（カード未付与）会員の一覧。事務局(staff)も取得できる。
 * カード付与に必要な最小限の項目のみ返す。
 */
function getPendingMembers(): array
{
    $rows = db()->query(
        "SELECT * FROM members WHERE member_no IS NULL OR member_no = '' OR member_no = 'PENDING'
         ORDER BY registered_at"
    )->fetchAll();
    $suggest = next_card_numbers(count($rows));
    $pending = [];
    foreach ($rows as $i => $r) {
        $o = build_member_object($r);
        $pending[] = [
            'id' => '', 'cardAssigned' => false, 'suggestedNo' => $suggest[$i] ?? null,
            'fullName' => $o['fullName'], 'familyName' => $o['familyName'], 'firstName' => $o['firstName'],
            'phone' => $o['phone'], 'email' => $o['email'],
            'isMinor' => $o['isMinor'], 'since' => $o['since'], 'hasPassword' => $o['hasPassword'],
        ];
    }
    return ['pending' => $pending, 'pendingCount' => count($pending)];
}

/**
 * 会員一覧（管理者用）。
 * ★ 旧GAS getMemberListFull と同じ契約: members=カード付与済み / pending=未付与（suggestedNo付き）
 */
function getMemberList(bool $full): array
{
    $rows = db()->query(
        "SELECT * FROM members WHERE member_no IS NOT NULL AND member_no <> '' AND member_no <> 'PENDING'
         ORDER BY registered_at"
    )->fetchAll();
    $members = [];
    foreach ($rows as $r) {
        $o = build_member_object($r);
        if ($full) {
            $o['kanaFamily'] = (string)($r['kana_family'] ?? '');
            $o['kanaFirst']  = (string)($r['kana_first'] ?? '');
            $o['birthDate'] = (string)($r['birth_date'] ?? '');
            $o['company']   = (string)($r['company'] ?? '');
            $o['zip']       = (string)($r['zip'] ?? '');
            $o['address1']  = (string)($r['address1'] ?? '');
            $o['address2']  = (string)($r['address2'] ?? '');
            $o['qualified'] = (int)($r['qualified'] ?? 0) === 1;
            $o['agreed']    = (int)($r['agreed'] ?? 0) === 1;
            $o['memo']      = (string)($r['memo'] ?? '');
        }
        $members[] = $o;
    }
    // 未付与（PENDING）は pending 側に入れて返す（管理画面のカード付与バナーが参照）
    $p = getPendingMembers();
    return ['members' => $members, 'count' => count($members),
            'pending' => $p['pending'], 'pendingCount' => $p['pendingCount']];
}

function loginMember(array $body): array
{
    $email = mb_strtolower(trim((string)($body['email'] ?? '')));
    $pw    = (string)($body['password'] ?? '');
    if ($email === '' || $pw === '') return ['success' => false, 'error' => 'メールアドレスとパスワードを入力してください'];
    $row = member_by_email($email);
    if (!$row) return ['success' => false, 'error' => 'メールアドレスまたはパスワードが正しくありません'];
    $hash = (string)($row['password_hash'] ?? '');
    if ($hash === '') return ['success' => false, 'hasPassword' => false, 'error' => 'パスワードが未設定です。初回登録を行ってください'];
    if (sha256_hex($pw) !== $hash) return ['success' => false, 'error' => 'メールアドレスまたはパスワードが正しくありません'];
    return ['success' => true, 'member' => build_member_object($row)];
}

function sendVerification(array $body): array
{
    global $CONFIG;
    $email = mb_strtolower(trim((string)($body['email'] ?? '')));
    if ($email === '') return ['success' => false, 'error' => 'メールアドレスを入力してください'];
    $row = member_by_email($email);
    if (!$row) return ['success' => true, 'note' => 'not_found']; // 存在を明かさない
    if (!empty($row['password_hash'])) {
        return ['success' => false, 'alreadySet' => true, 'error' => 'このメールアドレスはすでに登録済みです。ログインするか、パスワード変更をご利用ください'];
    }
    $token = issue_password_token($email);
    $ok = send_password_mail($email, trim(((string)$row['family_name']) . ' ' . ((string)$row['first_name'])), $token);
    if (!$ok) return ['success' => false, 'error' => 'メール送信に失敗しました。しばらくしてから再度お試しください'];
    return ['success' => true];
}

/**
 * パスワードを忘れた場合の再設定リクエスト（ログイン画面の「パスワードを忘れた方」）。
 *   - 公開エンドポイント（認証不要）。会員が存在すれば再設定用トークンを発行しメール送信。
 *   - メールアドレスの存在有無は明かさない（アカウント列挙を防ぐため常に同じ応答）。
 *   - 実際の新パスワード設定は password.php → setPasswordByToken で行う（既存基盤を再利用）。
 */
function requestPasswordReset(array $body): array
{
    $email = mb_strtolower(trim((string)($body['email'] ?? '')));
    if ($email !== '') {
        $row = member_by_email($email);
        if ($row) {
            $token = issue_password_token($email);
            $name  = trim(((string)$row['family_name']) . ' ' . ((string)$row['first_name']));
            @send_password_mail($email, $name, $token, 'reset');
        }
    }
    // 存在の有無に関わらず同じ応答（列挙防止）
    return ['success' => true, 'note' => 'ご登録のメールアドレスであれば、再設定用のリンクを送信しました'];
}

function setPasswordByToken(array $body): array
{
    $token = (string)($body['token'] ?? '');
    $pw    = (string)($body['password'] ?? '');
    if ($token === '' || $pw === '') return ['success' => false, 'error' => '入力が不足しています'];
    if (mb_strlen($pw) < 6) return ['success' => false, 'error' => 'パスワードは6文字以上にしてください'];

    $stmt = db()->prepare('SELECT * FROM auth_tokens WHERE token = ? LIMIT 1');
    $stmt->execute([$token]);
    $t = $stmt->fetch();
    if (!$t) return ['success' => false, 'error' => '無効なトークンです'];
    if ((int)$t['used'] === 1) return ['success' => false, 'error' => 'このリンクはすでに使用済みです'];
    if (strtotime((string)$t['expires_at']) < time()) return ['success' => false, 'error' => 'リンクの有効期限が切れています。再度メールを送信してください'];

    $email = (string)$t['email'];
    $m = member_by_email($email);
    if (!$m) return ['success' => false, 'error' => '会員情報が見つかりません'];
    db()->prepare('UPDATE members SET password_hash = ? WHERE email = ?')->execute([sha256_hex($pw), $email]);
    db()->prepare('UPDATE auth_tokens SET used = 1 WHERE id = ?')->execute([$t['id']]);
    $m = member_by_email($email);
    return ['success' => true, 'member' => build_member_object($m)];
}

function changePassword(array $body): array
{
    $email = mb_strtolower(trim((string)($body['email'] ?? '')));
    $cur   = (string)($body['currentPassword'] ?? '');
    $new   = (string)($body['newPassword'] ?? '');
    if ($email === '' || $cur === '' || $new === '') return ['success' => false, 'error' => '入力が不足しています'];
    if (mb_strlen($new) < 6) return ['success' => false, 'error' => 'パスワードは6文字以上にしてください'];
    $row = member_by_email($email);
    if (!$row) return ['success' => false, 'error' => 'メールアドレスが見つかりません'];
    if (sha256_hex($cur) !== (string)$row['password_hash']) return ['success' => false, 'error' => '現在のパスワードが正しくありません'];
    db()->prepare('UPDATE members SET password_hash = ? WHERE email = ?')->execute([sha256_hex($new), $email]);
    return ['success' => true];
}

function assignCard(array $body, string $admin): array
{
    $email  = mb_strtolower(trim((string)($body['email'] ?? '')));
    $cardId = trim((string)($body['cardId'] ?? ''));
    if ($email === '')  return ['success' => false, 'error' => 'メールアドレスが必要です'];
    if ($cardId === '') return ['success' => false, 'error' => 'カード番号が必要です'];

    // カード番号の重複チェック
    $stmt = db()->prepare('SELECT family_name, first_name FROM members WHERE member_no = ? LIMIT 1');
    $stmt->execute([$cardId]);
    if ($ex = $stmt->fetch()) {
        $nm = trim(((string)$ex['family_name']) . ' ' . ((string)$ex['first_name']));
        return ['success' => false, 'error' => 'カード番号 ' . $cardId . ' はすでに「' . $nm . '」様に付与されています'];
    }
    $m = member_by_email($email);
    if (!$m) return ['success' => false, 'error' => 'メールアドレスが見つかりません: ' . $email];
    db()->prepare('UPDATE members SET member_no = ? WHERE email = ?')->execute([$cardId, $email]);
    // この会員の過去のWEB予約（カード未発行時に作られ member_no が空/PENDING のもの）に
    // 実カード番号を backfill。email が一致する未確定の予約を実番号へ揃える。
    db()->prepare(
        "UPDATE bookings SET member_no = ?
         WHERE member_email = ? AND (member_no = '' OR member_no = 'PENDING')"
    )->execute([$cardId, $email]);
    log_member_change($admin, 'assignCard', $email, $cardId,
        ['member_no' => ['from' => (string)($m['member_no'] ?? ''), 'to' => $cardId]]);
    $nm = trim(((string)$m['family_name']) . ' ' . ((string)$m['first_name']));
    return ['success' => true, 'cardId' => $cardId, 'fullName' => $nm, 'email' => $email];
}

/**
 * メールで会員を1件だけ照合（受付キオスク用）。
 * 会員一覧を丸ごと配らないための専用エンドポイント（個人情報の露出を最小化）。
 */
function searchByEmail(string $email): array
{
    $email = mb_strtolower(trim($email));
    if ($email === '') return ['found' => false, 'error' => 'email required'];
    $row = member_by_email($email);
    if (!$row) return ['found' => false];
    $o = build_member_object($row);
    return ['found' => true, 'id' => $o['id'], 'fullName' => $o['fullName'],
            'memberNo' => $o['memberNo'], 'cardAssigned' => $o['cardAssigned']];
}

// ============================================================
//  管理者認証（config.php の admin_users で「ログインできる人」を限定）
// ============================================================
function adminLogin(array $body): array
{
    global $CONFIG;
    $user = trim((string)($body['user'] ?? ''));
    $pass = (string)($body['password'] ?? '');
    if ($user === '' || $pass === '') return ['success' => false, 'error' => 'IDとパスワードを入力してください'];

    // ① DBの管理者アカウント（bcrypt）→ ② config.php の admin_users（SHA-256・レスキュー用）
    $ok = false;
    $role = 'admin';
    $stmt = db()->prepare('SELECT password_hash, active, role FROM admin_accounts WHERE username = ? LIMIT 1');
    $stmt->execute([$user]);
    if ($row = $stmt->fetch()) {
        if ((int)$row['active'] !== 1) return ['success' => false, 'error' => 'このアカウントは無効です'];
        $ok = password_verify($pass, (string)$row['password_hash']);
        if ($ok) {
            $role = (string)($row['role'] ?: 'admin');
            db()->prepare('UPDATE admin_accounts SET last_login_at = NOW() WHERE username = ?')->execute([$user]);
        }
    }
    if (!$ok) {
        $users = $CONFIG['admin_users'] ?? [];
        $hash  = isset($users[$user]) ? (string)$users[$user] : '';
        // ユーザー不在でも比較時間を揃える（存在有無を推測させない）
        $ok = ($hash !== '' && hash_equals($hash, sha256_hex($pass)));
    }
    if (!$ok) return ['success' => false, 'error' => 'IDまたはパスワードが正しくありません'];
    $token = bin2hex(random_bytes(24)); // 48桁
    $min   = (int)($CONFIG['admin_session_minutes'] ?? 720);
    db()->prepare('INSERT INTO admin_sessions (token, admin_user, expires_at) VALUES (?,?,?)')
        ->execute([$token, $user, date('Y-m-d H:i:s', time() + $min * 60)]);
    // 期限切れセッションの掃除
    db()->exec('DELETE FROM admin_sessions WHERE expires_at < NOW()');
    return ['success' => true, 'token' => $token, 'user' => $user, 'role' => $role];
}

function adminLogout(array $auth): array
{
    $token = (string)($auth['adminToken'] ?? '');
    if ($token !== '') db()->prepare('DELETE FROM admin_sessions WHERE token = ?')->execute([$token]);
    return ['success' => true];
}

/** ログイン状態の確認（管理画面の起動時チェック用） */
function adminMe(array $auth): array
{
    $i = admin_identity($auth);
    return $i === null ? ['success' => false, 'needAdmin' => true]
                       : ['success' => true, 'user' => $i['user'], 'role' => $i['role']];
}

/**
 * トークンからログイン中のアカウントを解決。['user'=>..,'role'=>'admin'|'staff'] / 無効なら null。
 * config.php の admin_users は常に admin 扱い（レスキュー用）。
 */
function admin_identity(array $auth): ?array
{
    $token = (string)($auth['adminToken'] ?? '');
    if ($token === '') return null;
    $stmt = db()->prepare('SELECT admin_user FROM admin_sessions WHERE token = ? AND expires_at > NOW() LIMIT 1');
    $stmt->execute([$token]);
    $u = $stmt->fetchColumn();
    if (!$u) return null;
    db()->prepare('UPDATE admin_sessions SET last_used_at = NOW() WHERE token = ?')->execute([$token]);
    $r = db()->prepare('SELECT role FROM admin_accounts WHERE username = ? LIMIT 1');
    $r->execute([$u]);
    $role = (string)($r->fetchColumn() ?: 'admin');   // DBに無い＝configのレスキュー管理者
    return ['user' => (string)$u, 'role' => $role];
}

/** トークンから管理者名のみ解決（後方互換）。 */
function admin_user(array $auth): ?string
{
    $i = admin_identity($auth);
    return $i ? $i['user'] : null;
}

/** ログイン必須（admin / staff どちらでも可）。事務局のカード付与などに使う。 */
function require_staff(array $auth): array
{
    $i = admin_identity($auth);
    if ($i === null) {
        respond(['success' => false, 'error' => 'ログインが必要です', 'needAdmin' => true]);
    }
    return $i;
}

/** 管理者(admin)必須。staff では拒否する。 */
function require_admin(array $auth): string
{
    $i = require_staff($auth);
    if ($i['role'] !== 'admin') {
        respond(['success' => false, 'error' => 'この操作には管理者権限が必要です（事務局アカウントではできません）', 'forbidden' => true]);
    }
    return $i['user'];
}

// ============================================================
//  管理者アカウントの管理（管理者のみ・操作は監査ログに記録）
// ============================================================
/** 管理者アカウント操作の監査ログ */
function log_admin_change(string $admin, string $action, string $target, string $detail): void
{
    db()->prepare(
        'INSERT INTO admin_change_logs (admin_user, action, target_user, detail, ip) VALUES (?,?,?,?,?)'
    )->execute([$admin, $action, $target, $detail, (string)($_SERVER['REMOTE_ADDR'] ?? '')]);
}

/** 一覧（DBの管理者＋config のレスキュー用アカウント。パスワードは返さない） */
function listAdmins(): array
{
    global $CONFIG;
    $admins = [];
    foreach (db()->query('SELECT * FROM admin_accounts ORDER BY username')->fetchAll() as $r) {
        $admins[] = [
            'username' => $r['username'], 'displayName' => $r['display_name'],
            'role' => (string)($r['role'] ?: 'admin'),
            'active' => (int)$r['active'] === 1, 'createdAt' => $r['created_at'],
            'createdBy' => $r['created_by'], 'lastLoginAt' => $r['last_login_at'],
            'source' => 'db', 'editable' => true,
        ];
    }
    // config.php 側（画面からは編集不可。サーバのファイルでのみ変更できる）
    foreach (array_keys($CONFIG['admin_users'] ?? []) as $u) {
        $admins[] = [
            'username' => $u, 'displayName' => '(config.php)', 'role' => 'admin', 'active' => true,
            'createdAt' => '', 'createdBy' => '', 'lastLoginAt' => null,
            'source' => 'config', 'editable' => false,
        ];
    }
    return ['admins' => $admins, 'count' => count($admins)];
}

/** 追加 */
function addAdmin(array $body, string $admin): array
{
    global $CONFIG;
    $user = trim((string)($body['username'] ?? ''));
    $pass = (string)($body['password'] ?? '');
    $name = trim((string)($body['displayName'] ?? ''));
    $role = (string)($body['role'] ?? 'admin');
    if (!in_array($role, ['admin', 'staff'], true)) $role = 'staff';
    if (!preg_match('/^[A-Za-z0-9._-]{3,60}$/', $user)) {
        return ['success' => false, 'error' => 'IDは半角英数字と . _ - の3〜60文字にしてください'];
    }
    if (mb_strlen($pass) < 8) return ['success' => false, 'error' => 'パスワードは8文字以上にしてください'];

    $stmt = db()->prepare('SELECT 1 FROM admin_accounts WHERE username = ? LIMIT 1');
    $stmt->execute([$user]);
    if ($stmt->fetchColumn()) return ['success' => false, 'error' => 'そのIDは既に登録されています'];
    if (isset(($CONFIG['admin_users'] ?? [])[$user])) {
        return ['success' => false, 'error' => 'そのIDは config.php で予約されています'];
    }

    db()->prepare(
        'INSERT INTO admin_accounts (username, password_hash, display_name, role, created_by) VALUES (?,?,?,?,?)'
    )->execute([$user, password_hash($pass, PASSWORD_DEFAULT), $name, $role, $admin]);
    log_admin_change($admin, 'add', $user, $name . '（' . $role . '）');
    return ['success' => true, 'username' => $user, 'role' => $role];
}

/** 変更（表示名・有効/無効・パスワード再設定） */
function updateAdmin(array $body, string $admin): array
{
    $user = trim((string)($body['username'] ?? ''));
    if ($user === '') return ['success' => false, 'error' => '対象のIDが必要です'];
    $stmt = db()->prepare('SELECT * FROM admin_accounts WHERE username = ? LIMIT 1');
    $stmt->execute([$user]);
    $row = $stmt->fetch();
    if (!$row) return ['success' => false, 'error' => 'その管理者は見つかりません（config.php のアカウントは画面から変更できません）'];

    $sets = []; $args = []; $detail = [];
    if (array_key_exists('displayName', $body)) {
        $sets[] = 'display_name = ?'; $args[] = trim((string)$body['displayName']); $detail[] = '表示名';
    }
    if (array_key_exists('role', $body)) {
        $role = (string)$body['role'];
        if (!in_array($role, ['admin', 'staff'], true)) return ['success' => false, 'error' => '権限の指定が不正です'];
        // 自分自身の降格と、最後の admin の降格は禁止（ロックアウト防止）
        if ($role !== 'admin' && (string)$row['role'] === 'admin') {
            if ($user === $admin) return ['success' => false, 'error' => '自分自身の管理者権限は外せません'];
            $n = (int)db()->query("SELECT COUNT(*) FROM admin_accounts WHERE role = 'admin' AND active = 1")->fetchColumn();
            if ($n <= 1) return ['success' => false, 'error' => '管理者が居なくなるため権限を外せません'];
        }
        if ((string)$row['role'] !== $role) {
            $sets[] = 'role = ?'; $args[] = $role;
            $detail[] = '権限: ' . $row['role'] . '→' . $role;
        }
    }
    if (array_key_exists('active', $body)) {
        $active = !empty($body['active']) ? 1 : 0;
        // 自分自身の無効化と、最後の有効管理者の無効化は禁止（ロックアウト防止）
        if ($active === 0) {
            if ($user === $admin) return ['success' => false, 'error' => '自分自身を無効化することはできません'];
            if ((string)$row['role'] === 'admin') {
                $n = (int)db()->query("SELECT COUNT(*) FROM admin_accounts WHERE role = 'admin' AND active = 1")->fetchColumn();
                if ($n <= 1) return ['success' => false, 'error' => '有効な管理者が居なくなるため無効化できません'];
            }
        }
        $sets[] = 'active = ?'; $args[] = $active; $detail[] = $active ? '有効化' : '無効化';
    }
    if (!empty($body['newPassword'])) {
        $pw = (string)$body['newPassword'];
        if (mb_strlen($pw) < 8) return ['success' => false, 'error' => 'パスワードは8文字以上にしてください'];
        $sets[] = 'password_hash = ?'; $args[] = password_hash($pw, PASSWORD_DEFAULT); $detail[] = 'パスワード再設定';
    }
    if (!$sets) return ['success' => true, 'note' => 'no changes'];

    $args[] = $user;
    db()->prepare('UPDATE admin_accounts SET ' . implode(', ', $sets) . ' WHERE username = ?')->execute($args);
    log_admin_change($admin, in_array('パスワード再設定', $detail, true) ? 'password' : 'update', $user, implode(' / ', $detail));
    return ['success' => true, 'username' => $user, 'changed' => $detail];
}

/** 削除（自分自身・最後の1人は不可） */
function deleteAdmin(array $body, string $admin): array
{
    $user = trim((string)($body['username'] ?? ''));
    if ($user === '') return ['success' => false, 'error' => '対象のIDが必要です'];
    if ($user === $admin) return ['success' => false, 'error' => '自分自身は削除できません'];

    $stmt = db()->prepare('SELECT display_name FROM admin_accounts WHERE username = ? LIMIT 1');
    $stmt->execute([$user]);
    $row = $stmt->fetch();
    if (!$row) return ['success' => false, 'error' => 'その管理者は見つかりません（config.php のアカウントは画面から削除できません）'];

    $n = (int)db()->query('SELECT COUNT(*) FROM admin_accounts')->fetchColumn();
    if ($n <= 1) return ['success' => false, 'error' => '管理者が居なくなるため削除できません'];

    db()->prepare('DELETE FROM admin_accounts WHERE username = ?')->execute([$user]);
    db()->prepare('DELETE FROM admin_sessions WHERE admin_user = ?')->execute([$user]); // ログイン中なら失効
    log_admin_change($admin, 'delete', $user, (string)$row['display_name']);
    return ['success' => true, 'username' => $user];
}

function getAdminChangeLogs(): array
{
    $logs = [];
    foreach (db()->query('SELECT * FROM admin_change_logs ORDER BY id DESC LIMIT 200')->fetchAll() as $r) {
        $logs[] = ['at' => $r['at'], 'adminUser' => $r['admin_user'], 'action' => $r['action'],
                   'targetUser' => $r['target_user'], 'detail' => $r['detail']];
    }
    return ['logs' => $logs, 'count' => count($logs)];
}

/** 会員変更の監査ログを記録 */
function log_member_change(string $admin, string $action, string $email, string $memberNo, array $changes): void
{
    db()->prepare(
        'INSERT INTO member_change_logs (admin_user, action, member_email, member_no, changes, ip)
         VALUES (?,?,?,?,?,?)'
    )->execute([
        $admin, $action, $email, $memberNo,
        $changes ? json_encode($changes, JSON_UNESCAPED_UNICODE) : null,
        (string)($_SERVER['REMOTE_ADDR'] ?? ''),
    ]);
}

/**
 * 会員カルテ: 登録情報＋その会員の予約/利用履歴＋サマリー。
 *   紐付けは不変キー member_email。事務局(staff)も閲覧可。
 */
function getMemberProfile(string $email): array
{
    $email = mb_strtolower(trim($email));
    if ($email === '') return ['found' => false, 'error' => 'email required'];
    $row = member_by_email($email);
    if (!$row) return ['found' => false];

    $member = build_member_object($row);
    $member['kanaFamily'] = (string)($row['kana_family'] ?? '');
    $member['kanaFirst']  = (string)($row['kana_first'] ?? '');
    $member['birthDate']  = (string)($row['birth_date'] ?? '');
    $member['company']    = (string)($row['company'] ?? '');
    $member['zip']        = (string)($row['zip'] ?? '');
    $member['address1']   = (string)($row['address1'] ?? '');
    $member['address2']   = (string)($row['address2'] ?? '');
    $member['memo']       = (string)($row['memo'] ?? '');

    // 予約（新しい順）
    $bs = db()->prepare(
        "SELECT booking_no, bike_id, DATE_FORMAT(date,'%Y-%m-%d') AS date,
                TIME_FORMAT(start_time,'%H:%i') AS st, TIME_FORMAT(end_time,'%H:%i') AS et,
                status, course, total_paid
         FROM bookings WHERE member_email = ? ORDER BY date DESC, start_time DESC"
    );
    $bs->execute([$email]);
    $bookings = [];
    $bkConfirmed = 0; $bkCancelled = 0; $bkUpcoming = 0;
    $today = date('Y-m-d');
    foreach ($bs->fetchAll() as $r) {
        $bookings[] = [
            'bookingId' => $r['booking_no'], 'bikeId' => $r['bike_id'], 'date' => $r['date'],
            'startTime' => $r['st'], 'endTime' => $r['et'], 'status' => $r['status'],
            'course' => $r['course'], 'totalPaid' => (int)$r['total_paid'],
        ];
        if ($r['status'] === 'cancelled') { $bkCancelled++; }
        else { $bkConfirmed++; if ($r['date'] >= $today) $bkUpcoming++; }
    }

    // 利用記録（新しい順）
    $rs = db()->prepare("SELECT * FROM rentals WHERE member_email = ? ORDER BY start_at DESC");
    $rs->execute([$email]);
    $rentals = [];
    $rentalCount = 0; $activeCount = 0; $totalPaid = 0; $lastVisit = '';
    foreach ($rs->fetchAll() as $r) {
        $rentals[] = rentalRow($r);
        $rentalCount++;
        if ($r['status'] === 'active') $activeCount++;
        $totalPaid += (int)$r['total_paid'] + (int)$r['extra_paid'];
        $when = $r['returned_at'] ?: $r['start_at'];
        if ($when && (string)$when > $lastVisit) $lastVisit = (string)$when;
    }

    return [
        'found' => true,
        'member' => $member,
        'summary' => [
            'lastVisit'     => $lastVisit,
            'rentalCount'   => $rentalCount,
            'activeCount'   => $activeCount,
            'totalPaid'     => $totalPaid,
            'bookingCount'  => $bkConfirmed,
            'cancelledCount'=> $bkCancelled,
            'upcomingCount' => $bkUpcoming,
        ],
        'bookings' => $bookings,
        'rentals'  => $rentals,
    ];
}

/**
 * パスワード設定メールの再送（管理画面から。未設定の会員向け）。
 * 事務局(staff)も実行可。誰が再送したか監査ログに記録する。
 */
function resendPasswordSetup(array $body, string $admin): array
{
    $email = mb_strtolower(trim((string)($body['email'] ?? '')));
    if ($email === '') return ['success' => false, 'error' => '対象のメールアドレスが必要です'];
    $m = member_by_email($email);
    if (!$m) return ['success' => false, 'error' => '会員が見つかりません'];
    if (!empty($m['password_hash'])) {
        return ['success' => false, 'error' => 'この会員はすでにパスワードを設定済みです（変更はご本人のパスワード変更から）'];
    }
    $token = issue_password_token($email);
    $name  = trim(((string)$m['family_name']) . ' ' . ((string)$m['first_name']));
    $ok = send_password_mail($email, $name, $token);
    if (!$ok) return ['success' => false, 'error' => 'メール送信に失敗しました。しばらくしてから再度お試しください'];
    log_member_change($admin, 'resendMail', $email, (string)($m['member_no'] ?? ''),
        ['passwordSetupMail' => ['from' => '', 'to' => '再送']]);
    return ['success' => true, 'email' => $email];
}

function getMemberChangeLogs(string $email): array
{
    if ($email !== '') {
        $stmt = db()->prepare('SELECT * FROM member_change_logs WHERE member_email = ? ORDER BY id DESC LIMIT 200');
        $stmt->execute([mb_strtolower(trim($email))]);
    } else {
        $stmt = db()->query('SELECT * FROM member_change_logs ORDER BY id DESC LIMIT 200');
    }
    $logs = [];
    foreach ($stmt->fetchAll() as $r) {
        $logs[] = [
            'at' => $r['at'], 'adminUser' => $r['admin_user'], 'action' => $r['action'],
            'email' => $r['member_email'], 'memberNo' => $r['member_no'],
            'changes' => $r['changes'] ? json_decode((string)$r['changes'], true) : null,
        ];
    }
    return ['logs' => $logs, 'count' => count($logs)];
}

// ============================================================
//  会員の編集・削除（管理者のみ・変更は監査ログに記録）
// ============================================================
function updateMember(array $body, string $admin): array
{
    $email = mb_strtolower(trim((string)($body['email'] ?? '')));   // 対象（変更前のemail）
    if ($email === '') return ['success' => false, 'error' => '対象のメールアドレスが必要です'];
    $m = member_by_email($email);
    if (!$m) return ['success' => false, 'error' => '会員が見つかりません: ' . $email];

    // 編集可能な列（email は newEmail で別途扱う）
    $map = [
        'familyName' => 'family_name', 'firstName' => 'first_name',
        'kanaFamily' => 'kana_family', 'kanaFirst'  => 'kana_first',
        'birthDate'  => 'birth_date',  'company'    => 'company',
        'zip'        => 'zip',         'address1'   => 'address1', 'address2' => 'address2',
        'phone'      => 'phone',       'memo'       => 'memo',
        'memberNo'   => 'member_no',
    ];
    $sets = [];
    $args = [];
    $changes = [];
    foreach ($map as $in => $col) {
        if (!array_key_exists($in, $body)) continue;
        $new = trim((string)$body[$in]);
        if ($col === 'birth_date') {
            $new = $new === '' ? null : $new;
        }
        if ($col === 'member_no') {
            $new = $new === '' ? null : $new;
            // カード番号の重複チェック（自分以外）
            if ($new !== null) {
                $q = db()->prepare('SELECT email FROM members WHERE member_no = ? AND email <> ? LIMIT 1');
                $q->execute([$new, $email]);
                if ($q->fetchColumn()) return ['success' => false, 'error' => 'カード番号 ' . $new . ' は他の会員に付与されています'];
            }
        }
        $old = $m[$col];
        $oldS = $old === null ? '' : (string)$old;
        $newS = $new === null ? '' : (string)$new;
        if ($oldS === $newS) continue;
        $sets[] = "$col = ?";
        $args[] = $new;
        $changes[$col] = ['from' => $oldS, 'to' => $newS];
    }

    // 真偽値の列
    foreach (['isMinor' => 'is_minor', 'qualified' => 'qualified', 'agreed' => 'agreed', 'mailOpt' => 'mail_opt'] as $in => $col) {
        if (!array_key_exists($in, $body)) continue;
        $new = !empty($body[$in]) ? 1 : 0;
        if ((int)$m[$col] === $new) continue;
        $sets[] = "$col = ?";
        $args[] = $new;
        $changes[$col] = ['from' => (string)(int)$m[$col], 'to' => (string)$new];
    }

    // メールアドレス変更（予約・利用記録の紐付けも連動更新）
    $newEmail = isset($body['newEmail']) ? mb_strtolower(trim((string)$body['newEmail'])) : '';
    $emailChanged = ($newEmail !== '' && $newEmail !== $email);
    if ($emailChanged) {
        if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) return ['success' => false, 'error' => 'メールアドレスの形式が正しくありません'];
        if (member_by_email($newEmail)) return ['success' => false, 'error' => 'そのメールアドレスは既に使われています'];
        $sets[] = 'email = ?';
        $args[] = $newEmail;
        $changes['email'] = ['from' => $email, 'to' => $newEmail];
    }

    if (!$sets) return ['success' => true, 'note' => 'no changes'];

    db()->beginTransaction();
    try {
        $args[] = $email;
        db()->prepare('UPDATE members SET ' . implode(', ', $sets) . ' WHERE email = ?')->execute($args);
        if ($emailChanged) {
            // 不変キーとして使っている member_email を追従させる
            db()->prepare('UPDATE bookings SET member_email = ? WHERE member_email = ?')->execute([$newEmail, $email]);
            db()->prepare('UPDATE rentals  SET member_email = ? WHERE member_email = ?')->execute([$newEmail, $email]);
            db()->prepare('UPDATE auth_tokens SET email = ? WHERE email = ?')->execute([$newEmail, $email]);
        }
        log_member_change($admin, 'update', $email, (string)($m['member_no'] ?? ''), $changes);
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }
    $after = member_by_email($emailChanged ? $newEmail : $email);
    return ['success' => true, 'member' => build_member_object($after), 'changed' => array_keys($changes)];
}

function deleteMember(array $body, string $admin): array
{
    $email = mb_strtolower(trim((string)($body['email'] ?? '')));
    if ($email === '') return ['success' => false, 'error' => '対象のメールアドレスが必要です'];
    $m = member_by_email($email);
    if (!$m) return ['success' => false, 'error' => '会員が見つかりません: ' . $email];

    // 履歴がある会員は削除しない（誤削除防止・集計を壊さない）
    $bq = db()->prepare('SELECT COUNT(*) FROM bookings WHERE member_email = ?');
    $bq->execute([$email]);
    $bCount = (int)$bq->fetchColumn();
    $rq = db()->prepare('SELECT COUNT(*) FROM rentals WHERE member_email = ?');
    $rq->execute([$email]);
    $rCount = (int)$rq->fetchColumn();
    if ($bCount > 0 || $rCount > 0) {
        return ['success' => false, 'hasHistory' => true, 'bookings' => $bCount, 'rentals' => $rCount,
                'error' => '予約' . $bCount . '件・利用記録' . $rCount . '件があるため削除できません。履歴を残す必要があるため、削除ではなく情報の修正をご検討ください'];
    }

    db()->beginTransaction();
    try {
        db()->prepare('DELETE FROM auth_tokens WHERE email = ?')->execute([$email]);
        db()->prepare('DELETE FROM members WHERE email = ?')->execute([$email]);
        log_member_change($admin, 'delete', $email, (string)($m['member_no'] ?? ''), [
            'deleted' => ['from' => trim(((string)$m['family_name']) . ' ' . ((string)$m['first_name'])), 'to' => ''],
        ]);
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }
    return ['success' => true, 'email' => $email];
}
