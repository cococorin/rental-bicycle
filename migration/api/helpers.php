<?php
// ============================================================
// 共通ヘルパー（現行 GAS ロジックの移植）
//   設定ロード・営業日判定・重複チェック・会員照会・時刻ユーティリティ
// ============================================================
declare(strict_types=1);

const DEFAULT_SETTINGS = [
    'openTime' => '11:00', 'closeTime' => '18:00', 'bufferMinutes' => 60,
    'closedDays' => [3], 'price3h' => 500, 'priceDay' => 800,
    'ePrice3h' => 800, 'ePriceDay' => 1200, 'lockerPrice' => 300, 'overPrice' => 200,
    // 超過料金は車種別（サーバ設定を正とする）。通常=overPriceL / e-Looper=overPriceE。
    'overPriceL' => 300, 'overPriceE' => 500,
    'notifyEmail' => '', 'specialOpen' => [], 'specialClose' => [],
];

/**
 * settings テーブルを読み、現行 loadSettings と同じ型付きの連想配列を返す。
 */
function load_settings(): array
{
    $s = DEFAULT_SETTINGS;
    $rows = db()->query('SELECT skey, sval FROM settings')->fetchAll();
    $intKeys = ['bufferMinutes','price3h','priceDay','ePrice3h','ePriceDay','lockerPrice','overPrice','overPriceL','overPriceE'];
    foreach ($rows as $r) {
        $key = trim((string)$r['skey']);
        $val = $r['sval'];
        if ($key === '') continue;
        if ($key === 'closedDays') {
            $s['closedDays'] = array_values(array_filter(array_map(
                fn($v) => is_numeric(trim($v)) ? (int)trim($v) : null,
                explode(',', (string)$val)
            ), fn($n) => $n !== null));
        } elseif (in_array($key, $intKeys, true)) {
            $s[$key] = (int)$val;
        } elseif ($key === 'specialOpen' || $key === 'specialClose') {
            $raw = trim((string)$val);
            $s[$key] = $raw === '' ? [] : array_values(array_filter(
                array_map('trim', explode(',', $raw)),
                fn($v) => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)
            ));
        } else {
            $s[$key] = $val;
        }
    }
    return $s;
}

/**
 * settings を upsert（現行 saveSettings 相当。渡されたキーのみ更新）。
 */
function save_settings(array $body): void
{
    $stmt = db()->prepare(
        'INSERT INTO settings (skey, sval) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE sval = VALUES(sval)'
    );
    foreach ($body as $key => $val) {
        if ($key === 'action' || $key === 'callback') continue;
        $sval = is_array($val) ? implode(',', $val) : (string)$val;
        $stmt->execute([$key, $sval]);
    }
}

/**
 * 指定日が休業日か（優先: 特別休業 > 特別営業 > 通常定休日）。
 */
function is_closed_day(string $dateStr, array $settings): bool
{
    if (in_array($dateStr, $settings['specialClose'] ?? [], true)) return true;
    if (in_array($dateStr, $settings['specialOpen'] ?? [], true))  return false;
    $dow = (int)date('w', strtotime($dateStr . ' 01:00:00')); // 0=日〜6=土
    return in_array($dow, $settings['closedDays'] ?? [], true);
}

function day_name(int $d): string
{
    $names = ['日','月','火','水','木','金','土'];
    return $names[$d] ?? '';
}

// --- 時刻ユーティリティ（現行 tMin / addMinToTime / formatTime 相当）---
function t_min(string $t): int
{
    $p = explode(':', $t);
    return ((int)($p[0] ?? 0)) * 60 + ((int)($p[1] ?? 0));
}
function add_min_to_time(string $t, int $m): string
{
    $total = t_min($t) + $m;
    return sprintf('%02d:%02d', intdiv($total, 60), $total % 60);
}
function fmt_time($val): string
{
    $s = trim((string)$val);
    if (preg_match('/^(\d{1,2}):(\d{2})/', $s, $m)) {
        return sprintf('%02d:%s', (int)$m[1], $m[2]);
    }
    return $s;
}

/**
 * 予約番号採番（現行 genBookingId 相当）。BK-YYYYMMDD-XXXX。
 */
function gen_booking_id(): string
{
    $suffix = strtoupper(substr(base_convert((string)random_int(0, PHP_INT_MAX), 10, 36), -4));
    $suffix = str_pad($suffix, 4, '0', STR_PAD_LEFT);
    return 'BK-' . date('Ymd') . '-' . $suffix;
}

/**
 * 認証トークン採番（32桁英数字。現行 generateToken 相当）。
 */
function gen_token(): string
{
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    $t = '';
    for ($i = 0; $i < 32; $i++) {
        $t .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $t;
}

/**
 * トークンを発行して auth_tokens に保存（30分有効）。トークン文字列を返す。
 */
function issue_password_token(string $email): string
{
    $token = gen_token();
    $expires = date('Y-m-d H:i:s', time() + 30 * 60);
    db()->prepare('INSERT INTO auth_tokens (token, email, expires_at, used) VALUES (?,?,?,0)')
        ->execute([$token, mb_strtolower(trim($email)), $expires]);
    return $token;
}

/**
 * 予約重複チェック（清掃バッファ込み。現行 checkConflict 相当）。
 * 競合があれば {bookingId,start,end,bufferEnd} を返す。無ければ null。
 */
function check_conflict(string $bikeId, string $date, string $start, string $end, int $buffer): ?array
{
    $stmt = db()->prepare(
        "SELECT booking_no, TIME_FORMAT(start_time,'%H:%i') AS s, TIME_FORMAT(end_time,'%H:%i') AS e
         FROM bookings
         WHERE bike_id = ? AND date = ? AND status <> 'cancelled'"
    );
    $stmt->execute([$bikeId, $date]);
    $newStart = t_min($start);
    $newEnd   = t_min($end);
    $newBuf   = $newEnd + $buffer;
    foreach ($stmt->fetchAll() as $row) {
        $exS   = t_min($row['s']);
        $exE   = t_min($row['e']);
        $exBuf = $exE + $buffer;
        if ($newStart < $exBuf && $newBuf > $exS) {
            return [
                'bookingId' => $row['booking_no'],
                'start'     => $row['s'],
                'end'       => $row['e'],
                'bufferEnd' => add_min_to_time($row['e'], $buffer),
            ];
        }
    }
    return null;
}

// --- 会員照会 ---
function member_by_email(string $email): ?array
{
    $stmt = db()->prepare('SELECT * FROM members WHERE email = ? LIMIT 1');
    $stmt->execute([mb_strtolower(trim($email))]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * カード番号（会員番号）を正規化（現行 normalizeMemberId と同一規則）。
 * L- 除去 + 数値は5桁ゼロ埋め。
 */
function normalize_member_no(string $id): string
{
    $s = trim($id);
    if (str_starts_with($s, 'L-')) $s = substr($s, 2);
    if (preg_match('/^\d+$/', $s)) return str_pad((string)(int)$s, 5, '0', STR_PAD_LEFT);
    return $s;
}

function member_by_no(string $memberNo): ?array
{
    $needle = normalize_member_no($memberNo);
    // 正規化して突合するため全件から探す（会員数は小規模）
    $stmt = db()->query('SELECT * FROM members WHERE member_no IS NOT NULL');
    foreach ($stmt->fetchAll() as $row) {
        if (normalize_member_no((string)$row['member_no']) === $needle) return $row;
    }
    return null;
}

/**
 * 会員オブジェクト整形（現行 handanotane buildMemberObject 相当）。
 *   id は「カード番号があればそれ、無ければ PENDING」＝現行フロント互換。
 */
function build_member_object(array $row): array
{
    $fn = (string)($row['family_name'] ?? '');
    $gn = (string)($row['first_name'] ?? '');
    $cardNo = $row['member_no'] !== null && $row['member_no'] !== '' ? (string)$row['member_no'] : null;
    return [
        'id'           => $cardNo ?? 'PENDING',
        'memberNo'     => $cardNo ?? '',
        'cardAssigned' => $cardNo !== null,
        'familyName'   => $fn,
        'firstName'    => $gn,
        'fullName'     => trim($fn . ' ' . $gn),
        'fullNameKana' => trim(((string)($row['kana_family'] ?? '')) . ' ' . ((string)($row['kana_first'] ?? ''))),
        'email'        => (string)($row['email'] ?? ''),
        'phone'        => (string)($row['phone'] ?? ''),
        'isMinor'      => (int)($row['is_minor'] ?? 0) === 1,
        'since'        => (string)($row['registered_at'] ?? ''),
        'hasPassword'  => !empty($row['password_hash']),
    ];
}
