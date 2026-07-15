-- ============================================================
-- まちなかレンタサイクル「Looper」  MySQL スキーマ（移行版）
-- さくらインターネット レンタルサーバの MySQL を想定
-- 文字コードは utf8mb4、照合は utf8mb4_unicode_ci
-- ============================================================
-- 現行は 2 つの Google スプレッドシート（ikewaki=予約/利用記録/設定、
-- handanotane=会員/認証トークン）を DB 代わりに使っていた。これを 1 つの
-- MySQL に統合する。列コメントに現行シートの対応列（A,B,C...）を明記する。
--
-- 【設計方針（現行の不具合を是正）】
--  * 会員の不変キーは email（＝ログインID）。予約/利用記録は member_email で
--    会員に紐付ける。物理カード番号 member_no は members の別カラムに持つ。
--    → カード発行前後で会員↔予約の突合がズレる問題（旧「バグ④」）を解消。
--  * 現行の 'PENDING' 共有IDは廃止（member_no は未発行なら NULL）。
-- ============================================================

SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- 会員（現行 handanotane「会員」シート A〜R列）
--   Google フォーム登録 → webhook で upsert。email が一意キー。
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS members (
  id            BIGINT       NOT NULL AUTO_INCREMENT,       -- 内部PK（不変）
  email         VARCHAR(255) NOT NULL,                      -- L: メールアドレス（ログインID・一意）
  member_no     VARCHAR(20)  NULL,                          -- A: 物理カード番号（発行後にセット。未発行=NULL）
  family_name   VARCHAR(60)  NOT NULL DEFAULT '',           -- B: 姓
  first_name    VARCHAR(60)  NOT NULL DEFAULT '',           -- C: 名
  kana_family   VARCHAR(60)  NOT NULL DEFAULT '',           -- D: フリガナ（セイ）
  kana_first    VARCHAR(60)  NOT NULL DEFAULT '',           -- E: フリガナ（メイ）
  birth_date    DATE         NULL,                          -- F: 生年月日
  company       VARCHAR(120) NOT NULL DEFAULT '',           -- G: 会社名/学校名
  zip           VARCHAR(16)  NOT NULL DEFAULT '',           -- H: 郵便番号
  address1      VARCHAR(160) NOT NULL DEFAULT '',           -- I: 住所①
  address2      VARCHAR(160) NOT NULL DEFAULT '',           -- J: 住所②
  phone         VARCHAR(24)  NOT NULL DEFAULT '',           -- K: 携帯電話番号
  agreed        TINYINT      NOT NULL DEFAULT 0,            -- M: 利用規約同意
  qualified     TINYINT      NOT NULL DEFAULT 0,            -- N: 10歳以上・身長145cm以上
  is_minor      TINYINT      NOT NULL DEFAULT 0,            -- O: 16歳未満
  memo          VARCHAR(255) NOT NULL DEFAULT '',           -- Q: メモ
  password_hash CHAR(64)     NULL,                          -- R: パスワードハッシュ（SHA-256。未設定=NULL）
  mail_opt      TINYINT      NOT NULL DEFAULT 1,            -- メール受信可否（1=受信）
  registered_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP, -- P: 登録日時
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_email (email),
  UNIQUE KEY uq_member_no (member_no),                      -- カード番号は一意（NULLは重複可）
  KEY idx_name (family_name, first_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 認証トークン（現行 handanotane「認証トークン」シート A〜D列）
--   パスワード設定リンク用。フォーム登録直後に発行し、メール送信する。
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS auth_tokens (
  id          BIGINT       NOT NULL AUTO_INCREMENT,
  token       CHAR(32)     NOT NULL,                        -- A: トークン（32桁）
  email       VARCHAR(255) NOT NULL,                        -- B: メールアドレス
  expires_at  DATETIME     NOT NULL,                        -- C: 有効期限
  used        TINYINT      NOT NULL DEFAULT 0,              -- D: 使用済（1=済）
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_token (token),
  KEY idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 自転車マスタ（現行はコード内 BIKES 定数）
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS bikes (
  bike_id   VARCHAR(20)  NOT NULL,                          -- 例: LOOPER-1 / eLOOPER-1
  label     VARCHAR(40)  NOT NULL DEFAULT '',               -- 表示名（LOOPER ① 等）
  type      VARCHAR(16)  NOT NULL DEFAULT 'looper',         -- looper / elooper
  sort      INT          NOT NULL DEFAULT 0,
  active    TINYINT      NOT NULL DEFAULT 1,
  PRIMARY KEY (bike_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 予約（現行 ikewaki「予約」シート A〜L列）
--   ※ 会員紐付けは member_email（不変）。member_no/name は表示用スナップショット。
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS bookings (
  id            BIGINT       NOT NULL AUTO_INCREMENT,       -- 行識別子（旧「予約番号」は booking_no に保持）
  booking_no    VARCHAR(24)  NOT NULL,                      -- A: 予約番号（BK-YYYYMMDD-XXXX）
  member_email  VARCHAR(255) NOT NULL DEFAULT '',           -- 会員の不変キー（紐付け用）
  member_no     VARCHAR(20)  NOT NULL DEFAULT '',           -- B: 会員番号（スナップショット）
  name          VARCHAR(120) NOT NULL DEFAULT '',           -- C: 氏名（スナップショット）
  bike_id       VARCHAR(20)  NOT NULL,                      -- D: 自転車ID
  date          DATE         NOT NULL,                      -- E: 日付
  start_time    TIME         NOT NULL,                      -- F: 開始時刻
  end_time      TIME         NOT NULL,                      -- G: 終了時刻
  status        VARCHAR(12)  NOT NULL DEFAULT 'confirmed',  -- H: ステータス（confirmed/cancelled）
  course        VARCHAR(8)   NOT NULL DEFAULT '',           -- I: コース（3h/1d）
  total_paid    INT          NOT NULL DEFAULT 0,            -- J: 前払額
  memo          VARCHAR(255) NOT NULL DEFAULT '',           -- K: メモ
  req_id        VARCHAR(48)  NULL,                          -- 冪等キー（重複予約/多重送信防止）
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP, -- L: 予約日時
  PRIMARY KEY (id),
  UNIQUE KEY uq_booking_no (booking_no),
  UNIQUE KEY uq_req_id (req_id),                            -- 同一 req_id の二重登録を DB で防止
  KEY idx_bike_date (bike_id, date, status),                -- 空き状況・重複チェック
  KEY idx_member (member_email),                            -- 会員別予約
  KEY idx_date (date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 利用記録（現行 ikewaki「利用記録」シート A〜N列）
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS rentals (
  id             BIGINT       NOT NULL AUTO_INCREMENT,
  txn_no         VARCHAR(24)  NOT NULL,                     -- A: 取引番号
  member_email   VARCHAR(255) NOT NULL DEFAULT '',          -- 会員の不変キー（紐付け用）
  member_no      VARCHAR(20)  NOT NULL DEFAULT '',          -- B: 会員番号（スナップショット）
  name           VARCHAR(120) NOT NULL DEFAULT '',          -- C: 氏名（スナップショット）
  bike_id        VARCHAR(20)  NOT NULL DEFAULT '',          -- D: 車種/自転車ID
  course         VARCHAR(8)   NOT NULL DEFAULT '',          -- E: コース
  helmet         TINYINT      NOT NULL DEFAULT 0,           -- F: ヘルメット
  locker         TINYINT      NOT NULL DEFAULT 0,           -- G: ロッカー
  start_at       DATETIME     NOT NULL,                     -- H: 開始日時
  return_expected TIME        NULL,                         -- I: 返却予定時刻
  status         VARCHAR(12)  NOT NULL DEFAULT 'active',    -- J: ステータス（active/returned）
  total_paid     INT          NOT NULL DEFAULT 0,           -- K: 前払額
  extra_paid     INT          NOT NULL DEFAULT 0,           -- L: 追加精算額
  returned_at    DATETIME     NULL,                         -- M: 返却日時
  memo           VARCHAR(255) NOT NULL DEFAULT '',          -- N: メモ
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_txn_no (txn_no),
  KEY idx_active (status),                                  -- getActiveRentals
  KEY idx_bike (bike_id, status),
  KEY idx_member (member_email),
  KEY idx_start (start_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 設定（現行 ikewaki「設定」シート key-value）
--   キー例: openTime/closeTime/bufferMinutes/closedDays/price3h/priceDay/
--           ePrice3h/ePriceDay/lockerPrice/overPrice/notifyEmail
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
  skey  VARCHAR(50) NOT NULL,
  sval  TEXT            NULL,
  PRIMARY KEY (skey)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 特別日 / 臨時休業（現行 saveSpecialDays 相当）
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS closures (
  date       DATE         NOT NULL,                         -- 対象日
  kind       VARCHAR(16)  NOT NULL DEFAULT 'closed',        -- closed=臨時休業 / special=特別営業時間
  open_time  TIME         NULL,                             -- special のとき開始時刻
  close_time TIME         NULL,                             -- special のとき終了時刻
  note       VARCHAR(120) NOT NULL DEFAULT '',
  PRIMARY KEY (date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 初期データ（自転車マスタ・既定設定）
-- ------------------------------------------------------------
INSERT INTO bikes (bike_id, label, type, sort) VALUES
  ('LOOPER-1',  'LOOPER ①',   'looper',  1),
  ('LOOPER-2',  'LOOPER ②',   'looper',  2),
  ('eLOOPER-1', 'e-LOOPER ①', 'elooper', 3),
  ('eLOOPER-2', 'e-LOOPER ②', 'elooper', 4)
ON DUPLICATE KEY UPDATE label=VALUES(label), type=VALUES(type), sort=VALUES(sort);

INSERT INTO settings (skey, sval) VALUES
  ('openTime','11:00'), ('closeTime','18:00'), ('bufferMinutes','60'),
  ('closedDays','3'),   ('price3h','500'),     ('priceDay','800'),
  ('ePrice3h','800'),   ('ePriceDay','1200'),  ('lockerPrice','300'),
  ('overPrice','200'),  ('notifyEmail','')
ON DUPLICATE KEY UPDATE sval=VALUES(sval);
