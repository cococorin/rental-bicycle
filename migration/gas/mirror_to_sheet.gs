// ============================================================
// 【移行後の GAS】MySQL → スタッフ閲覧用スプレッドシート 一方向ミラー（Looper）
//   さくらの export.php（共有シークレット保護）を叩き、
//   会員 / 予約 / 利用記録 の3シートへ上書きする。
//   ※ DB が正・シートは閲覧専用のコピー（編集しても次回上書きされる）。
//
// ★スクリプトプロパティに設定:
//   PHP_EXPORT_URL = https://<さくらのドメイン>/webhook/export.php
//   SHARED_SECRET  = config.php と同じ長いランダム文字列
//   MIRROR_SS_ID   = 閲覧用スプレッドシートのID
//
// ★トリガー: 時間主導（例 10分毎）で mirrorToSheet を実行。
// ============================================================

function mirrorToSheet() {
  var props  = PropertiesService.getScriptProperties();
  var url    = props.getProperty('PHP_EXPORT_URL');
  var secret = props.getProperty('SHARED_SECRET');
  var ssId   = props.getProperty('MIRROR_SS_ID');
  if (!url || !secret || !ssId) { Logger.log('mirror: プロパティ未設定'); return; }

  var res = UrlFetchApp.fetch(url + '?kind=all&secret=' + encodeURIComponent(secret), { muteHttpExceptions: true });
  if (res.getResponseCode() !== 200) { Logger.log('mirror: export 失敗 ' + res.getResponseCode()); return; }
  var data = JSON.parse(res.getContentText());
  if (!data || !data.success) { Logger.log('mirror: export エラー'); return; }

  var ss = SpreadsheetApp.openById(ssId);
  writeSheet_(ss, '会員',
    ['会員番号','メール','氏名','電話','生年月日','16歳未満','パスワード設定','登録日時'],
    (data.members || []).map(function(m){
      return [m.member_no, m.email, m.full_name, m.phone, m.birth_date,
              m.is_minor ? 'はい' : '', m.has_password ? '済' : '未', m.registered_at];
    }));
  writeSheet_(ss, '予約',
    ['予約番号','会員番号','氏名','自転車','日付','開始','終了','状態','コース','前払額','予約日時'],
    (data.bookings || []).map(function(b){
      return [b.booking_no, b.member_no, b.name, b.bike_id, b.date, b.start_time, b.end_time,
              b.status, b.course, b.total_paid, b.created_at];
    }));
  writeSheet_(ss, '利用記録',
    ['取引番号','会員番号','氏名','自転車','コース','ヘルメット','ロッカー','開始日時','返却予定','状態','前払額','追加精算','返却日時'],
    (data.rentals || []).map(function(r){
      return [r.txn_no, r.member_no, r.name, r.bike_id, r.course, r.helmet ? '有' : '', r.locker ? '有' : '',
              r.start_at, r.return_expected, r.status, r.total_paid, r.extra_paid, r.returned_at];
    }));
  Logger.log('mirror 完了: 会員' + (data.members||[]).length + ' 予約' + (data.bookings||[]).length + ' 利用' + (data.rentals||[]).length);
}

// シートを見出し＋データで上書きする（無ければ作成）
function writeSheet_(ss, name, headers, rows) {
  var sh = ss.getSheetByName(name) || ss.insertSheet(name);
  sh.clearContents();
  sh.getRange(1, 1, 1, headers.length).setValues([headers])
    .setFontWeight('bold').setBackground('#C0281C').setFontColor('white');
  if (rows.length) sh.getRange(2, 1, rows.length, headers.length).setValues(rows);
  sh.setFrozenRows(1);
}
