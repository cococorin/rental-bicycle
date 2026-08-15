<?php
// ============================================================
// 本日の予約を Discord に通知する（毎朝8時 cron 実行想定）
//   - 本日(JST)の非キャンセル予約を start_time 順に取得
//   - 予約があれば Discord Webhook へ「予約者名・利用時間・車両」を投稿
//   - 予約が無ければ何も送らない（「予約が入っている場合は通知」の仕様）
//
// 実行方法:
//   CLI（推奨・秘密情報を晒さない）:
//     php /home/.../looper_reservation/cron/discord_daily.php
//   HTTP（cron から curl する場合）:
//     curl -s "https://handanotane.com/looper_reservation/cron/discord_daily.php?secret=<shared_secret>"
//
// 設定: api/config.php に 'discord_webhook' => 'https://discord.com/api/webhooks/...'
// ============================================================
declare(strict_types=1);

require __DIR__ . '/../api/db.php';   // db() と $CONFIG（Asia/Tokyo 設定込み）を読み込む
global $CONFIG;

$isCli = (PHP_SAPI === 'cli');

// HTTP 経由のときは shared_secret を要求（Web から誰でも叩けないように）
if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
    $secret = (string)($_GET['secret'] ?? '');
    if ($secret === '' || !hash_equals((string)($CONFIG['shared_secret'] ?? ''), $secret)) {
        http_response_code(403);
        echo "forbidden\n";
        exit;
    }
}

$webhook = trim((string)($CONFIG['discord_webhook'] ?? ''));
$today   = date('Y-m-d');   // db.php で Asia/Tokyo 設定済み

// 本日の非キャンセル予約（車両表示名は bikes テーブルの label を正とする）
$stmt = db()->prepare(
    "SELECT b.name, b.member_no,
            TIME_FORMAT(b.start_time, '%H:%i') AS st,
            TIME_FORMAT(b.end_time,   '%H:%i') AS et,
            COALESCE(NULLIF(k.label, ''), b.bike_id) AS bike_label
     FROM bookings b
     LEFT JOIN bikes k ON k.bike_id = b.bike_id
     WHERE b.date = ? AND b.status <> 'cancelled'
     ORDER BY b.start_time, b.bike_id"
);
$stmt->execute([$today]);
$rows = $stmt->fetchAll();

if (!$rows) {
    echo "本日({$today})の予約はありません。通知しません。\n";
    exit;
}

// ユーザー入力（予約者名など）を Discord 投稿に混ぜる際のサニタイズ。
//   - URL/メンション/コードブロック/改行を無害化し、スパムや @everyone 巻き込みを防ぐ。
$clean = function (string $s): string {
    $s = preg_replace('#https?://\S+#i', '[リンク削除]', $s);   // URL を除去
    $s = str_replace(['@', '`', "\n", "\r", '|'], ['＠', "'", ' ', ' ', '/'], $s); // メンション/整形記号を無害化
    $s = preg_replace('/\s{2,}/u', ' ', trim($s));
    return mb_substr($s, 0, 40);                                 // 長さ制限
};

// メッセージ組み立て
$lines = [];
$i = 0;
foreach ($rows as $r) {
    $i++;
    $name = ($r['name'] !== '') ? $clean((string)$r['name']) : '(お名前未登録)';
    $card = ($r['member_no'] !== '') ? '　会員#' . $clean((string)$r['member_no']) : '';
    $lines[] = sprintf('%d. %s〜%s　%s 様　【%s】%s', $i, $r['st'], $r['et'], $name, $clean((string)$r['bike_label']), $card);
}
$content = "📅 **本日の予約 " . $today . "**（" . count($rows) . "件）\n" . implode("\n", $lines);

// Discord の content は 2000 文字上限。長い場合は安全側に切り詰める。
if (mb_strlen($content) > 1900) {
    $content = mb_substr($content, 0, 1900) . "\n…（以下省略）";
}

if ($webhook === '') {
    echo "discord_webhook が未設定です。送信内容:\n" . $content . "\n";
    exit;
}

$payload = json_encode(
    [
        'content'         => $content,
        'username'        => 'Looper 予約bot',
        'allowed_mentions'=> ['parse' => []], // @everyone/@here/ロール等の巻き込みを無効化
        'flags'           => 4,               // SUPPRESS_EMBEDS: リンクプレビューを出さない
    ],
    JSON_UNESCAPED_UNICODE
);

$ch = curl_init($webhook);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
]);
$res  = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

if ($code >= 200 && $code < 300) {
    echo "Discord送信OK (HTTP {$code}) 予約" . count($rows) . "件\n";
} else {
    http_response_code(500);
    echo "Discord送信失敗 HTTP={$code} err={$err} res={$res}\n";
}
