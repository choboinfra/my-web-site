<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$account_file = '/var/lib/site-live/admin_accounts.json';
$legacy_password = 'G2271207112!';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['admin_pw'] ?? '');

    $authenticated = false;

    // 기존 비밀번호 방식 유지
    if ($username === '' && hash_equals($legacy_password, $password)) {
        $authenticated = true;
    }

    // 계정 파일 기반 로그인
    if (!$authenticated && $username !== '' && is_readable($account_file)) {
        $json = file_get_contents($account_file);
        $data = json_decode($json ?: '', true);

        if (is_array($data) && isset($data['users']) &&
            is_array($data['users'])) {
            foreach ($data['users'] as $user) {
                if (
                    isset($user['username'], $user['password_hash']) &&
                    strcasecmp((string)$user['username'], $username) === 0 &&
                    password_verify($password, (string)$user['password_hash'])
                ) {
                    $authenticated = true;
                    break;
                }
            }
        }
    }

    if ($authenticated) {
        session_regenerate_id(true);
        $_SESSION['admin_logged_in'] = true;
        header('Location: filemanager.php');
        exit;
    }

    $error = '아이디 또는 비밀번호가 올바르지 않습니다.';
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>관리자 로그인</title>
<style>
body {
    font-family: sans-serif;
    background: #121212;
    color: #eee;
    display: flex;
    justify-content: center;
    align-items: center;
    min-height: 100vh;
    margin: 0;
    padding: 16px;
    box-sizing: border-box;
}
.login-box {
    background: #1e1e1e;
    padding: 30px;
    border: 1px solid #333;
    border-radius: 8px;
    width: 100%;
    max-width: 340px;
    box-sizing: border-box;
}
h3 { color: #ff5252; margin-top: 0; }
input {
    width: 100%;
    padding: 11px;
    box-sizing: border-box;
    background: #121212;
    border: 1px solid #444;
    color: #fff;
    border-radius: 4px;
    margin: 7px 0 15px;
}
button {
    background: #ff5252;
    color: #fff;
    font-weight: bold;
    border: none;
    padding: 11px;
    width: 100%;
    cursor: pointer;
    border-radius: 4px;
}
.error { color: #ff7777; }
.back-link {
    display: block;
    margin-top: 15px;
    color: #80deea;
    text-decoration: none;
}
</style>
</head>
<body>
<div class="login-box">
    <h3>🛠️ 관리자 로그인</h3>

    <?php if ($error !== ''): ?>
        <p class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>

    <form method="POST" autocomplete="on">
        <label for="username">관리자 아이디</label>
        <input id="username" name="username"
               autocomplete="username" placeholder="choadmin">

        <label for="admin_pw">비밀번호</label>
        <input id="admin_pw" type="password" name="admin_pw"
               autocomplete="current-password" required>

        <button type="submit">로그인</button>
    </form>

    <a href="index.php" class="back-link">← 메인으로 돌아가기</a>
</div>
</body>
</html>
