#!/usr/bin/env python3
# -*- coding: utf-8 -*-
# ============================================================
# 現行スプレッドシート CSV → MySQL 投入用 SQL 生成（Looper 移行）
#
#   現行2スプレッドシートを CSV エクスポートし、新スキーマ（schema.sql）へ
#   変換した INSERT 文と、予約/利用記録の member_email バックフィル UPDATE を
#   1本の import.sql に出力する。
#
#   変換要点:
#     * members: A会員番号→member_no（PENDING/空はNULL）、Lメール→email（小文字・一意）、
#                Rハッシュ→password_hash（空はNULL）、真偽値/日付を正規化。
#                ★同一メールの重複行は「カード有>PW有>登録新しい」で1件に集約。
#     * bookings/rentals: 自転車IDを正規化（Looper-1→LOOPER-1）、日時ISO(UTC)→JST、
#                member_no を保持し、後段 UPDATE で members.email から member_email を補完。
#     * settings: key-value をそのまま upsert。
#
#   使い方:
#     1) 各シートを CSV で書き出し、import/csv/ に置く（既定ファイル名は下記）:
#          members.csv   … handanotane「会員」シート（A〜R列）
#          bookings.csv  … ikewaki「予約」シート（A〜L列）
#          rentals.csv   … ikewaki「利用記録」シート（A〜N列）
#          settings.csv  … ikewaki「設定」シート（A:キー B:値）
#        ※ いずれも1行目はヘッダー（スキップ）。無いファイルはスキップ。
#     2) python tools/normalize_csv_for_mysql.py --in import/csv --out import/import.sql
#     3) mysql -h <host> -u <user> -p <db> < import/import.sql
# ============================================================
import argparse
import csv
import os
import re
import sys
from datetime import datetime, timedelta, timezone

JST = timezone(timedelta(hours=9))

BIKE_MAP = {
    'Looper-1': 'LOOPER-1', 'Looper-2': 'LOOPER-2',
    'e-Looper-1': 'eLOOPER-1', 'e-Looper-2': 'eLOOPER-2',
    'LOOPER-1': 'LOOPER-1', 'LOOPER-2': 'LOOPER-2',
    'eLOOPER-1': 'eLOOPER-1', 'eLOOPER-2': 'eLOOPER-2',
}


# ---- SQL リテラル ----
def q(v):
    """文字列を MySQL リテラルに。None/空は 'NULL' ではなく空文字列 ''（列がNOT NULL DEFAULT ''のため）。"""
    if v is None:
        return "''"
    s = str(v)
    s = s.replace('\\', '\\\\').replace("'", "\\'").replace('\n', '\\n').replace('\r', '')
    return "'" + s + "'"


def qnull(v):
    """None は NULL、それ以外は文字列リテラル。"""
    if v is None or v == '':
        return 'NULL'
    return q(v)


def qint(v, default=0):
    try:
        return str(int(float(str(v).replace(',', '').strip())))
    except (ValueError, TypeError):
        return str(default)


# ---- 値パーサ ----
def parse_bool(v):
    s = str(v or '').strip()
    if s == '':
        return 0
    low = s.lower()
    if low in ('true', '1', 'yes'):
        return 1
    if low in ('false', '0', 'no'):
        return 0
    if '同意しない' in s or 'いいえ' in s:
        return 0
    if 'はい' in s or '同意' in s:
        return 1
    return 0


def parse_date(v):
    """YYYY-MM-DD / YYYY/MM/DD / 8桁 / ISO日時 → 'YYYY-MM-DD'。不明は None。"""
    s = str(v or '').strip()
    if s == '':
        return None
    m = re.match(r'^(\d{4})[-/](\d{1,2})[-/](\d{1,2})', s)
    if m:
        return '%04d-%02d-%02d' % (int(m.group(1)), int(m.group(2)), int(m.group(3)))
    digits = re.sub(r'\D', '', s)
    if len(digits) == 8:
        return '%s-%s-%s' % (digits[0:4], digits[4:6], digits[6:8])
    return None


def parse_time(v):
    """HH:MM[:SS] → 'HH:MM:00'。不明は None。"""
    s = str(v or '').strip()
    m = re.match(r'^(\d{1,2}):(\d{2})', s)
    if m:
        return '%02d:%s:00' % (int(m.group(1)), m.group(2))
    return None


def parse_datetime(v):
    """ISO(UTCのZ付き)→JSTに変換。'YYYY-MM-DD HH:MM:SS' 等はそのまま。不明は None。"""
    s = str(v or '').strip()
    if s == '':
        return None
    # ISO 8601（例: 2026-04-29T01:21:09.879Z）
    m = re.match(r'^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2}):(\d{2})(?:\.\d+)?(Z)?', s)
    if m:
        dt = datetime(int(m.group(1)), int(m.group(2)), int(m.group(3)),
                      int(m.group(4)), int(m.group(5)), int(m.group(6)),
                      tzinfo=timezone.utc if m.group(7) else JST)
        return dt.astimezone(JST).strftime('%Y-%m-%d %H:%M:%S')
    d = parse_date(s)
    if d:
        return d + ' 00:00:00'
    return None


def norm_email(v):
    return str(v or '').strip().lower()


def norm_member_no(v):
    s = str(v or '').strip()
    if s == '' or s.upper() == 'PENDING':
        return None
    return s


def norm_bike(v):
    s = str(v or '').strip()
    return BIKE_MAP.get(s, s)


def load_csv(path):
    if not os.path.isfile(path):
        return None
    with open(path, encoding='utf-8-sig', newline='') as f:
        rows = list(csv.reader(f))
    return rows[1:] if rows else []  # ヘッダー行スキップ


def cell(row, i):
    return row[i] if i < len(row) else ''


# ---- テーブル別ビルダ ----
def build_members(rows, warn):
    """A会員番号 B姓 C名 Dカナ姓 Eカナ名 F生年月日 G会社 H郵便 I住所① J住所② K携帯
       Lメール M規約 N10歳以上 O16歳未満 P登録日時 Qメモ Rハッシュ"""
    best = {}  # email -> (score, tuple)
    for row in rows:
        email = norm_email(cell(row, 11))
        if not email:
            warn('members: メール空の行をスキップ: %s' % (cell(row, 1) + cell(row, 2)))
            continue
        card = norm_member_no(cell(row, 0))
        pw = str(cell(row, 17) or '').strip() or None
        reg = parse_datetime(cell(row, 15))
        # 集約スコア: カード有(4) + PW有(2) + 登録日時（新しいほど大）
        score = (4 if card else 0) + (2 if pw else 0)
        rec = {
            'email': email, 'member_no': card,
            'family_name': cell(row, 1).strip(), 'first_name': cell(row, 2).strip(),
            'kana_family': cell(row, 3).strip(), 'kana_first': cell(row, 4).strip(),
            'birth_date': parse_date(cell(row, 5)), 'company': cell(row, 6).strip(),
            'zip': cell(row, 7).strip(), 'address1': cell(row, 8).strip(), 'address2': cell(row, 9).strip(),
            'phone': cell(row, 10).strip(), 'agreed': parse_bool(cell(row, 12)),
            'qualified': parse_bool(cell(row, 13)), 'is_minor': parse_bool(cell(row, 14)),
            'memo': cell(row, 16).strip(), 'password_hash': pw,
            'registered_at': reg or datetime.now(JST).strftime('%Y-%m-%d %H:%M:%S'),
        }
        key = (score, reg or '')
        if email not in best or key > best[email][0]:
            if email in best:
                warn('members: 重複メール %s を集約（カード/PW/新しさ優先）' % email)
            best[email] = (key, rec)
        else:
            warn('members: 重複メール %s の下位行をスキップ' % email)

    lines = []
    for _, rec in best.values():
        cols = ['email', 'member_no', 'family_name', 'first_name', 'kana_family', 'kana_first',
                'birth_date', 'company', 'zip', 'address1', 'address2', 'phone',
                'agreed', 'qualified', 'is_minor', 'memo', 'password_hash', 'registered_at']
        vals = [
            q(rec['email']), qnull(rec['member_no']),
            q(rec['family_name']), q(rec['first_name']), q(rec['kana_family']), q(rec['kana_first']),
            qnull(rec['birth_date']), q(rec['company']), q(rec['zip']), q(rec['address1']), q(rec['address2']),
            q(rec['phone']), str(rec['agreed']), str(rec['qualified']), str(rec['is_minor']),
            q(rec['memo']), qnull(rec['password_hash']), q(rec['registered_at']),
        ]
        lines.append('INSERT INTO members (%s) VALUES (%s);' % (', '.join(cols), ', '.join(vals)))
    return lines


def build_bookings(rows, warn):
    """A予約番号 B会員番号 C氏名 D自転車ID E日付 F開始 G終了 Hステータス Iコース J前払額 Kメモ L予約日時"""
    lines = []
    for row in rows:
        bno = str(cell(row, 0) or '').strip()
        if not bno:
            continue
        date = parse_date(cell(row, 4))
        st = parse_time(cell(row, 5))
        et = parse_time(cell(row, 6))
        if not (date and st and et):
            warn('bookings: 日付/時刻が不正な行をスキップ: %s' % bno)
            continue
        cols = ['booking_no', 'member_no', 'name', 'bike_id', 'date', 'start_time', 'end_time',
                'status', 'course', 'total_paid', 'memo', 'created_at']
        vals = [
            q(bno), q(norm_member_no(cell(row, 1)) or ''), q(cell(row, 2).strip()),
            q(norm_bike(cell(row, 3))), q(date), q(st), q(et),
            q((cell(row, 7).strip() or 'confirmed')), q(cell(row, 8).strip()),
            qint(cell(row, 9)), q(cell(row, 10).strip()),
            q(parse_datetime(cell(row, 11)) or (date + ' 00:00:00')),
        ]
        lines.append('INSERT INTO bookings (%s) VALUES (%s);' % (', '.join(cols), ', '.join(vals)))
    return lines


def build_rentals(rows, warn):
    """A取引番号 B会員番号 C氏名 D車種 Eコース Fヘルメット Gロッカー H開始日時 I返却予定
       Jステータス K前払額 L追加精算 M返却日時 Nメモ"""
    lines = []
    for row in rows:
        txn = str(cell(row, 0) or '').strip()
        if not txn:
            continue
        start = parse_datetime(cell(row, 7))
        if not start:
            warn('rentals: 開始日時が不正な行をスキップ: %s' % txn)
            continue
        cols = ['txn_no', 'member_no', 'name', 'bike_id', 'course', 'helmet', 'locker',
                'start_at', 'return_expected', 'status', 'total_paid', 'extra_paid', 'returned_at', 'memo']
        vals = [
            q(txn), q(norm_member_no(cell(row, 1)) or ''), q(cell(row, 2).strip()),
            q(norm_bike(cell(row, 3))), q(cell(row, 4).strip()),
            str(parse_bool(cell(row, 5))), str(parse_bool(cell(row, 6))),
            q(start), qnull(parse_time(cell(row, 8))),
            q((cell(row, 9).strip() or 'returned')), qint(cell(row, 10)), qint(cell(row, 11)),
            qnull(parse_datetime(cell(row, 12))), q(cell(row, 13).strip()),
        ]
        lines.append('INSERT INTO rentals (%s) VALUES (%s);' % (', '.join(cols), ', '.join(vals)))
    return lines


def build_settings(rows, warn):
    lines = []
    for row in rows:
        key = str(cell(row, 0) or '').strip()
        if not key or key == '設定キー':
            continue
        val = str(cell(row, 1) or '').strip()
        lines.append("INSERT INTO settings (skey, sval) VALUES (%s, %s) "
                     "ON DUPLICATE KEY UPDATE sval = VALUES(sval);" % (q(key), q(val)))
    return lines


def main():
    ap = argparse.ArgumentParser(description='現行CSV→MySQL投入SQL生成（Looper移行）')
    ap.add_argument('--in', dest='indir', default='import/csv', help='CSV入力ディレクトリ')
    ap.add_argument('--out', dest='out', default='import/import.sql', help='出力SQLファイル')
    args = ap.parse_args()

    warns = []
    def warn(m):
        warns.append(m)

    out = []
    out.append('-- 自動生成: 現行スプレッドシート → Looper MySQL 投入SQL')
    out.append('-- 実行前に schema.sql を投入済みであること。')
    out.append('SET NAMES utf8mb4;')
    out.append("SET time_zone = '+09:00';")
    out.append('')

    for name, builder in [('members', build_members), ('settings', build_settings),
                          ('bookings', build_bookings), ('rentals', build_rentals)]:
        rows = load_csv(os.path.join(args.indir, name + '.csv'))
        if rows is None:
            warn('%s.csv が無いためスキップ' % name)
            continue
        lines = builder(rows, warn)
        out.append('-- ==== %s (%d 行) ====' % (name, len(lines)))
        out.extend(lines)
        out.append('')

    # member_email バックフィル（カード番号→members.email）。PENDING時代の予約は補完不可（空のまま）。
    out.append('-- ==== member_email バックフィル（カード番号で会員を突合）====')
    out.append("UPDATE bookings b JOIN members m ON b.member_no = m.member_no "
               "SET b.member_email = m.email WHERE b.member_no <> '' AND m.member_no IS NOT NULL;")
    out.append("UPDATE rentals r JOIN members m ON r.member_no = m.member_no "
               "SET r.member_email = m.email WHERE r.member_no <> '' AND m.member_no IS NOT NULL;")
    out.append('')

    os.makedirs(os.path.dirname(args.out) or '.', exist_ok=True)
    with open(args.out, 'w', encoding='utf-8', newline='\n') as f:
        f.write('\n'.join(out))

    sys.stderr.write('生成: %s\n' % args.out)
    if warns:
        sys.stderr.write('--- 注意/スキップ (%d件) ---\n' % len(warns))
        for w in warns:
            sys.stderr.write('  ' + w + '\n')


if __name__ == '__main__':
    main()
