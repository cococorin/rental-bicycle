-- ============================================================
-- アップグレード 001: 車種の表示定義を DB に一元化
--   予約画面・受付画面にハードコードされていた「カラー名／文字色／サブ表記」を
--   bikes テーブルへ集約し、全画面が API 経由で同じ表示を使うようにする。
--
--   実行: mysql -h <host> -u <user> -p <db> < upgrades/001_bike_display.sql
--   ※ ADD COLUMN は MySQL では IF NOT EXISTS が使えないため、2回目の実行は
--     「Duplicate column name」エラーになる（無害。UPDATE 部分だけ再実行可）。
-- ============================================================
SET NAMES utf8mb4;

ALTER TABLE bikes
  ADD COLUMN color VARCHAR(16) NOT NULL DEFAULT '' AFTER type,
  ADD COLUMN sub   VARCHAR(40) NOT NULL DEFAULT '' AFTER color;

-- 受付タブレットの表記に合わせて確定値を投入（label もカラー名に更新）
INSERT INTO bikes (bike_id, label, type, color, sub, sort) VALUES
  ('LOOPER-1',  'Looper （ブラック）',   'looper',  '#1a1a1a', '普通自転車 26インチ',  1),
  ('LOOPER-2',  'Looper （グリーン）',   'looper',  '#2E7D32', '普通自転車 26インチ',  2),
  ('eLOOPER-1', 'e-Looper （ブルー）',   'elooper', '#185FA5', '電動アシスト 20インチ', 3),
  ('eLOOPER-2', 'e-Looper （ベージュ）', 'elooper', '#8D7355', '電動アシスト 20インチ', 4)
ON DUPLICATE KEY UPDATE label=VALUES(label), type=VALUES(type),
  color=VALUES(color), sub=VALUES(sub), sort=VALUES(sort);

SELECT bike_id, label, type, color, sub, sort FROM bikes ORDER BY sort;
