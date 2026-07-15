// ============================================================
// 【移行後の GAS】Google フォーム → PHP webhook（Looper）
//   従来の onFormSubmit（会員シート書き込み）を置き換える。
//   フォーム送信トリガーで発火し、会員情報を さくらの PHP に POST する。
//   → member_upsert.php が upsert し、パスワード設定メールを即送信する。
//
// ★スクリプトプロパティに設定（コードに直書きしない）:
//   PHP_WEBHOOK_URL = https://<さくらのドメイン>/webhook/member_upsert.php
//   SHARED_SECRET   = config.php と同じ長いランダム文字列
//
// ★トリガー: 実行する関数 = onFormSubmit / イベント = フォーム送信時
// ============================================================

// Looper 会員登録フォームの列インデックス（0始まり。現行 onFormSubmit と同じ）
//   [1] 10歳以上145cm(qualified) [2]姓 [3]名 [4]カナ姓 [5]カナ名 [6]生年月日
//   [7]会社/学校 [8]郵便番号 [9]住所① [10]住所② [11]携帯 [12]メール
//   [13]規約同意 [14]16歳未満
function onFormSubmit(e) {
  if (!e || !e.values) { Logger.log('onFormSubmit: e.values 無し'); return; }
  var v = e.values;

  var payload = {
    secret:     PropertiesService.getScriptProperties().getProperty('SHARED_SECRET'),
    email:      String(v[12] || '').trim(),
    familyName: String(v[2]  || '').trim(),
    firstName:  String(v[3]  || '').trim(),
    kanaFamily: String(v[4]  || '').trim(),
    kanaFirst:  String(v[5]  || '').trim(),
    birthDate:  String(v[6]  || '').trim(),
    company:    String(v[7]  || '').trim(),
    zip:        String(v[8]  || '').trim(),
    address1:   String(v[9]  || '').trim(),
    address2:   String(v[10] || '').trim(),
    phone:      String(v[11] || '').trim(),
    agreed:     String(v[13] || '').length > 0 && String(v[13]).indexOf('同意しない') < 0,
    qualified:  String(v[1]  || '').indexOf('はい') >= 0,
    isMinor:    String(v[14] || '').indexOf('はい') >= 0
  };

  if (!payload.email) { Logger.log('onFormSubmit: メール空でスキップ'); return; }

  var url = PropertiesService.getScriptProperties().getProperty('PHP_WEBHOOK_URL');
  var res = UrlFetchApp.fetch(url, {
    method: 'post',
    contentType: 'application/json',
    payload: JSON.stringify(payload),
    muteHttpExceptions: true
  });
  Logger.log('member_upsert 応答: ' + res.getResponseCode() + ' ' + res.getContentText());
}
