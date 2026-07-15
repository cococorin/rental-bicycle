# Looper  MySQL 移行キット（案B: PHP API + MySQL）

スプレッドシートを DB 代わりに使う現行システム（Google Apps Script ×2＋リレー）を、
**さくらのレンタルサーバ上の PHP + MySQL** へ置き換えるための一式。
コワーキング受付システムの移行キットと同じ流儀で構成する。

- **維持するもの**: Google フォームでの会員登録、既存の3フロント（`looper_booking.html` /
  `looper_admin.html` / `looper_reception.html`）。
- **改善点**:
  1. **速度・信頼性**: GAS Web App（1〜3秒・「通信エラー」多発）→ PHP 直叩き（<200ms）。
  2. **2 GAS＋リレーの解消**: ikewaki/handanotane の2本立て＋UrlFetchリレーを、単一 API に統合。
  3. **登録の一気通貫**: フォーム送信 → その場でパスワード設定メールを即送信（メール再入力を廃止）。
  4. **会員↔予約の紐付けを email（不変キー）に**。カード番号は別カラム（`member_no`）。
     → 現行の「PENDING 共有ID」「カード発行前予約が受付で選べない（旧バグ④）」を根本解消。

## ファイル構成

```
migration/
  schema.sql                 ... MySQL スキーマ（members/auth_tokens/bikes/bookings/rentals/settings/closures）
  api/
    config.sample.php        ... 設定サンプル（実値は config.php にコピー。コミット禁止）
    db.php                   ... PDO接続・JSON/JSONP応答・sha256
    helpers.php              ... 料金計算・営業時間/定休日・重複チェック等（現行GASの移植）
    index.php                ... ?action=... ルーター（現行フロントをそのまま受ける）
    .htaccess                ... config.php 直アクセス禁止
  webhook/
    member_upsert.php        ... フォーム会員登録の受け口（共有シークレット＋upsert＋設定メール即送信）
  gas/
    form_webhook.gs          ... onFormSubmit → PHP webhook へ POST（縮小版）
    mirror_to_sheet.gs       ... （任意）MySQL→スタッフ閲覧用シート 一方向ミラー
  password.php               ... パスワード設定ページ（メールのリンク先。トークン検証）
  README.md
```

## エンドポイント対応（現行 GAS action → PHP）

現行フロントの `?action=...`（JSONP/callback 付き）をそのまま受ける。ikewaki/handanotane の
両 GAS のアクションを 1 つの `api/index.php` に統合する。

| action | 用途 | 元GAS |
|---|---|---|
| getAvailability / getBookings / getSettings | 空き状況・予約一覧・設定 | ikewaki |
| getActiveRentals / getRentals / getAllRentals / getMonthlyCount | 利用記録・集計 | ikewaki |
| addBooking / cancelBooking | 予約追加（req_id 冪等）・取消 | ikewaki |
| addRental / updateRental | 利用開始・返却 | ikewaki |
| saveSettings / saveSpecialDays | 設定・特別日保存 | ikewaki |
| getMember / getMemberList / getMemberListFull | 会員照会・一覧 | handanotane |
| login / sendVerification / setPasswordByToken / changePassword | 認証 | handanotane |
| assignCard | カード番号付与 | handanotane |
| ping | 疎通 | 両方 |

## 登録の一気通貫フロー

1. 利用者が Google フォームで会員登録。
2. onFormSubmit → `gas/form_webhook.gs` が `webhook/member_upsert.php` へ POST（共有シークレット）。
3. `member_upsert.php`: `members` に upsert（email 一意）→ **その場で認証トークン発行 →
   さくらSMTPでパスワード設定メールを即送信**。
4. 利用者はメールのリンク（`password.php?token=...`）を開いてパスワードを設定 → 完了。
   → 「フォーム後に予約画面でメールを再入力」する手間が無くなる。

## セットアップ手順（概要）

1. さくらの MySQL を発行 → `mysql -h mysqlXXX.db.sakura.ne.jp -u USER -p DBNAME < schema.sql`。
2. `api/config.sample.php` を `api/config.php` にコピーし実値を記入（DB・shared_secret・mail・verify_url）。
   config.php はコミットしない。
3. `migration/` をさくらの公開領域へ配置（例: `~/www/looper/`）。HTTPS（Let's Encrypt）必須。
4. データ移行: 現行 2 スプレッドシートを CSV 出力 → schema に整形して投入
   （members は handanotane「会員」、bookings/rentals/settings は ikewaki 各シート）。
5. GAS 切替: フォームのトリガーを `syncMembersFromForm`（form_webhook.gs）に。Script Property に
   `PHP_WEBHOOK_URL` / `SHARED_SECRET` を設定。旧 handanotane の onFormSubmit は停止。
6. フロント切替: `looper_*.html` の `GAS_URL`/`DEFAULT_GAS_URL` を
   `https://<さくらのドメイン>/api/index.php` に差し替え（同一オリジンなら CORS 不要）。
7. 並行運用で件数・料金・空き状況を突き合わせ → 問題なければ旧 GAS を無効化。

## セキュリティ

- `config.php`・秘密情報は Web から読めない位置／`.htaccess` で保護（`api/.htaccess` 同梱）。
- `webhook/` は共有シークレット必須。
- 管理画面は Basic 認証 or 簡易トークンで保護（店外アクセス想定）。
- 全て HTTPS。パスワードは SHA-256（現行ハッシュと互換。将来 password_hash() への移行も可）。

## 要決定事項

1. パスワードハッシュ: 現行互換の SHA-256 を踏襲（本キットの既定）。将来 bcrypt 移行するか。
2. フォーム再登録時の挙動: email 既存なら「会員情報を更新（upsert）」か「スキップ」か。
3. スタッフ閲覧用の MySQL→Sheets ミラーを使うか（`mirror_to_sheet.gs`）。
4. 管理画面の認証強度（Basic か スタッフ別ログインか）。
