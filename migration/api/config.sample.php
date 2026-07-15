<?php
// ============================================================
// Looper API 設定ファイルのサンプル
//   実値を入れて config.php としてコピーし、Web公開領域の外に置くか、
//   最低限 .htaccess で直アクセスを禁止すること。
//   ★このファイル（config.sample.php）にはダミー値のみ。秘密情報は入れない。
//   ★config.php は Git にコミットしない（.gitignore 済み）。
// ============================================================

return [
    // --- MySQL 接続（さくらの管理画面で発行される値）---
    'db_host'    => 'mysqlXXX.db.sakura.ne.jp',
    'db_name'    => 'your_db_name',
    'db_user'    => 'your_db_user',
    'db_pass'    => 'CHANGE_ME',
    'db_charset' => 'utf8mb4',

    // --- webhook 用の共有シークレット（フォーム同期の認証）---
    //     GAS 側 Script Property SHARED_SECRET と一致させる。
    'shared_secret' => 'CHANGE_ME_LONG_RANDOM_STRING',

    // --- CORS（案①=HTMLも同一ドメイン配信なら空配列でよい。別ドメインなら許可オリジンを列挙）---
    'cors_allow_origins' => [],   // 案①（handanotane.com 同一オリジン）では空でOK

    // --- メール送信（さくらSMTP / PHP mail）---
    'mail_mode'  => 'php',                          // 'php'（さくらSMTP）/ 'off'
    'mail_from'  => 'noreply@handanotane.com',      // 差出人（handanotane.com のアドレス）
    'mail_from_name' => 'まちなかレンタサイクル Looper',

    // --- パスワード設定ページの基底URL（メールのリンク先）---
    'verify_url' => 'https://handanotane.com/looper_reservation/password.php',

    // --- 管理画面 Basic 認証を使う場合の許可（任意・.htaccess 側で制御も可）---
    'admin_ip_allow' => [],   // 空なら制限なし（.htaccess/Basic認証に委ねる）
];
