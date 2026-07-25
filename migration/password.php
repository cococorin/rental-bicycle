<?php
// ============================================================
// パスワード設定ページ（メール「パスワードを設定する」のリンク先）
//   ?token=... を受け、同一オリジンの api/index.php へ setPasswordByToken を投げる。
//   現行 GAS passwordSetPage の後継（拡大・見やすい意匠）。
// ============================================================
declare(strict_types=1);
$token = isset($_GET['token']) ? preg_replace('/[^A-Za-z0-9]/', '', (string)$_GET['token']) : '';
?><!DOCTYPE html>
<html lang="ja"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Looper — パスワード設定</title>
<link rel="icon" href="favicon.ico" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="favicon-32.png">
<link rel="icon" type="image/png" sizes="16x16" href="favicon-16.png">
<link rel="apple-touch-icon" href="apple-touch-icon.png">
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:-apple-system,"Hiragino Sans",sans-serif;background:#f5f0f0;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:24px;}
.box{background:#fff;border-radius:18px;padding:2.75rem;max-width:560px;width:100%;border:1px solid #eedada;box-shadow:0 10px 40px rgba(0,0,0,.08);}
.hdr{background:#1a0000;margin:-2.75rem -2.75rem 1.75rem;padding:22px 28px;border-radius:16px 16px 0 0;text-align:center;}
.hdr img{height:40px;width:auto;}
.logo-sub{font-size:13px;color:rgba(255,255,255,.7);margin-top:6px;}
.title{font-size:24px;font-weight:700;color:#1a0000;margin-bottom:16px;}
.desc{font-size:16px;color:#888;margin-bottom:20px;line-height:1.8;}
.field{margin-bottom:20px;}
.field label{display:block;font-size:15px;color:#888;font-weight:600;margin-bottom:7px;}
.field input{width:100%;padding:16px 18px;border:2px solid #eedada;border-radius:12px;font-size:18px;}
.field input:focus{outline:none;border-color:#C0281C;}
.pw-wrap{position:relative;}
.eye{position:absolute;right:14px;top:50%;transform:translateY(-50%);border:none;background:transparent;cursor:pointer;font-size:20px;color:#ccc;}
.btn{width:100%;padding:18px;background:#C0281C;color:#fff;border:none;border-radius:12px;font-size:18px;font-weight:700;cursor:pointer;margin-top:8px;}
.btn:disabled{background:#ddd;cursor:default;}
/* 設定完了後に表示する「WEB予約ページへ」ボタン */
.btn-go{display:none;width:100%;padding:18px;background:#C0281C;color:#fff;border-radius:12px;font-size:18px;font-weight:700;margin-top:12px;text-align:center;text-decoration:none;box-sizing:border-box;}
.btn-go:hover{background:#a02218;}
.msg{border-radius:9px;padding:12px 14px;font-size:14px;margin-top:12px;display:none;}
.err{background:#fff1f0;border:1px solid #f09595;color:#A32D2D;}
.ok{background:#E2F5EE;border:1px solid #5DCAA5;color:#085041;}
</style></head><body>
<div class="box">
  <div class="hdr"><img src="https://cococorin.github.io/rental-bicycle/looper-logo.jpg" alt="Looper"><div class="logo-sub">まちなかレンタサイクル</div></div>
  <div class="title">パスワードを設定する</div>
  <p class="desc">以下のフォームに新しいパスワードを入力してください。</p>
  <div class="field"><label>新しいパスワード（6文字以上）</label>
    <div class="pw-wrap"><input type="password" id="pw" placeholder="パスワードを入力">
      <button class="eye" type="button" onclick="eye('pw',this)">👁</button></div></div>
  <div class="field"><label>パスワード（確認）</label>
    <div class="pw-wrap"><input type="password" id="pw2" placeholder="もう一度入力">
      <button class="eye" type="button" onclick="eye('pw2',this)">👁</button></div></div>
  <div class="msg" id="msg"></div>
  <button class="btn" id="btn" onclick="submitPw()">パスワードを設定する</button>
  <a class="btn-go" id="go-booking" href="looper_booking.html">WEB予約ページへログイン →</a>
</div>
<script>
var TK = <?= json_encode($token) ?>;
function eye(id,b){var i=document.getElementById(id);i.type=i.type==="password"?"text":"password";b.textContent=i.type==="password"?"👁":"🙈";}
function show(t,c){var m=document.getElementById("msg");m.textContent=t;m.className="msg "+c;m.style.display="block";}
function submitPw(){
  var pw=document.getElementById("pw").value,pw2=document.getElementById("pw2").value,btn=document.getElementById("btn");
  document.getElementById("msg").style.display="none";
  if(!TK){show("リンクが無効です。再度メールを送信してください。","err");return;}
  if(pw.length<6){show("パスワードは6文字以上にしてください","err");return;}
  if(pw!==pw2){show("パスワードが一致しません","err");return;}
  btn.disabled=true;btn.textContent="設定中…";
  fetch("api/index.php?action=setPasswordByToken",{method:"POST",headers:{"Content-Type":"application/json"},
    body:JSON.stringify({token:TK,password:pw})})
    .then(function(r){return r.json();})
    .then(function(d){
      if(d.success){
        show("✅ パスワードを設定しました！下のボタンからログインしてください。","ok");
        btn.style.display="none";
        document.getElementById("go-booking").style.display="block";
      }
      else{show(d.error||"設定に失敗しました","err");btn.disabled=false;btn.textContent="パスワードを設定する";}})
    .catch(function(){show("通信エラーが発生しました","err");btn.disabled=false;btn.textContent="パスワードを設定する";});
}
</script></body></html>
