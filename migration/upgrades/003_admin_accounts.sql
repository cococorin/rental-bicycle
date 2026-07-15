-- ============================================================
-- アップグレード 003: 管理者アカウントを画面から登録できるようにする
--   * admin_accounts … 管理画面から追加/変更/削除できる管理者（bcrypt保存）
--   * admin_change_logs … 管理者アカウント操作の監査ログ（誰が誰を追加/変更/削除したか）
--
--   ※ config.php の admin_users は「レスキュー用」として引き続き有効。
--     DBを壊しても・全管理者を消してもログインできる逃げ道を残す。
--
--   実行: mysql -h <host> -u <user> -p <db> < upgrades/003_admin_accounts.sql
-- ============================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS admin_accounts (
  username      VARCHAR(60)  NOT NULL,                    -- ログインID
  password_hash VARCHAR(255) NOT NULL,                    -- password_hash()（bcrypt）
  display_name  VARCHAR(80)  NOT NULL DEFAULT '',         -- 表示名（監査ログの可読性用）
  active        TINYINT      NOT NULL DEFAULT 1,          -- 0=無効（ログイン不可）
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by    VARCHAR(60)  NOT NULL DEFAULT '',         -- 追加した管理者
  last_login_at DATETIME         NULL,
  PRIMARY KEY (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_change_logs (
  id          BIGINT       NOT NULL AUTO_INCREMENT,
  at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  admin_user  VARCHAR(60)  NOT NULL DEFAULT '',           -- 実行者
  action      VARCHAR(20)  NOT NULL,                      -- add / update / password / delete
  target_user VARCHAR(60)  NOT NULL DEFAULT '',           -- 対象の管理者ID
  detail      VARCHAR(255) NOT NULL DEFAULT '',
  ip          VARCHAR(45)  NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  KEY idx_at (at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
