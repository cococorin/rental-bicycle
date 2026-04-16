/**
 * ============================================================
 *  まちなかレンタサイクル「Looper」
 *  【ikewaki.keita@handanotane.com アカウント】Google Apps Script
 * ============================================================
 */

// ★ ikewakiアカウントのスプレッドシートIDに書き換えてください
var SPREADSHEET_ID = '1m6-mgGuZ_hfRdbsL5zjJ5QrAYFcNpBJUjUSghndToD4';

// ★ はんだのたねGASのデプロイURLに書き換えてください
var MEMBER_API_URL = 'https://script.google.com/macros/s/AKfycbw9lfCxylNo1vrDJispjjkmTlwvPZSo0Tnwda3rafEPz9YtYvg53wM6_TB8ua0fVpAN/exec';

var SHEET_BOOKINGS = '予約';
var SHEET_RENTALS  = '利用記録';
var SHEET_SETTINGS = '設定';

// ★ 受付画面・管理画面と一致させること
var BIKES = [
  { id: 'LOOPER-1',  label: 'LOOPER ①',   type: 'looper'  },
  { id: 'LOOPER-2',  label: 'LOOPER ②',   type: 'looper'  },
  { id: 'eLOOPER-1', label: 'e-LOOPER ①', type: 'elooper' },
  { id: 'eLOOPER-2', label: 'e-LOOPER ②', type: 'elooper' }
];

var DEFAULT_SETTINGS = {
  openTime: '11:00', closeTime: '18:00', bufferMinutes: 60,
  closedDays: [3], price3h: 500, priceDay: 800,
  ePrice3h: 800, ePriceDay: 1200, lockerPrice: 300, overPrice: 200,
  notifyEmail: ''
};

// ============================================================
//  JSON / HTML レスポンス
// ============================================================
function jsonResponse(data, callback) {
  if (callback) {
    // JSONPモード（CORSを回避するためのscriptタグ経由呼び出し）
    return ContentService
      .createTextOutput(callback + '(' + JSON.stringify(data) + ')')
      .setMimeType(ContentService.MimeType.JAVASCRIPT);
  }
  return ContentService.createTextOutput(JSON.stringify(data))
    .setMimeType(ContentService.MimeType.JSON);
}

function htmlResponse(html) {
  return HtmlService.createHtmlOutput(html)
    .setTitle('Looper — パスワード設定')
    .setXFrameOptionsMode(HtmlService.XFrameOptionsMode.ALLOWALL);
}

// ============================================================
//  GET エントリーポイント
//  ※ JSONP経由の書き込み系アクション（body パラメータ付き）もここで処理
// ============================================================
function doGet(e) {
  try {
    var action   = e.parameter.action;
    var callback = e.parameter.callback || null;

    // --- 読み取り系 ---
    if (action === 'getAvailability')  return getAvailability(e.parameter.date, callback);
    if (action === 'getBookings')      return getBookings(e.parameter.from, e.parameter.to, callback);
    if (action === 'getSettings')      return jsonResponse(loadSettings(), callback);
    if (action === 'getActiveRentals') return getActiveRentals(callback);
    if (action === 'ping')             return jsonResponse({ status: 'ok', account: 'ikewaki', timestamp: new Date().toISOString(), bikes: BIKES }, callback);

    // --- トークン検証ページ ---
    if (action === 'verifyToken')      return showVerifyTokenPage(e.parameter.token);

    // --- 会員検索（はんだのたねGASへリレー）---
    if (action === 'getMember') {
      var memberId = e.parameter.id || '';
      return relayGet('getMember&id=' + encodeURIComponent(memberId), callback);
    }
    if (action === 'getMemberList') return relayGet('getMemberList', callback);

    // --- 書き込み系（JSONP経由: bodyパラメータをJSONパース）---
    // 管理画面はCORS回避のためGETでbodyを渡してくる
    var bodyParam = e.parameter.body ? JSON.parse(e.parameter.body) : null;
    if (bodyParam) {
      if (action === 'addBooking')    return addBooking(bodyParam, callback);
      if (action === 'cancelBooking') return cancelBooking(bodyParam, callback);
      if (action === 'addRental')     return addRental(bodyParam, callback);
      if (action === 'updateRental')  return updateRental(bodyParam, callback);
      if (action === 'saveSettings')  return saveSettings(bodyParam, callback);
      // 認証系リレー
      if (action === 'login')             return relayPost('login', bodyParam, callback);
      if (action === 'sendVerification')  return relayPost('sendVerification', bodyParam, callback);
      if (action === 'setPasswordByToken')return relayPost('setPasswordByToken', bodyParam, callback);
      if (action === 'changePassword')    return relayPost('changePassword', bodyParam, callback);
    }

    return jsonResponse({ error: 'unknown action: ' + action }, callback);
  } catch (err) {
    var cb = (e && e.parameter && e.parameter.callback) || null;
    return jsonResponse({ error: err.message }, cb);
  }
}

// ============================================================
//  POST エントリーポイント（直接POSTの場合）
// ============================================================
function doPost(e) {
  try {
    var body   = JSON.parse(e.postData.contents);
    var action = e.parameter.action || body.action;
    if (action === 'addBooking')        return addBooking(body);
    if (action === 'cancelBooking')     return cancelBooking(body);
    if (action === 'addRental')         return addRental(body);
    if (action === 'updateRental')      return updateRental(body);
    if (action === 'saveSettings')      return saveSettings(body);
    if (action === 'login')             return relayPost('login', body);
    if (action === 'sendVerification')  return relayPost('sendVerification', body);
    if (action === 'setPasswordByToken')return relayPost('setPasswordByToken', body);
    if (action === 'changePassword')    return relayPost('changePassword', body);
    return jsonResponse({ error: 'unknown action: ' + action });
  } catch (err) {
    return jsonResponse({ error: err.message });
  }
}

// ============================================================
//  【リレー処理】はんだのたねGASへ転送
// ============================================================
function relayGet(actionParam, callback) {
  if (!MEMBER_API_URL || MEMBER_API_URL === 'HANDANOTANE_GAS_URL_HERE') {
    return jsonResponse({ error: 'MEMBER_API_URL が設定されていません' }, callback);
  }
  try {
    var res  = UrlFetchApp.fetch(MEMBER_API_URL + '?action=' + actionParam);
    var data = JSON.parse(res.getContentText());
    return jsonResponse(data, callback);
  } catch (err) {
    return jsonResponse({ error: 'はんだのたねGAS接続エラー: ' + err.message }, callback);
  }
}

function relayPost(action, body, callback) {
  if (!MEMBER_API_URL || MEMBER_API_URL === 'HANDANOTANE_GAS_URL_HERE') {
    return jsonResponse({ error: 'MEMBER_API_URL が設定されていません' }, callback);
  }
  try {
    var res  = UrlFetchApp.fetch(MEMBER_API_URL + '?action=' + action, {
      method:      'POST',
      contentType: 'application/json',
      payload:     JSON.stringify(Object.assign({ action: action }, body))
    });
    var data = JSON.parse(res.getContentText());
    return jsonResponse(data, callback);
  } catch (err) {
    return jsonResponse({ error: 'はんだのたねGAS接続エラー: ' + err.message }, callback);
  }
}

// ============================================================
//  【トークン検証ページ表示】
// ============================================================
function showVerifyTokenPage(token) {
  if (!token) return htmlResponse(errorPage('トークンが指定されていません'));
  var gasUrl = ScriptApp.getService().getUrl();
  return htmlResponse(passwordSetPage(token, gasUrl));
}

function errorPage(msg) {
  return '<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">' +
    '<title>Looper — エラー</title>' +
    '<style>*{box-sizing:border-box;margin:0;padding:0;}' +
    'body{font-family:-apple-system,sans-serif;background:#f5f0f0;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:20px;}' +
    '.box{background:white;border-radius:15px;padding:2rem;max-width:400px;width:100%;text-align:center;border:1px solid #eedada;}' +
    '.icon{font-size:48px;margin-bottom:1rem;}.title{font-size:18px;font-weight:700;color:#A32D2D;margin-bottom:10px;}' +
    '.msg{font-size:15px;color:#666;line-height:1.8;}</style></head><body>' +
    '<div class="box"><div class="icon">⚠️</div><div class="title">リンクが無効です</div>' +
    '<div class="msg">' + msg + '</div></div></body></html>';
}

function passwordSetPage(token, gasUrl) {
  return '<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">' +
    '<title>Looper — パスワード設定</title>' +
    '<style>*{box-sizing:border-box;margin:0;padding:0;}' +
    'body{font-family:-apple-system,"Hiragino Sans",sans-serif;background:#f5f0f0;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:20px;}' +
    '.box{background:white;border-radius:15px;padding:2rem;max-width:440px;width:100%;border:1px solid #eedada;}' +
    '.hdr{background:#C0281C;margin:-2rem -2rem 1.5rem;padding:16px 20px;border-radius:12px 12px 0 0;}' +
    '.logo{font-size:24px;font-weight:900;font-style:italic;color:white;}' +
    '.logo-sub{font-size:12px;color:rgba(255,255,255,.7);margin-top:2px;}' +
    '.title{font-size:19px;font-weight:700;color:#1a0000;margin-bottom:14px;}' +
    '.field{margin-bottom:15px;}' +
    '.field label{display:block;font-size:13px;color:#888;font-weight:600;margin-bottom:5px;}' +
    '.field input{width:100%;padding:13px 16px;border:2px solid #eedada;border-radius:10px;font-size:16px;}' +
    '.field input:focus{outline:none;border-color:#C0281C;}' +
    '.strength{height:4px;border-radius:2px;margin-top:4px;background:#eee;transition:all .3s;}' +
    '.strength-lbl{font-size:12px;color:#888;margin-top:3px;}' +
    '.btn{width:100%;padding:15px;background:#C0281C;color:white;border:none;border-radius:10px;font-size:16px;font-weight:700;cursor:pointer;margin-top:6px;}' +
    '.btn:disabled{background:#ddd;cursor:default;}' +
    '.msg{border-radius:9px;padding:10px 12px;font-size:13px;margin-top:10px;display:none;}' +
    '.err{background:#fff1f0;border:1px solid #f09595;color:#A32D2D;}' +
    '.ok{background:#E2F5EE;border:1px solid #5DCAA5;color:#085041;}' +
    '.pw-wrap{position:relative;}' +
    '.eye{position:absolute;right:13px;top:50%;transform:translateY(-50%);border:none;background:transparent;cursor:pointer;font-size:18px;color:#ccc;}' +
    '</style></head><body>' +
    '<div class="box">' +
    '<div class="hdr"><div class="logo">Looper</div><div class="logo-sub">まちなかレンタサイクル</div></div>' +
    '<div class="title">パスワードを設定する</div>' +
    '<p style="font-size:14px;color:#888;margin-bottom:16px;line-height:1.7;">以下のフォームに新しいパスワードを入力してください。</p>' +
    '<div class="field"><label>新しいパスワード（7文字以上）</label>' +
    '<div class="pw-wrap"><input type="password" id="pw" placeholder="パスワードを入力" oninput="strength(this.value)">' +
    '<button class="eye" type="button" onclick="eye(\'pw\',this)">👁</button></div>' +
    '<div class="strength" id="str"></div><div class="strength-lbl" id="str-lbl"></div></div>' +
    '<div class="field"><label>パスワード（確認）</label>' +
    '<div class="pw-wrap"><input type="password" id="pw2" placeholder="もう一度入力">' +
    '<button class="eye" type="button" onclick="eye(\'pw2\',this)">👁</button></div></div>' +
    '<div class="msg" id="msg"></div>' +
    '<button class="btn" id="btn" onclick="submit()">パスワードを設定する</button>' +
    '</div>' +
    '<script>' +
    'var GAS="' + gasUrl + '",TK="' + token + '";' +
    'function eye(id,b){var i=document.getElementById(id);i.type=i.type==="password"?"text":"password";b.textContent=i.type==="password"?"👁":"🙈";}' +
    'function strength(pw){var s=document.getElementById("str"),l=document.getElementById("str-lbl"),n=1;' +
    'if(pw.length>=7)n++;if(pw.length>=10)n++;if(/[A-Z]|[0-9]/.test(pw))n++;if(/[^A-Za-z0-9]/.test(pw))n++;' +
    'var w=["1%","25%","50%","75%","100%"],c=["#eee","#f09595","#f0c040","#5DCAA5","#1D9E75"],t=["","弱い","普通","強い","とても強い"];' +
    's.style.width=w[n];s.style.background=c[n];l.textContent=pw?t[n]:"";}' +
    'function submit(){' +
    'var pw=document.getElementById("pw").value,pw2=document.getElementById("pw2").value,' +
    'msg=document.getElementById("msg"),btn=document.getElementById("btn");' +
    'msg.className="msg";msg.style.display="none";' +
    'if(pw.length<7){show("パスワードは7文字以上にしてください","err");return;}' +
    'if(pw!==pw2){show("パスワードが一致しません","err");return;}' +
    'btn.disabled=true;btn.textContent="設定中…";' +
    'fetch(GAS+"?action=setPasswordByToken",{method:"POST",headers:{"Content-Type":"application/json"},' +
    'body:JSON.stringify({action:"setPasswordByToken",token:TK,password:pw})})' +
    '.then(function(r){return r.json();})' +
    '.then(function(d){if(d.success){show("✅ パスワードを設定しました！\\nLooperの予約ページからログインしてください。","ok");btn.style.display="none";}' +
    'else{show(d.error||"設定に失敗しました","err");btn.disabled=false;btn.textContent="パスワードを設定する";}})' +
    '.catch(function(){show("通信エラーが発生しました","err");btn.disabled=false;btn.textContent="パスワードを設定する";});' +
    '}' +
    'function show(t,c){var m=document.getElementById("msg");m.textContent=t;m.className="msg "+c;m.style.display="block";}' +
    '<\/script></body></html>';
}

// ============================================================
//  【設定の読み込み・保存】
//  ★修正: A列(data[i][0])がキー、B列(data[i][1])が値
//  ★修正: parseInt の基数を 10 に統一
// ============================================================
function loadSettings() {
  var ss    = SpreadsheetApp.openById(SPREADSHEET_ID);
  var sheet = ss.getSheetByName(SHEET_SETTINGS);
  if (!sheet) return DEFAULT_SETTINGS;
  var data     = sheet.getDataRange().getValues();
  var settings = JSON.parse(JSON.stringify(DEFAULT_SETTINGS));
  for (var i = 1; i < data.length; i++) {
    var key = String(data[i][0]).trim();  // ★ A列がキー
    var val = data[i][1];                 // ★ B列が値
    if (!key) continue;
    if (key === 'closedDays') {
      settings.closedDays = String(val).split(',').map(function(v) {
        return parseInt(v.trim(), 10);    // ★ 基数10
      }).filter(function(n) { return !isNaN(n); });
    } else if (['bufferMinutes','price3h','priceDay','ePrice3h','ePriceDay','lockerPrice','overPrice'].indexOf(key) >= 0) {
      settings[key] = parseInt(val, 10); // ★ 基数10
    } else {
      settings[key] = val;
    }
  }
  return settings;
}

function saveSettings(body, callback) {
  var ss    = SpreadsheetApp.openById(SPREADSHEET_ID);
  var sheet = ss.getSheetByName(SHEET_SETTINGS) || ss.insertSheet(SHEET_SETTINGS);
  var rows  = [];
  for (var key in body) {
    if (key === 'action') continue;
    rows.push([key, Array.isArray(body[key]) ? body[key].join(',') : body[key], '']);
  }
  sheet.clearContents();
  // ★ ヘッダー行を復元
  sheet.getRange(1, 1, 1, 3).setValues([['設定キー', '値', '説明']]);
  if (rows.length) sheet.getRange(2, 1, rows.length, 3).setValues(rows);
  return jsonResponse({ success: true }, callback);
}

// ============================================================
//  【空き状況の取得】GET ?action=getAvailability&date=YYYY-MM-DD
//  ★修正: 列インデックス・ループ開始位置・isClosedDay判定
// ============================================================
function getAvailability(dateStr, callback) {
  if (!dateStr) return jsonResponse({ error: 'date required' }, callback);
  var settings   = loadSettings();
  var targetDate = new Date(dateStr + 'T01:00:00');
  var dayOfWeek  = targetDate.getDay();
  var isClosedDay = settings.closedDays.indexOf(dayOfWeek) >= 0; // ★ >= 0

  var result = {
    date: dateStr, isClosedDay: isClosedDay,
    closedDayName: isClosedDay ? getDayName(dayOfWeek) : null,
    openTime: settings.openTime, closeTime: settings.closeTime,
    bufferMinutes: settings.bufferMinutes, bikes: []
  };

  var sheet       = SpreadsheetApp.openById(SPREADSHEET_ID).getSheetByName(SHEET_BOOKINGS);
  var allBookings = sheet ? sheet.getDataRange().getValues() : [];

  BIKES.forEach(function(bike) {
    var bikeBookings = [];
    for (var i = 1; i < allBookings.length; i++) { // ★ i=1（ヘッダー行スキップ）
      var row = allBookings[i];
      if (!row[0]) continue;
      if (String(row[3]) !== bike.id)              continue; // ★ D列(row[3])=自転車ID
      if (String(row[4]).slice(0, 10) !== dateStr) continue; // ★ E列(row[4])=日付
      if (row[7] === 'cancelled')                  continue; // ★ H列(row[7])=ステータス
      bikeBookings.push({
        bookingId: row[0],
        start:     String(row[5]),
        end:       String(row[6]),
        bufferEnd: addMinToTime(String(row[6]), settings.bufferMinutes)
      });
    }
    result.bikes.push({ id: bike.id, label: bike.label, type: bike.type, bookings: bikeBookings });
  });
  return jsonResponse(result, callback);
}

// ============================================================
//  【予約一覧】GET ?action=getBookings&from=...&to=...
// ============================================================
function getBookings(fromStr, toStr, callback) {
  var sheet = SpreadsheetApp.openById(SPREADSHEET_ID).getSheetByName(SHEET_BOOKINGS);
  if (!sheet) return jsonResponse({ bookings: [], count: 0 }, callback);
  var data     = sheet.getDataRange().getValues();
  var bookings = [];
  var fromDate = fromStr ? new Date(fromStr) : null;
  var toDate   = toStr   ? new Date(toStr + 'T23:59:59') : null;
  for (var i = 1; i < data.length; i++) {
    var row = data[i];
    if (!row[0]) continue;
    // ★ Date型を文字列に変換してからスライス
    var dateStr = (row[4] instanceof Date)
      ? Utilities.formatDate(row[4], Session.getScriptTimeZone(), 'yyyy-MM-dd')
      : String(row[4]).slice(0, 10);
    var bDate = new Date(dateStr);
    if (fromDate && bDate < fromDate) continue;
    if (toDate   && bDate > toDate)   continue;
    bookings.push({
      bookingId: row[0], memberId: row[1], name: row[2], bikeId: row[3],
      date: dateStr, startTime: row[5], endTime: row[6],
      status: row[7], course: row[8], totalPaid: row[9], memo: row[10], createdAt: row[11]
    });
  }
  return jsonResponse({ bookings: bookings, count: bookings.length }, callback);
}

// ============================================================
//  【予約追加】
// ============================================================
function addBooking(body, callback) {
  if (!body.memberId || !body.bikeId || !body.date || !body.startTime || !body.endTime) {
    return jsonResponse({ success: false, error: '必須項目が不足しています' }, callback);
  }
  var settings  = loadSettings();
  var dayOfWeek = new Date(body.date + 'T00:00:00').getDay();
  if (settings.closedDays.indexOf(dayOfWeek) >= 0) {
    return jsonResponse({ success: false, error: '定休日のため予約できません（' + getDayName(dayOfWeek) + '曜日）' }, callback);
  }
  if (tMin(body.startTime) < tMin(settings.openTime) || tMin(body.endTime) > tMin(settings.closeTime)) {
    return jsonResponse({ success: false, error: '営業時間外です（' + settings.openTime + '〜' + settings.closeTime + '）' }, callback);
  }
  if (tMin(body.startTime) >= tMin(body.endTime)) {
    return jsonResponse({ success: false, error: '終了時刻は開始時刻より後に設定してください' }, callback);
  }
  var conflict = checkConflict(body.bikeId, body.date, body.startTime, body.endTime, settings.bufferMinutes);
  if (conflict) {
    return jsonResponse({ success: false, error: 'この時間帯は予約済みです（' + conflict.start + '〜' + conflict.bufferEnd + ' 清掃バッファ含む）' }, callback);
  }
  var bookingId = genBookingId();
  var sheet     = SpreadsheetApp.openById(SPREADSHEET_ID).getSheetByName(SHEET_BOOKINGS);
  if (!sheet) return jsonResponse({ success: false, error: 'sheet not found' }, callback);
  sheet.appendRow([
    bookingId, body.memberId||'', body.name||'', body.bikeId||'', body.date||'',
    body.startTime||'', body.endTime||'', 'confirmed', body.course||'',
    body.totalPaid||0, body.memo||'', new Date().toISOString()
  ]);
  if (settings.notifyEmail) {
    try {
      var bl = BIKES.filter(function(b){ return b.id === body.bikeId; })[0];
      MailApp.sendEmail(settings.notifyEmail,
        '【Looper】新規予約: ' + body.name + ' 様 / ' + body.date,
        '予約番号: '+bookingId+'\n会員番号: '+body.memberId+'\nお名前: '+body.name+
        '\n自転車: '+(bl ? bl.label : body.bikeId)+'\n日時: '+body.date+' '+body.startTime+'〜'+body.endTime+
        '\n料金: ¥'+(body.totalPaid||0));
    } catch(e) { Logger.log('通知メールエラー: ' + e.message); }
  }
  return jsonResponse({ success: true, bookingId: bookingId }, callback);
}

// ============================================================
//  【予約キャンセル】
// ============================================================
function cancelBooking(body, callback) {
  if (!body.bookingId) return jsonResponse({ success: false, error: 'bookingId required' }, callback);
  var sheet = SpreadsheetApp.openById(SPREADSHEET_ID).getSheetByName(SHEET_BOOKINGS);
  if (!sheet) return jsonResponse({ success: false, error: 'sheet not found' }, callback);
  var data = sheet.getDataRange().getValues();
  for (var i = 1; i < data.length; i++) {
    if (data[i][0] !== body.bookingId) continue;
    if (!body.isAdmin && data[i][1] !== body.memberId) {
      return jsonResponse({ success: false, error: '他の会員の予約はキャンセルできません' }, callback);
    }
    sheet.getRange(i + 1, 8).setValue('cancelled');
    return jsonResponse({ success: true }, callback);
  }
  return jsonResponse({ success: false, error: '予約が見つかりません' }, callback);
}

// ============================================================
//  【重複チェック】
// ============================================================
function checkConflict(bikeId, date, startTime, endTime, bufferMinutes) {
  var sheet = SpreadsheetApp.openById(SPREADSHEET_ID).getSheetByName(SHEET_BOOKINGS);
  if (!sheet) return null;
  var data     = sheet.getDataRange().getValues();
  var newStart = tMin(startTime), newEnd = tMin(endTime);
  for (var i = 1; i < data.length; i++) {
    var row = data[i];
    if (!row[0])                           continue;
    if (row[7] === 'cancelled')            continue; // ★ H列(row[7])
    if (String(row[3]) !== bikeId)         continue; // ★ D列(row[3])
    var rowDate = (row[4] instanceof Date)
      ? Utilities.formatDate(row[4], Session.getScriptTimeZone(), 'yyyy-MM-dd')
      : String(row[4]).slice(0, 10);
    if (rowDate !== date) continue;
    var exS = tMin(String(row[5])), exE = tMin(String(row[6]));
    var exBuf = exE + bufferMinutes, newBuf = newEnd + bufferMinutes;
    if (newStart < exBuf && newBuf > exS) {
      return { bookingId: row[0], start: row[5], end: row[6],
               bufferEnd: addMinToTime(String(row[6]), bufferMinutes) };
    }
  }
  return null;
}

// ============================================================
//  【利用記録】
// ============================================================
function getActiveRentals(callback) {
  var sheet = SpreadsheetApp.openById(SPREADSHEET_ID).getSheetByName(SHEET_RENTALS);
  if (!sheet) return jsonResponse({ rentals: [], count: 0 }, callback);
  var data = sheet.getDataRange().getValues();
  var rentals = [];
  for (var i = 1; i < data.length; i++) {
    var row = data[i];
    if (!row[0]) continue;
    if (row[9] === 'active') {
      rentals.push({
        txnId: row[0], memberId: row[1], name: row[2], bike: row[3],
        course: row[4], helmet: row[5] === 'TRUE', locker: row[6] === 'TRUE',
        startTime: row[7], returnTime: row[8], status: row[9],
        totalPaid: row[10], extraPaid: row[11], returnedAt: row[12]
      });
    }
  }
  return jsonResponse({ rentals: rentals, count: rentals.length }, callback);
}

function addRental(body, callback) {
  var sheet = SpreadsheetApp.openById(SPREADSHEET_ID).getSheetByName(SHEET_RENTALS);
  if (!sheet) return jsonResponse({ success: false, error: 'sheet not found' }, callback);
  var ex = sheet.getDataRange().getValues();
  for (var i = 1; i < ex.length; i++) {
    if (ex[i][0] === body.txnId) return jsonResponse({ success: true, note: 'already exists' }, callback);
  }
  sheet.appendRow([
    body.txnId||'', body.memberId||'', body.name||'', body.bike||'', body.course||'',
    body.helmet ? 'TRUE' : 'FALSE', body.locker ? 'TRUE' : 'FALSE',
    body.startTime || new Date().toISOString(), body.returnTime||'',
    'active', body.totalPaid||0, 0, '', body.memo||''
  ]);
  return jsonResponse({ success: true, txnId: body.txnId }, callback);
}

function updateRental(body, callback) {
  var sheet = SpreadsheetApp.openById(SPREADSHEET_ID).getSheetByName(SHEET_RENTALS);
  if (!sheet) return jsonResponse({ success: false, error: 'sheet not found' }, callback);
  var data = sheet.getDataRange().getValues();
  for (var i = 1; i < data.length; i++) {
    if (data[i][0] !== body.txnId) continue;
    var r = i + 1;
    sheet.getRange(r, 10).setValue(body.status || 'returned');
    if (body.extraPaid  !== undefined) sheet.getRange(r, 12).setValue(body.extraPaid);
    if (body.returnedAt !== undefined) sheet.getRange(r, 13).setValue(body.returnedAt);
    return jsonResponse({ success: true, txnId: body.txnId }, callback);
  }
  return jsonResponse({ success: false, error: 'txnId not found' }, callback);
}

// ============================================================
//  ユーティリティ
// ============================================================
function tMin(t) {
  var p = String(t).split(':');
  return parseInt(p[0], 10) * 60 + parseInt(p[1], 10);
}
function addMinToTime(t, m) {
  var total = tMin(t) + m;
  return ('0' + Math.floor(total / 60)).slice(-2) + ':' + ('0' + (total % 60)).slice(-2);
}
function getDayName(d) { return ['日', '月', '火', '水', '木', '金', '土'][d] || ''; }
function genBookingId() {
  var d = new Date(), p = function(n) { return ('0' + n).slice(-2); };
  return 'BK-' + d.getFullYear() + p(d.getMonth() + 1) + p(d.getDate()) + '-' + Math.random().toString(36).slice(-4).toUpperCase();
}

// ============================================================
//  【初期セットアップ】初回のみ実行
// ============================================================
function setupSheets() {
  var ss = SpreadsheetApp.openById(SPREADSHEET_ID);

  var bs = ss.getSheetByName(SHEET_BOOKINGS) || ss.insertSheet(SHEET_BOOKINGS);
  if (!bs.getRange('A1').getValue()) {
    bs.getRange('A1:L1').setValues([['予約番号','会員番号','氏名','自転車ID','日付','開始時刻','終了時刻','ステータス','コース','前払額','メモ','予約日時']]);
    bs.getRange('A1:L1').setFontWeight('bold').setBackground('#C0281C').setFontColor('white');
    bs.setFrozenRows(1);
    bs.setColumnWidth(1,160); bs.setColumnWidth(4,120); bs.setColumnWidth(5,110); bs.setColumnWidth(12,180);
  }

  var rs = ss.getSheetByName(SHEET_RENTALS) || ss.insertSheet(SHEET_RENTALS);
  if (!rs.getRange('A1').getValue()) {
    rs.getRange('A1:N1').setValues([['取引番号','会員番号','氏名','車種','コース','ヘルメット','ロッカー','開始日時','返却予定時刻','ステータス','前払額','追加精算額','返却日時','メモ']]);
    rs.getRange('A1:N1').setFontWeight('bold').setBackground('#1a0000').setFontColor('white');
    rs.setFrozenRows(1);
    rs.setColumnWidth(1,160); rs.setColumnWidth(8,180); rs.setColumnWidth(13,180);
  }

  var cfg = ss.getSheetByName(SHEET_SETTINGS) || ss.insertSheet(SHEET_SETTINGS);
  if (!cfg.getRange('A1').getValue()) {
    cfg.getRange('A1:C1').setValues([['設定キー','値','説明']]);
    cfg.getRange('A1:C1').setFontWeight('bold').setBackground('#555').setFontColor('white');
    cfg.getRange('A2:C10').setValues([
      ['openTime',      '11:00',  '営業開始時刻（HH:MM）'],
      ['closeTime',     '18:00',  '営業終了時刻（HH:MM）'],
      ['bufferMinutes', '60',     '予約間バッファ（分）'],
      ['closedDays',    '3',      '定休日（0=日〜6=土、複数は「0,2」）'],
      ['price3h',       '500',    'LOOPER 3時間以内（円）'],
      ['priceDay',      '800',    'LOOPER 3時間超（円）'],
      ['ePrice3h',      '800',    'e-LOOPER 3時間以内（円）'],
      ['ePriceDay',     '1200',   'e-LOOPER 3時間超（円）'],
      ['lockerPrice',   '300',    'ロッカー（円）'],
    ]);
    cfg.setColumnWidth(1,160); cfg.setColumnWidth(2,120); cfg.setColumnWidth(3,280);
  }

  SpreadsheetApp.flush();
  SpreadsheetApp.getUi().alert(
    '✅ セットアップ完了！（ikewakiアカウント）\n\n' +
    '作成シート:\n・予約\n・利用記録\n・設定\n\n' +
    '次のステップ:\n' +
    '① 「設定」シートで定休日・通知メールを確認\n' +
    '② MEMBER_API_URL をはんだのたねGASのURLに設定\n' +
    '③ Web APIとしてデプロイ（アクセス：全員）\n' +
    '④ デプロイURLを各HTMLの管理画面設定タブに入力'
  );
}