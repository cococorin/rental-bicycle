-- ============================================================
-- アップグレード 002: 管理者ログイン（限定）＋ 会員変更の監査ログ
--   * 管理者アカウントは config.php の admin_users（ID→SHA-256ハッシュ）で限定する。
--     → 「管理者としてログインできる人を限定」する要件。DBにパスワードは置かない。
--   * ログイン成功でトークンを発行し admin_sessions に保存。管理系APIはこれを必須にする。
--   * 会員の編集・削除・カード発行は member_change_logs に「誰が・いつ・何を」を記録する。
--
--   実行: mysql -h <host> -u <user> -p <db> < upgrades/002_admin_and_audit.sql
-- ============================================================
SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- 管理者セッション（ログインで発行するトークン）
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS admin_sessions (
  token       CHAR(48)     NOT NULL,                    -- セッショントークン
  admin_user  VARCHAR(60)  NOT NULL,                    -- config.php の admin_users のキー
  expires_at  DATETIME     NOT NULL,                    -- 有効期限
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used_at DATETIME        NULL,
  PRIMARY KEY (token),
  KEY idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 会員変更ログ（監査証跡）
--   member_email は変更前の値を保持（email 変更時も追跡できるように）
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS member_change_logs (
  id           BIGINT       NOT NULL AUTO_INCREMENT,
  at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  admin_user   VARCHAR(60)  NOT NULL DEFAULT '',        -- 実行した管理者
  action       VARCHAR(20)  NOT NULL,                   -- update / delete / assignCard
  member_email VARCHAR(255) NOT NULL DEFAULT '',        -- 対象会員（変更前のemail）
  member_no    VARCHAR(20)  NOT NULL DEFAULT '',        -- 対象の会員番号（判別用）
  changes      TEXT             NULL,                   -- 変更内容（JSON: {列:{from,to}}）
  ip           VARCHAR(45)  NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  KEY idx_member (member_email),
  KEY idx_at (at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
