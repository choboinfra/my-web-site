<?php
require_once __DIR__ . '/lib/members_lib.php';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!site_valid_csrf()) {
        $error = '요청이 만료되었습니다. 새로고침해 주세요.';
    } else {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $found = null;
        foreach (site_member_data()['users'] as $u) {
            if (strcasecmp((string)($u['username'] ?? ''), $username) === 0) {
                $found = $u; break;
            }
        }
        if ($found && empty($found['suspended']) &&
            password_verify($password, (string)$found['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['site_member_id'] = $found['id'];
            header('Location: member.php'); exit;
        }
        $error = '아이디 또는 비밀번호가 올바르지 않습니다.';
    }
}
?>
<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>로그인</title>
<style>body{margin:0;padding:24px;background:#121212;color:#eee;font-family:sans-serif}.box{max-width:420px;margin:40px auto;background:#1e1e1e;border:1px solid #333;border-radius:10px;padding:24px}input{box-sizing:border-box;width:100%;padding:12px;margin:7px 0 15px;background:#111;color:#fff;border:1px solid #444;border-radius:5px}button,.btn{display:inline-block;padding:11px 15px;background:#b9e3ff;color:#172b3a;border:0;border-radius:5px;font-weight:bold;text-decoration:none;cursor:pointer}.error{color:#ff7777}</style></head><body><div class="box">
<h1>회원 로그인</h1><?php if($error!==''):?><p class="error"><?=site_e($error)?></p><?php endif;?>
<form method="post"><input type="hidden" name="csrf" value="<?=site_e(site_csrf())?>">
<label>아이디</label><input name="username" required autocomplete="username">
<label>비밀번호</label><input type="password" name="password" required autocomplete="current-password">
<button type="submit">로그인</button></form>
<p><a class="btn" href="signup.php">회원가입</a> <a class="btn" href="guest.php">게스트로 입장</a></p>
<p><a href="index.php">메인으로</a></p></div></body></html>
