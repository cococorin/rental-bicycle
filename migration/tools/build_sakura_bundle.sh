#!/usr/bin/env bash
# ============================================================
# さくらデプロイ用バンドル生成（案①: 同一オリジン配信）
#   本番 https://handanotane.com/looper_reservation/ にそのまま上げられる形で、
#   API URL を差し替えたHTML＋PHP一式を deploy/looper_reservation/ に組み立てる。
#
#   ★live の GitHub Pages（master のHTML）は変更しない。バンドルは gitignore 済み。
#   使い方: cd migration && bash tools/build_sakura_bundle.sh
# ============================================================
set -euo pipefail

MIG="$(cd "$(dirname "$0")/.." && pwd)"   # migration/
ROOT="$(cd "$MIG/.." && pwd)"             # apps/looper/
OUT="$MIG/deploy/looper_reservation"

# 現行GAS URL（3HTML共通） → さくら本番 API URL
OLD='https://script.google.com/macros/s/AKfycbytH4cMYLZ4eP60yW3el-YDgkOziz3gkjmxtb_Mz-EsFc3xvOtj0t4jJwRpl27M7Oop/exec'
NEW='https://handanotane.com/looper_reservation/api/index.php'

rm -rf "$OUT"
mkdir -p "$OUT/api" "$OUT/webhook"

# 1) HTML（API URL を差し替えて配置）
for f in looper_booking.html looper_admin.html looper_reception.html; do
  sed "s#${OLD}#${NEW}#g" "$ROOT/$f" > "$OUT/$f"
done

# 2) PHP（config.php は含めない＝さくらで config.sample.php からコピーして作成）
cp "$MIG/api/config.sample.php" "$MIG/api/db.php" "$MIG/api/helpers.php" \
   "$MIG/api/mail.php" "$MIG/api/index.php" "$MIG/api/.htaccess" "$OUT/api/"
cp "$MIG/webhook/member_upsert.php" "$MIG/webhook/export.php" "$OUT/webhook/"
cp "$MIG/password.php" "$OUT/"

# 3) ロゴ（メール/ページが参照。Pages URLでも動くが同梱して独立性を確保）
cp "$ROOT/looper-logo.jpg" "$OUT/" 2>/dev/null || echo "  (looper-logo.jpg 無し・スキップ)"

# 4) 差し替え確認
echo "=== 生成: $OUT ==="
echo "API URL 差し替え結果:"
grep -h "API_ENDPOINT" -A1 "$OUT"/looper_*.html | grep "index.php" | sort -u | sed 's/^/  /'
echo ""
echo "=== 構成 ==="
( cd "$OUT" && find . -type f | sort )
echo ""
echo "次: この looper_reservation/ を さくらの公開領域へアップロードし、"
echo "    api/config.sample.php を api/config.php にコピーして実値を記入すること。"
