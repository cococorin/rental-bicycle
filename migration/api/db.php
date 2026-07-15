<?php
// ============================================================
// DB接続・共通ブートストラップ（Looper API）
// ============================================================
declare(strict_types=1);

date_default_timezone_set('Asia/Tokyo');

// 設定ロード（config.php が無ければ sample を使う＝開発時のみ）
$cfgPath = __DIR__ . '/config.php';
if (!is_file($cfgPath)) {
    $cfgPath = __DIR__ . '/config.sample.php';
}
$CONFIG = require $cfgPath;

/**
 * PDO を1本張って使い回す。JST 固定・例外モード。
 */
function db(): PDO
{
    static $pdo = null;
    global $CONFIG;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        $CONFIG['db_host'],
        $CONFIG['db_name'],
        $CONFIG['db_charset']
    );
    $pdo = new PDO($dsn, $CONFIG['db_user'], $CONFIG['db_pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    // セッションのタイムゾーンを JST に固定（DATETIME を JST で扱う）
    $pdo->exec("SET time_zone = '+09:00'");
    return $pdo;
}

/**
 * JSON を返して終了。GAS 版 jsonResponse と同じ形の応答を返す。
 * 現行フロントは JSONP(callback) も使うため、callback があれば JSONP で返す。
 */
function respond(array $result): void
{
    global $CONFIG;
    // 別ドメイン配信時のみ CORS 許可オリジンを返す（同一オリジンなら不要）
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin && in_array($origin, $CONFIG['cors_allow_origins'] ?? [], true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
    }
    $json = json_encode($result, JSON_UNESCAPED_UNICODE);
    $callback = $_REQUEST['callback'] ?? '';
    // JSONP: 現行 booking/reception は <script> 経由（callback付き）で叩く
    if ($callback !== '' && preg_match('/^[A-Za-z_$][\w$]*$/', $callback)) {
        header('Content-Type: application/javascript; charset=utf-8');
        echo $callback . '(' . $json . ')';
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
    echo $json;
    exit;
}

/**
 * SHA-256 16進（現行 GAS の sha256 と同一。既存パスワードハッシュと互換）。
 */
function sha256_hex(string $text): string
{
    return hash('sha256', $text);
}
