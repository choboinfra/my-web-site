<?php
require_once __DIR__ . '/lib/members_lib.php';
if (site_current_member()) {
    header('Location: member.php'); exit;
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!site_valid_csrf()) {
        $error = '요청이 만료되었습니다. 새로고침해 주세요.';
    } else {
        $username = trim((string)($_POST['username'] ?? ''));
        $nickname = trim((string)($_POST['nickname'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $confirm = (string)($_POST['confirm'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_.-]{3,24}$/', $username)) {
            $error = '아이디는 영문, 숫자, 점, 밑줄, 하이픈으로 3~24자여야 합니다.';
        } elseif (preg_match('/[\x00-\x1F\x7F]/', $nickname) ||
            mb_strlen($nickname, 'UTF-8') < 2 ||
            mb_strlen($nickname, 'UTF-8') > 20) {
            $error = '닉네임은 2~20자로 입력해 주세요.';
        } elseif (strlen($password) < 10 || strlen($password) > 200) {
            $error = '비밀번호는 10자 이상 200자 이하로 입력해 주세요.';
        } elseif ($password !== $confirm) {
            $error = '비밀번호가 일치하지 않습니다.';
        } elseif (site_username_taken($username)) {
            $error = '이미 사용 중인 아이디입니다.';
        } elseif (site_nickname_taken($nickname)) {
            $error = '이미 사용 중인 닉네임입니다.';
        } else {
            $data = site_member_data();
            $data['users'][] = [
                'id' => bin2hex(random_bytes(16)),
                'username' => $username,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'nickname' => $nickname,
                'role' => 'member',
                'created_at' => date('Y-m-d H:i:s'),
                'last_nickname_change' => 0,
                'nickname_request' => null,
                'suspended' => false
            ];
            site_save_members($data);
            $newUser = end($data['users']);
            session_regenerate_id(true);
            $_SESSION['site_member_id'] = $newUser['id'];
            header('Location: member.php'); exit;
        }
    }
}
?>
<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>회원가입</title>
<style>
body{margin:0;padding:24px;background:#121212;color:#eee;font-family:sans-serif}.box{max-width:420px;margin:40px auto;background:#1e1e1e;border:1px solid #333;border-radius:10px;padding:24px}input{box-sizing:border-box;width:100%;padding:12px;margin:7px 0 15px;background:#111;color:#fff;border:1px solid #444;border-radius:5px}button,.btn{display:inline-block;padding:11px 15px;background:#b9e3ff;color:#172b3a;border:0;border-radius:5px;font-weight:bold;text-decoration:none;cursor:pointer}.error{color:#ff7777}.muted{color:#aaa;font-size:13px}
</style></head><body><div class="box">
<h1>회원가입</h1><p class="muted">가입하면 고유 닉네임과 프로필을 사용할 수 있습니다.</p>
<?php if ($error !== ''): ?><p class="error"><?=site_e($error)?></p><?php endif; ?>
<form method="post">
<input type="hidden" name="csrf" value="<?=site_e(site_csrf())?>">
<label>아이디</label><input name="username" required minlength="3" maxlength="24" autocomplete="username">
<label>닉네임 (2~20자)</label><input name="nickname" required minlength="2" maxlength="20">
<label>비밀번호 (10자 이상)</label><input type="password" name="password" required minlength="10" maxlength="200" autocomplete="new-password">
<label>비밀번호 확인</label><input type="password" name="confirm" required maxlength="200" autocomplete="new-password">
<button type="submit">가입하고 시작하기</button>
</form><p><a class="btn" href="login.php">로그인</a> <a class="btn" href="index.php">메인으로</a></p>
</div></body></html>
