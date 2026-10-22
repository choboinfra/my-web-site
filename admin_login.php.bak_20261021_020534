<?php
session_start();

// [설정] 관리자 암호 (원하시는 비밀번호로 변경 가능합니다, 예: '조승훈')
$admin_password = "G2271207112!"; 

$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input_pw = trim($_POST['admin_pw'] ?? '');
    
    if ($input_pw === $admin_password) {
        $_SESSION['admin_logged_in'] = true; // 관리자 인증 통행증 부여
        header('Location: filemanager.php'); // 인증 성공 시 파일매니저로 이동
        exit;
    } else {
        $error = "비밀번호가 틀렸습니다!";
    }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <title>관리자 인증</title>
    <style>
        body { font-family: sans-serif; background: #121212; color: #e0e0e0; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
        .login-box { background: #1e1e1e; padding: 30px; border: 1px solid #333; border-radius: 8px; width: 300px; text-align: center; box-shadow: 0 4px 10px rgba(0,0,0,0.5); }
        h3 { color: #ff5252; margin-top: 0; }
        input { width: 100%; padding: 10px; box-sizing: border-box; background: #121212; border: 1px solid #444; color: #fff; border-radius: 4px; margin-bottom: 15px; }
        button { background: #ff5252; color: #fff; font-weight: bold; border: none; padding: 10px; width: 100%; cursor: pointer; border-radius: 4px; }
        button:hover { background: #ff1744; }
        .error { color: #ff5252; font-size: 0.85em; margin-bottom: 10px; }
        .back-link { display: block; margin-top: 15px; color: #80deea; font-size: 0.85em; text-decoration: none; }
        .back-link:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="login-box">
        <h3>🛠️ 관리자 페이지 접근</h3>
        <p style="font-size: 0.9em; color: #aaa;">비밀번호를 입력하세요.</p>
        
        <?php if (!empty($error)): ?>
            <div class="error"><?= $error ?></div>
        <?php endif; ?>

        <form method="POST">
            <input type="password" name="admin_pw" placeholder="비밀번호" required autofocus>
            <button type="submit">입장하기</button>
        </form>
        <a href="index.php" class="back-link">← 메인으로 돌아가기</a>
    </div>
</body>
</html>