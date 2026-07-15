-- ============================================================
-- アップグレード 004: 管理者の権限を2段階にする
--   admin … 会員情報の編集・削除、管理者アカウント管理まで可能
--   staff … 事務局メンバー。PENDING会員の確認と「会員番号（カード）付与」のみ可能
--            会員の編集・削除、管理者管理はできない。
--
--   ※ config.php の admin_users は常に admin 扱い（レスキュー用）。
--
--   実行: mysql -h <host> -u <user> -p <db> < upgrades/004_admin_roles.sql
--   ※ 2回目の実行は「Duplicate column name 'role'」エラーになる（無害）。
-- ============================================================
SET NAMES utf8mb4;

ALTER TABLE admin_accounts
  ADD COLUMN role VARCHAR(16) NOT NULL DEFAULT 'admin' AFTER display_name;

-- 既存アカウントはこれまでどおり admin のまま
UPDATE admin_accounts SET role = 'admin' WHERE role = '' OR role IS NULL;

SELECT username, display_name, role, active FROM admin_accounts ORDER BY username;
