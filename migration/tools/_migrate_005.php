<?php
// 一回だけ実行する本番マイグレーション: rentals に finalized 列を追加（既にあれば無害にスキップ）。
// 実行後はサーバから削除する。docroot 直下に置き `php _migrate_005.php` で実行する想定。
$cfg = require __DIR__ . '/api/config.php';
$pdo = new PDO(
    "mysql:host={$cfg['db_host']};dbname={$cfg['db_name']};charset=utf8mb4",
    $cfg['db_user'], $cfg['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
try {
    $pdo->exec("ALTER TABLE rentals ADD COLUMN finalized TINYINT NOT NULL DEFAULT 0 AFTER memo");
    echo "finalized 列を追加しました\n";
} catch (Throwable $e) {
    echo "スキップ（既に存在等）: " . $e->getMessage() . "\n";
}
$col = $pdo->query("SHOW COLUMNS FROM rentals LIKE 'finalized'")->fetch();
echo $col ? "確認: finalized 列あり\n" : "確認: finalized 列なし（要調査）\n";
