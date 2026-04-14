
/**
 * ============================================================
 *  まちなかレンタサイクル「Looper」
 *  【はんだのたね アカウント】Google Apps Script
 *
 *  担当範囲:
 *    - 会員情報の管理（Googleフォーム連携）
 *    - メール認証（初回パスワード設定リンクの送信）
 *    - ikewaki GASからの会員検索リクエストへの応答
 *
 *  ★ このGASは「はんだのたね」Googleアカウントで作成・デプロイしてください
 *     デプロイURL → ikewaki GASの MEMBER_API_URL に設定
 *
 *  【Googleフォーム 列マッピング（確定）】
 *    [0]  タイムスタンプ
 *    [1]  10歳以上かつ身長145cm以上か
 *    [2]  お名前（姓）
 *    [3]  お名前（名）
 *    [4]  フリガナ（セイ）
 *    [5]  フリガナ（メイ）
 *    [6]  生年月日
 *    [7]  会社名/学校名
 *    [8]  郵便番号
 *    [9]  住所① 都道府県・市区町村
 *    [10] 住所② 町名・番地・建物名
 *    [11] 携帯電話番号
 *    [12] メールアドレス
 *    [13] 利用規約同意
 *    [14] 16歳未満か
 *    [15] 会員番号（GASが採番して書き戻す）
 *
 *  【会員シート列構成】
 *    A(0):  会員番号（L-0001〜）
 *    B(1):  姓
 *    C(2):  名
 *    D(3):  フリガナ（セイ）
 *    E(4):  フリガナ（メイ）
 *    F(5):  生年月日
 *    G(6):  会社名/学校名
 *    H(7):  郵便番号
 *    I(8):  住所①
 *    J(9):  住所②
 *    K(10): 携帯電話番号
 *    L(11): メールアドレス  ← ログインID
 *    M(12): 利用規約同意
 *    N(13): 10歳以上・身長145cm以上
 *    O(14): 16歳未満
 *    P(15): 登録日時
 *    Q(16): メモ
 *    R(17): パスワードハッシュ（SHA-256）
 *
 *  【認証トークンシート列構成】
 *    A: トークン（32桁）
 *    B: メールアドレス
 *    C: 有効期限（ISO文字列）
 *    D: 使用済（TRUE/FALSE）
 * ============================================================
 */

// ★ はんだのたねアカウントのスプレッドシートIDに書き換えてください
var SPREADSHEET_ID = '1DDluKAOb_4q_h5jc7IvS_Agvc1DyCzZAkHf2XJVt4Gc';

var SHEET_FORM    = 'フォームの回答 1';
var SHEET_MEMBERS = '会員';
var SHEET_TOKENS  = '認証トークン';

// 会員番号の形式
var MEMBER_ID_PREFIX = 'L-';
var MEMBER_ID_DIGITS = 4;

// ikewaki GASのURL（トークン検証ページのリダイレクト先）
// ★ ikewaki GASをデプロイしたURLに書き換えてください
var IKEWAKI_GAS_URL = 'https://script.google.com/a/macros/handanotane.com/s/AKfycbxR98pNy7mpBk9ljhVGobzeFH01wXZm5qbAL8ltBzCyfdd8HSG0FSPzG67a10vfWAX-/exec';

// ============================================================
//  JSON / HTML レスポンス
// ============================================================
function jsonResponse(data) {
  return ContentService
    .createTextOutput(JSON.stringify(data))
    .setMimeType(ContentService.MimeType.JSON);
}
function htmlResponse(html) {
  return HtmlService.createHtmlOutput(html)
    .setTitle('Looper — パスワード設定')
    .setXFrameOptionsMode(HtmlService.XFrameOptionsMode.ALLOWALL);
}

// ============================================================
//  GET エントリーポイント
// ============================================================
function doGet(e) {
  try {
    var action = e.parameter.action;
    if (action === 'getMember')     return getMember(e.parameter.id);
    if (action === 'getMemberList') return getMemberList();
    if (action === 'ping')          return jsonResponse({ status: 'ok', account: 'handanotane', timestamp: new Date().toISOString() });
    return jsonResponse({ error: 'unknown action: ' + action });
  } catch (err) {
    return jsonResponse({ error: err.message });
  }
}

// ============================================================
//  POST エントリーポイント
// ============================================================
function doPost(e) {
  try {
    var body   = JSON.parse(e.postData.contents);
    var action = e.parameter.action || body.action;
    if (action === 'sendVerification')   return sendVerificationEmail(body);
    if (action === 'setPasswordByToken') return setPasswordByToken(body);
    if (action === 'changePassword')     return changePassword(body);
    if (action === 'login')              return loginMember(body);
    if (action === 'addMember')          return addMemberManual(body);  // [BUG FIX] 関数本体を追加
    return jsonResponse({ error: 'unknown action: ' + action });
  } catch (err) {
    return jsonResponse({ error: err.message });
  }
}

// ============================================================
//  【SHA-256 ハッシュ化】
// ============================================================
function sha256(text) {
  var bytes  = Utilities.computeDigest(Utilities.DigestAlgorithm.SHA_256, text, Utilities.Charset.UTF_8);
  var result = '';
  for (var i = 0; i < bytes.length; i++) {
    var b = bytes[i]; if (b < 0) b += 256;
    result += ('0' + b.toString(16)).slice(-2);
  }
  return result;
}

// ============================================================
//  【ログイン認証】POST ?action=login
//  ikewaki GASから呼び出される
//  Body: { email, password }
// ============================================================
function loginMember(body) {
  var email    = body.email    ? String(body.email).trim().toLowerCase()    : '';
  var password = body.password ? String(body.password)                      : '';
  if (!email || !password) return jsonResponse({ success: false, error: 'メールアドレスとパスワードを入力してください' });

  var row = findMemberByEmail(email);
  if (!row) return jsonResponse({ success: false, error: 'メールアドレスまたはパスワードが正しくありません' });

  var storedHash = row[17] ? String(row[17]).trim() : '';
  if (!storedHash) return jsonResponse({ success: false, hasPassword: false, error: 'パスワードが未設定です。初回登録を行ってください' });
  if (sha256(password) !== storedHash) return jsonResponse({ success: false, error: 'メールアドレスまたはパスワードが正しくありません' });

  return jsonResponse({ success: true, member: buildMemberObject(row) });
}

// ============================================================
//  【認証メール送信】POST ?action=sendVerification
//  Body: { email }
// ============================================================
function sendVerificationEmail(body) {
  var email = body.email ? String(body.email).trim().toLowerCase() : '';
  if (!email) return jsonResponse({ success: false, error: 'メールアドレスを入力してください' });

  var row = findMemberByEmail(email);
  if (!row) {
    Logger.log('認証メール: 未登録メールアドレス → ' + email);
    // セキュリティ上、存在しなくても「送信しました」を返す
    return jsonResponse({ success: true, note: 'not_found' });
  }

  // パスワード設定済みの場合
  if (row[17]) {
    return jsonResponse({ success: false, alreadySet: true, error: 'このメールアドレスはすでに登録済みです。ログインするか、パスワード変更をご利用ください' });
  }

  // トークン生成（30分有効）
  var token   = generateToken();
  var expires = new Date(Date.now() + 30 * 60 * 1000);

  // 認証トークンシートに保存
  var ss     = SpreadsheetApp.openById(SPREADSHEET_ID);
  var tSheet = ss.getSheetByName(SHEET_TOKENS) || ss.insertSheet(SHEET_TOKENS);
  if (!tSheet.getRange('A1').getValue()) {
    tSheet.getRange('A1:D1').setValues([['トークン', 'メールアドレス', '有効期限', '使用済']]);
    tSheet.getRange('A1:D1').setFontWeight('bold').setBackground('#555').setFontColor('white');
  }
  tSheet.appendRow([token, email, expires.toISOString(), 'FALSE']);

  // 認証リンク → ikewaki GASのverifyTokenページ
  var verifyUrl = IKEWAKI_GAS_URL + '?action=verifyToken&token=' + token;

  // メール送信
  try {
    MailApp.sendEmail({
      to:      email,
      subject: '【Looper】メールアドレスの確認',
      htmlBody:
        '<div style="font-family:sans-serif;max-width:520px;margin:0 auto;padding:20px;">' +
        '<div style="background:#C0281C;padding:16px 20px;border-radius:10px 10px 0 0;">' +
        '<span style="font-size:22px;font-weight:900;font-style:italic;color:white;">Looper</span>' +
        '<span style="font-size:11px;color:rgba(255,255,255,.7);display:block;margin-top:2px;">まちなかレンタサイクル</span>' +
        '</div>' +
        '<div style="background:white;padding:24px;border:1px solid #eedada;border-top:none;border-radius:0 0 10px 10px;">' +
        '<p style="font-size:15px;color:#1a0000;">' + row[1] + ' ' + row[2] + ' 様</p>' +
        '<p style="font-size:14px;color:#444;line-height:1.8;margin:12px 0;">Looper まちなかレンタサイクルへのご登録ありがとうございます。<br>' +
        '以下のボタンをクリックしてパスワードを設定してください。</p>' +
        '<div style="text-align:center;margin:24px 0;">' +
        '<a href="' + verifyUrl + '" style="background:#C0281C;color:white;padding:14px 32px;border-radius:8px;text-decoration:none;font-size:15px;font-weight:700;display:inline-block;">パスワードを設定する</a>' +
        '</div>' +
        '<p style="font-size:12px;color:#aaa;border-top:1px solid #f5eaea;padding-top:12px;margin-top:12px;">' +
        '⏰ リンクの有効期限は30分です。<br>' +
        'このメールに心当たりがない場合は無視してください。<br><br>' +
        'ここ○リン（半田市南末広町120-4）<br>まちなかレンタサイクル「Looper」' +
        '</p></div></div>'
    });
  } catch (mailErr) {
    Logger.log('メール送信エラー: ' + mailErr.message);
    return jsonResponse({ success: false, error: 'メール送信に失敗しました。しばらくしてから再度お試しください' });
  }

  Logger.log('✅ 認証メール送信: ' + email + ' / トークン: ' + token.slice(0, 8) + '...');
  return jsonResponse({ success: true });
}

// ============================================================
//  【トークン検証・パスワード設定】POST ?action=setPasswordByToken
//  ikewaki GASからリレーされる
//  Body: { token, password }
// ============================================================
function setPasswordByToken(body) {
  var token    = body.token    ? String(body.token)    : '';
  var password = body.password ? String(body.password) : '';
  if (!token || !password) return jsonResponse({ success: false, error: '入力が不足しています' });
  if (password.length < 6)  return jsonResponse({ success: false, error: 'パスワードは6文字以上にしてください' });

  var ss     = SpreadsheetApp.openById(SPREADSHEET_ID);
  var tSheet = ss.getSheetByName(SHEET_TOKENS);
  if (!tSheet) return jsonResponse({ success: false, error: 'セッションが無効です' });

  var tData = tSheet.getDataRange().getValues();
  for (var i = 1; i < tData.length; i++) {
    var tRow = tData[i];
    if (String(tRow[0]) !== token)       continue;
    if (String(tRow[3]) === 'TRUE')      return jsonResponse({ success: false, error: 'このリンクはすでに使用済みです' });
    if (new Date() > new Date(tRow[2]))  return jsonResponse({ success: false, error: 'リンクの有効期限が切れています。再度メールを送信してください' });

    var email     = String(tRow[1]);
    var mSheet    = ss.getSheetByName(SHEET_MEMBERS);
    var mData     = mSheet.getDataRange().getValues();
    var memberObj = null;
    var saved     = false;

    for (var j = 1; j < mData.length; j++) {
      var rowEmail = mData[j][11] ? String(mData[j][11]).trim().toLowerCase() : '';
      if (rowEmail !== email.toLowerCase()) continue;
      mSheet.getRange(j + 1, 18).setValue(sha256(password));
      memberObj = buildMemberObject(mData[j]);
      saved = true;
      break;
    }
    if (!saved) return jsonResponse({ success: false, error: '会員情報が見つかりません' });

    // トークンを使用済みにする
    tSheet.getRange(i + 1, 4).setValue('TRUE');
    Logger.log('✅ パスワード設定完了（トークン経由）: ' + email);
    return jsonResponse({ success: true, member: memberObj });
  }
  return jsonResponse({ success: false, error: '無効なトークンです' });
}

// ============================================================
//  【パスワード変更】POST ?action=changePassword
//  Body: { email, currentPassword, newPassword }
// ============================================================
function changePassword(body) {
  var email   = body.email           ? String(body.email).trim().toLowerCase() : '';
  var current = body.currentPassword ? String(body.currentPassword)            : '';
  var newPw   = body.newPassword     ? String(body.newPassword)                : '';
  if (!email || !current || !newPw) return jsonResponse({ success: false, error: '入力が不足しています' });
  if (newPw.length < 6) return jsonResponse({ success: false, error: 'パスワードは6文字以上にしてください' });

  var ss    = SpreadsheetApp.openById(SPREADSHEET_ID);
  var sheet = ss.getSheetByName(SHEET_MEMBERS);
  var data  = sheet.getDataRange().getValues();

  for (var i = 1; i < data.length; i++) {
    var rowEmail = data[i][11] ? String(data[i][11]).trim().toLowerCase() : '';
    if (rowEmail !== email) continue;
    if (sha256(current) !== String(data[i][17]).trim()) return jsonResponse({ success: false, error: '現在のパスワードが正しくありません' });
    sheet.getRange(i + 1, 18).setValue(sha256(newPw));
    return jsonResponse({ success: true });
  }
  return jsonResponse({ success: false, error: 'メールアドレスが見つかりません' });
}

// ============================================================
//  【会員番号で検索】GET ?action=getMember&id=L-0001
//  ikewaki GASから呼び出される（個人情報は最小限のみ返す）
// ============================================================
function getMember(memberId) {
  if (!memberId) return jsonResponse({ found: false, error: 'id required' });
  var sheet = SpreadsheetApp.openById(SPREADSHEET_ID).getSheetByName(SHEET_MEMBERS);
  if (!sheet) return jsonResponse({ found: false, error: 'sheet not found' });
  var data = sheet.getDataRange().getValues();
  for (var i = 1; i < data.length; i++) {
    var row = data[i];
    if (String(row[0]).trim() !== String(memberId).trim()) continue;
    var obj = buildMemberObject(row);
    obj.found = true;
    return jsonResponse(obj);
  }
  return jsonResponse({ found: false });
}

// ============================================================
//  【会員一覧】GET ?action=getMemberList
//  ikewaki GASから呼び出される（管理画面用）
// ============================================================
function getMemberList() {
  var sheet = SpreadsheetApp.openById(SPREADSHEET_ID).getSheetByName(SHEET_MEMBERS);
  if (!sheet) return jsonResponse({ members: [] });
  var data    = sheet.getDataRange().getValues();
  var members = [];
  for (var i = 1; i < data.length; i++) {
    var row = data[i];
    if (!row[0]) continue;
    members.push({
      id:          String(row[0]).trim(),
      fullName:    (String(row[1] || '') + ' ' + String(row[2] || '')).trim(),
      phone:       row[10] ? String(row[10]) : '',
      email:       row[11] ? String(row[11]) : '',
      isMinor:     row[14] === true || String(row[14]).includes('はい') || row[14] === 'TRUE',
      since:       row[15] ? String(row[15]) : '',
      hasPassword: !!(row[17])
    });
  }
  return jsonResponse({ members: members, count: members.length });
}

// ============================================================
//  【手動会員追加】POST ?action=addMember
//  管理画面からスタッフが直接会員を追加する場合に使用
//  Body: { familyName, firstName, email, phone, memo }
//  [BUG FIX] doPost に呼び出しがあったが関数本体が未定義だったため追加
// ============================================================
function addMemberManual(body) {
  var email = body.email ? String(body.email).trim().toLowerCase() : '';
  if (!email) return jsonResponse({ success: false, error: 'メールアドレスは必須です' });
  if (!body.familyName) return jsonResponse({ success: false, error: '姓は必須です' });

  // メールアドレス重複チェック
  var existing = findMemberByEmail(email);
  if (existing) return jsonResponse({ success: false, error: 'このメールアドレスはすでに登録済みです' });

  var ss          = SpreadsheetApp.openById(SPREADSHEET_ID);
  var memberSheet = ss.getSheetByName(SHEET_MEMBERS);
  if (!memberSheet) return jsonResponse({ success: false, error: '会員シートが見つかりません' });

  var newId = generateMemberId(memberSheet);
  memberSheet.appendRow([
    newId,
    body.familyName  || '',
    body.firstName   || '',
    body.kanaFamily  || '',
    body.kanaFirst   || '',
    body.birthDate   || '',
    body.company     || '',
    body.zip         || '',
    body.address1    || '',
    body.address2    || '',
    body.phone       || '',
    email,
    body.agreed      ? 'TRUE' : 'FALSE',
    body.qualified   ? 'TRUE' : 'FALSE',
    body.isMinor     ? 'TRUE' : 'FALSE',
    new Date().toISOString(),
    body.memo        || '',
    ''  // パスワードハッシュ（初回登録時は空）
  ]);

  Logger.log('✅ 手動会員追加: ' + newId + ' / ' + body.familyName + ' ' + (body.firstName || ''));
  return jsonResponse({ success: true, memberId: newId });
}

// ============================================================
//  【Googleフォーム送信トリガー】onFormSubmit
//
//  設定方法:
//  Apps Script「トリガー」→「トリガーを追加」
//    実行する関数: onFormSubmit
//    イベントのソース: スプレッドシートから
//    イベントの種類: フォーム送信時
// ============================================================
function onFormSubmit(e) {
  try {
    var v             = e.values;
    var agreedBool    = String(v[13]).length > 0 && !String(v[13]).includes('同意しない');
    var qualifiedBool = String(v[1]).includes('はい');
    var isMinorBool   = String(v[14]).includes('はい');

    var ss          = SpreadsheetApp.openById(SPREADSHEET_ID);
    var memberSheet = ss.getSheetByName(SHEET_MEMBERS);
    var newId       = generateMemberId(memberSheet);

    memberSheet.appendRow([
      newId,
      v[2] || '', v[3] || '', v[4] || '', v[5] || '',
      v[6] || '', v[7] || '', v[8] || '', v[9] || '', v[10] || '',
      v[11] || '', v[12] || '',
      agreedBool    ? 'TRUE' : 'FALSE',
      qualifiedBool ? 'TRUE' : 'FALSE',
      isMinorBool   ? 'TRUE' : 'FALSE',
      new Date().toISOString(), '', ''  // P:登録日時 Q:メモ R:パスワードハッシュ
    ]);

    // フォーム回答シートP列（16列目）に会員番号を書き戻す
    try {
      var formSheet = ss.getSheetByName(SHEET_FORM);
      var lastRow   = formSheet.getLastRow();
      if (formSheet.getRange(1, 16).getValue() !== '会員番号') {
        formSheet.getRange(1, 16).setValue('会員番号').setFontWeight('bold').setBackground('#C0281C').setFontColor('white');
      }
      formSheet.getRange(lastRow, 16).setValue(newId);
    } catch (e2) { Logger.log('書き戻しスキップ: ' + e2.message); }

    Logger.log('✅ 会員登録完了: ' + newId + ' / ' + (v[2] || '') + ' ' + (v[3] || ''));
  } catch (err) {
    Logger.log('❌ onFormSubmit エラー: ' + err.message);
  }
}

// ============================================================
//  ユーティリティ
// ============================================================
function buildMemberObject(row) {
  var fName = row[1] ? String(row[1]) : '';
  var gName = row[2] ? String(row[2]) : '';
  return {
    id:           String(row[0]).trim(),
    familyName:   fName,
    firstName:    gName,
    fullName:     (fName + ' ' + gName).trim(),
    fullNameKana: ((row[3] || '') + ' ' + (row[4] || '')).trim(),
    email:        row[11] ? String(row[11]) : '',
    phone:        row[10] ? String(row[10]) : '',
    isMinor:      row[14] === true || String(row[14]).includes('はい') || row[14] === 'TRUE',
    since:        row[15] ? String(row[15]) : '',
    hasPassword:  !!(row[17])
  };
}

function findMemberByEmail(email) {
  var sheet = SpreadsheetApp.openById(SPREADSHEET_ID).getSheetByName(SHEET_MEMBERS);
  if (!sheet) return null;
  var data = sheet.getDataRange().getValues();
  for (var i = 1; i < data.length; i++) {
    var rowEmail = data[i][11] ? String(data[i][11]).trim().toLowerCase() : '';
    if (rowEmail === email) return data[i];
  }
  return null;
}

function generateMemberId(sheet) {
  var data = sheet.getDataRange().getValues();
  var max  = 0;
  for (var i = 1; i < data.length; i++) {
    var id  = String(data[i][0]).trim();
    if (id.indexOf(MEMBER_ID_PREFIX) !== 0) continue;
    var num = parseInt(id.replace(MEMBER_ID_PREFIX, ''), 10);
    if (!isNaN(num) && num > max) max = num;
  }
  return MEMBER_ID_PREFIX + String(max + 1).padStart(MEMBER_ID_DIGITS, '0');
}

function generateToken() {
  var chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
  var bytes = Utilities.computeDigest(Utilities.DigestAlgorithm.SHA_256,
               Math.random().toString() + Date.now().toString(), Utilities.Charset.UTF_8);
  var token = '';
  for (var i = 0; i < 32; i++) {
    var b = bytes[i % bytes.length]; if (b < 0) b += 256;
    token += chars[b % chars.length];
  }
  return token;
}

// 期限切れ・使用済みトークンの定期クリーンアップ（週1回トリガー推奨）
function cleanupTokens() {
  var ss    = SpreadsheetApp.openById(SPREADSHEET_ID);
  var sheet = ss.getSheetByName(SHEET_TOKENS);
  if (!sheet) return;
  var data  = sheet.getDataRange().getValues();
  var now   = new Date();
  // 下から削除してインデックスずれを防ぐ
  for (var i = data.length - 1; i >= 1; i--) {
    if (new Date(data[i][2]) < now || String(data[i][3]) === 'TRUE') {
      sheet.deleteRow(i + 1);
    }
  }
  Logger.log('トークンクリーンアップ完了');
}

// ============================================================
//  【初期セットアップ】初回のみ実行
// ============================================================
function setupSheets() {
  var ss = SpreadsheetApp.openById(SPREADSHEET_ID);

  // 会員シート
  var ms = ss.getSheetByName(SHEET_MEMBERS) || ss.insertSheet(SHEET_MEMBERS);
  if (!ms.getRange('A1').getValue()) {
    ms.getRange('A1:R1').setValues([[
      '会員番号', '姓', '名', 'フリガナ（セイ）', 'フリガナ（メイ）',
      '生年月日', '会社名/学校名', '郵便番号',
      '住所①（都道府県・市区町村）', '住所②（町名・番地・建物名）',
      '携帯電話番号', 'メールアドレス',
      '利用規約同意', '10歳以上・身長145cm以上', '16歳未満',
      '登録日時', 'メモ', 'パスワードハッシュ（SHA-256）'
    ]]);
    ms.getRange('A1:R1').setFontWeight('bold').setBackground('#C0281C').setFontColor('white');
    ms.setFrozenRows(1);
    ms.setColumnWidths(1, 18, 100);
    ms.setColumnWidth(9, 200); ms.setColumnWidth(10, 200);
    ms.setColumnWidth(12, 200); ms.setColumnWidth(16, 180);
    // パスワード列を薄く色付けして誤編集を抑制
    ms.getRange('R2:R1000').setBackground('#FFF8F8').setFontColor('#DDAAAA');
    // サンプルデータ（動作確認後に削除してください）
    ms.getRange('A2:Q2').setValues([[
      'L-0001', '田中', '花子', 'タナカ', 'ハナコ', '1990-04-01', '',
      '475-0000', '愛知県半田市南末広町', '120-4',
      '090-0000-0001', 'hanako@example.com',
      'TRUE', 'TRUE', 'FALSE', new Date().toISOString(), 'サンプル（確認後削除）'
    ]]);
  }

  // 認証トークンシート
  var ts = ss.getSheetByName(SHEET_TOKENS) || ss.insertSheet(SHEET_TOKENS);
  if (!ts.getRange('A1').getValue()) {
    ts.getRange('A1:D1').setValues([['トークン', 'メールアドレス', '有効期限', '使用済']]);
    ts.getRange('A1:D1').setFontWeight('bold').setBackground('#555').setFontColor('white');
    ts.setColumnWidth(1, 240); ts.setColumnWidth(2, 200); ts.setColumnWidth(3, 180);
  }

  SpreadsheetApp.flush();
  SpreadsheetApp.getUi().alert(
    '✅ セットアップ完了！（はんだのたねアカウント）\n\n' +
    '作成シート:\n・会員\n・認証トークン\n\n' +
    '次のステップ:\n' +
    '① Googleフォームとこのスプレッドシートを連携\n' +
    '② フォーム送信トリガーを設定（onFormSubmit）\n' +
    '③ Web APIとしてデプロイ（アクセス：全員）\n' +
    '④ デプロイURLをikewaki GASの MEMBER_API_URL に設定'
  );
}