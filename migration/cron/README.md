# cron: 本日の予約を Discord 通知

`discord_daily.php` は、**本日（JST）の予約があれば** Discord に「予約者名・利用時間・車両」を投稿します。予約が無ければ何も送りません。毎朝 8:00 に実行する想定です。

## 1. Discord Webhook を用意

Discord の対象チャンネル → 「連携サービス」→「ウェブフックを作成」→ URL をコピー。

## 2. config.php に URL を設定

`api/config.php`（本番のみ・コミットしない）に追記:

```php
'discord_webhook' => 'https://discord.com/api/webhooks/xxxx/yyyy',
```

## 3. さくらのコントロールパネルで cron 登録（毎朝 8:00）

**推奨（CLI・秘密情報を晒さない）:**

```
0 8 * * *   php %HOME%/www/wp/looper_reservation/cron/discord_daily.php
```

※ さくらの PHP CLI パスが必要な場合は `/usr/local/bin/php` 等を明示:
```
0 8 * * *   /usr/local/bin/php %HOME%/www/wp/looper_reservation/cron/discord_daily.php
```

**HTTP で叩く場合（curl）:** `?secret=` に config の `shared_secret` を渡す。

```
0 8 * * *   curl -s "https://handanotane.com/looper_reservation/cron/discord_daily.php?secret=<shared_secret>" > /dev/null
```

## 動作確認（手動実行）

```
php cron/discord_daily.php
```

- 予約あり → Discord に投稿し `Discord送信OK (HTTP 204) 予約N件` を出力
- 予約なし → 送信せず `本日(YYYY-MM-DD)の予約はありません。通知しません。`
- `discord_webhook` 未設定 → 送信せず、送信予定の本文を標準出力に表示

## 出力例（Discord 投稿内容）

```
📅 本日の予約 2026-07-26（3件）
1. 10:00〜12:00　海辺 太郎 様　【Looper （ブラック）】　会員#42
2. 13:00〜15:00　山 次郎 様　【e-Looper （ブルー）】
3. 15:30〜17:00　川 花子 様　【Looper （グリーン）】　会員#8
```

## セキュリティ

- HTTP 実行は `shared_secret` 必須（不一致は 403）。
- 個人情報（氏名・会員番号）を Discord に送るため、**通知先チャンネルは事務局限定**にすること。
