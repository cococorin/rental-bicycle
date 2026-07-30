-- ============================================================
-- アップグレード 005: 利用記録に「確定」フラグを追加
--   finalized = 1 の日は金額修正不可（管理ダッシュボードの「返却済み」→「本日の利用を確定する」で立てる）
--   実行: mysql -h <host> -u <user> -p <db> < upgrades/005_rental_finalized.sql
--   ※ 2回目の実行は「Duplicate column name 'finalized'」エラー（無害）。
-- ============================================================
SET NAMES utf8mb4;

ALTER TABLE rentals
  ADD COLUMN finalized TINYINT NOT NULL DEFAULT 0 AFTER memo;
