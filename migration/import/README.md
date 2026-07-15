# データ移行（現行スプレッドシート → MySQL）

現行の2スプレッドシートを CSV エクスポートし、`tools/normalize_csv_for_mysql.py` で
新スキーマ（`schema.sql`）向けの投入SQL（`import/import.sql`）を生成して投入する。

> ⚠️ **CSV と生成SQL は会員 PII（氏名・メール・電話・ハッシュ）を含む。コミットしない**
> （`import/csv/*.csv` と `import/import.sql` は `.gitignore` 済み）。

## 1. CSV を書き出す

各シートを **「ファイル → ダウンロード → カンマ区切り値(.csv)」** で書き出し、
`import/csv/` に以下の名前で置く（1行目ヘッダーのまま／文字コード UTF-8）。

| ファイル名 | 元シート | 列（順序どおり） |
|---|---|---|
| `members.csv`  | handanotane「会員」 | A会員番号 B姓 C名 Dカナ姓 Eカナ名 F生年月日 G会社 H郵便 I住所① J住所② K携帯 Lメール M規約同意 N10歳以上 O16歳未満 P登録日時 Qメモ Rパスワードハッシュ |
| `bookings.csv` | ikewaki「予約」 | A予約番号 B会員番号 C氏名 D自転車ID E日付 F開始 G終了 Hステータス Iコース J前払額 Kメモ L予約日時 |
| `rentals.csv`  | ikewaki「利用記録」 | A取引番号 B会員番号 C氏名 D車種 Eコース Fヘルメット Gロッカー H開始日時 I返却予定 Jステータス K前払額 L追加精算 M返却日時 Nメモ |
| `settings.csv` | ikewaki「設定」 | A設定キー B値（C説明は無視） |

※ 無いファイルはスキップされる。列順が上記と一致していることが重要（ヘッダー名ではなく位置で読む）。

## 2. 投入SQLを生成

```
cd migration
python tools/normalize_csv_for_mysql.py --in import/csv --out import/import.sql
```

標準エラーに「スキップ/集約」の注意が出る。特に **同一メールの重複会員は
『カード有 > パスワード有 > 登録が新しい』で1件に自動集約**される（内容を確認すること）。

**自動で行う正規化**
- 会員: メール小文字化・一意化、`会員番号=PENDING/空 → member_no=NULL`、ハッシュ空→NULL、真偽値/日付整形。
- 予約/利用: 自転車ID正規化（`Looper-1`→`LOOPER-1` 等）、日時 ISO(UTC)→JST 変換。
- 予約/利用の `member_email` は、**カード番号で会員を突合して自動補完**（末尾の UPDATE）。
  カード発行前（PENDING時代）の予約は突合できず空のまま（履歴のため許容。今後の新規は不変キーで正しく紐付く）。

## 3. MySQL へ投入

```
# 先に schema.sql（無ければ）
mysql -h <host> -u <user> -p <db> < schema.sql
# 続いてデータ
mysql -h <host> -u <user> -p <db> < import/import.sql
```

## 4. 投入後の確認（例）

```sql
SELECT COUNT(*) FROM members;
SELECT COUNT(*) FROM bookings;
SELECT COUNT(*) FROM rentals;
-- カード番号が付いているのに member_email が空の予約（PENDING時代・要確認）
SELECT booking_no, member_no FROM bookings WHERE member_email = '' AND member_no <> '';
-- 重複カード番号（本来一意。異常があれば手動確認）
SELECT member_no, COUNT(*) c FROM members WHERE member_no IS NOT NULL GROUP BY member_no HAVING c > 1;
```

## 補足

- 認証トークン（handanotane「認証トークン」）は一時データのため移行不要。
- パスワードハッシュは現行と同じ **SHA-256** なので、既存会員はそのままログイン可能。
- ローカル検証: `laragon` の MySQL 8.4 で「schema→import→確認」まで実施済み。
