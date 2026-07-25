<?php
// ============================================================
// メール送信（さくらのレンタルサーバ SMTP / PHP mail()）
//   mail_mode: 'php'（送信）/ 'off'（送信しない＝開発）
// ============================================================
declare(strict_types=1);

/**
 * HTML メールを送る。成功で true。
 */
function send_html_mail(string $to, string $subject, string $htmlBody): bool
{
    global $CONFIG;
    if (($CONFIG['mail_mode'] ?? 'off') !== 'php') {
        error_log('[mail off] to=' . $to . ' subject=' . $subject);
        return true; // 開発時は送らず成功扱い
    }
    $fromAddr = $CONFIG['mail_from'] ?? 'noreply@localhost';
    $fromName = $CONFIG['mail_from_name'] ?? 'Looper';
    $encName  = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
    $encSubj  = '=?UTF-8?B?' . base64_encode($subject) . '?=';

    $headers  = 'From: ' . $encName . ' <' . $fromAddr . ">\r\n";
    $headers .= 'MIME-Version: 1.0' . "\r\n";
    $headers .= 'Content-Type: text/html; charset=UTF-8' . "\r\n";
    $headers .= 'Content-Transfer-Encoding: base64' . "\r\n";

    $body = chunk_split(base64_encode($htmlBody));

    // さくらは mb_send_mail でも可。エンベロープFromを差出人に。
    $params = '-f' . $fromAddr;
    return @mail($to, $encSubj, $body, $headers, $params);
}

/**
 * パスワード設定メールを送る（verify_url + token を組み立て）。
 */
function send_password_mail(string $email, string $name, string $token, string $mode = 'setup'): bool
{
    global $CONFIG;
    $base = (string)($CONFIG['verify_url'] ?? '');
    $verifyUrl = rtrim($base, '?&') . (str_contains($base, '?') ? '&' : '?') . 'token=' . $token;
    $subject = $mode === 'reset' ? '【Looper】パスワード再設定のご案内' : '【Looper】メールアドレスの確認';
    return send_html_mail($email, $subject, password_mail_body($name, $verifyUrl, $mode));
}

/**
 * パスワード設定／再設定メールの本文（黒帯＋公式ロゴ。現行メールと同じ意匠）。
 *   $mode = 'setup' … 初回のパスワード設定 / 'reset' … 忘れた場合の再設定
 */
function password_mail_body(string $memberName, string $verifyUrl, string $mode = 'setup'): string
{
    $logo = 'https://cococorin.github.io/rental-bicycle/looper-logo.jpg';
    $name = htmlspecialchars($memberName, ENT_QUOTES, 'UTF-8');
    $url  = htmlspecialchars($verifyUrl, ENT_QUOTES, 'UTF-8');
    $lead = $mode === 'reset'
        ? 'パスワード再設定のリクエストを受け付けました。<br>以下のボタンをクリックして新しいパスワードを設定してください。<br>お心当たりがない場合は、このメールを破棄してください（パスワードは変更されません）。'
        : 'Looper まちなかレンタサイクルへのご登録ありがとうございます。<br>以下のボタンをクリックしてパスワードを設定してください。';
    $btnLabel = $mode === 'reset' ? '新しいパスワードを設定する' : 'パスワードを設定する';
    return
        '<div style="font-family:sans-serif;max-width:520px;margin:0 auto;padding:20px;">' .
        '<div style="background:#1a0000;padding:18px 20px;border-radius:10px 10px 0 0;text-align:center;">' .
        '<img src="' . $logo . '" alt="Looper" width="160" style="display:inline-block;border:0;height:auto;">' .
        '<div style="font-size:11px;color:rgba(255,255,255,.78);margin-top:6px;">まちなかレンタサイクル</div>' .
        '</div>' .
        '<div style="background:white;padding:24px;border:1px solid #eedada;border-top:none;border-radius:0 0 10px 10px;">' .
        '<p style="font-size:15px;color:#1a0000;">' . $name . ' 様</p>' .
        '<p style="font-size:14px;color:#444;line-height:1.8;margin:12px 0;">' . $lead . '</p>' .
        '<div style="text-align:center;margin:24px 0;">' .
        '<a href="' . $url . '" style="background:#C0281C;color:white;padding:14px 32px;border-radius:8px;text-decoration:none;font-size:15px;font-weight:700;display:inline-block;">' . $btnLabel . '</a>' .
        '</div>' .
        '<p style="font-size:12px;color:#aaa;border-top:1px solid #f5eaea;padding-top:12px;margin-top:12px;">' .
        '⏰ リンクの有効期限は30分です。<br>' .
        'このメールに心当たりがない場合は無視してください。<br><br>' .
        'cococorin（半田市南末広町120-4）<br>まちなかレンタサイクル「Looper」' .
        '</p></div></div>';
}
