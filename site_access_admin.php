<?php
require_once __DIR__ . '/lib/members_lib.php';

if (empty($_SESSION['admin_logged_in'])) {
    http_response_code(403);
    exit('관리자 로그인 후 이용할 수 있습니다.');
}

$settingsFile = '/var/lib/site-live/site_access.json';
$pages = [
    'lunch.html' => '오늘의 점심 메뉴',
    'starwars.html' => '턴 게임',
    'guestbook.php' => '방명록',
    'chat.php' => '실시간 채팅',
    'board.php' => '익명 게시판',
    'dark_path.php' => '어둠의 경로',
    'filemanager.php' => '파일 관리자',
    'upload.php' => '파일 업로드',
    'guest.php' => '게스트 입장',
    'login.php' => '회원 로그인',
    'signup.php' => '회원가입',

];

function access_read_settings($path) {
    if (!is_file($path)) return ['blocked' => []];
    $data = json_decode((string)file_get_contents($path), true);
    return is_array($data) && isset($data['blocked']) && is_array($data['blocked'])
        ? $data : ['blocked' => []];
}

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!site_valid_csrf()) {
        http_response_code(400);
        exit('요청 검증에 실패했습니다. 페이지를 새로고침해 주세요.');
    }

    $blocked = $_POST['blocked'] ?? [];
    if (!is_array($blocked)) $blocked = [];
    $blocked = array_values(array_unique(array_filter(
        $blocked,
        static fn($p) => is_string($p) && array_key_exists($p, $pages)
    )));

    $json = json_encode(
        ['blocked' => $blocked, 'updated_at' => date(DATE_ATOM)],
        JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
    );

    if ($json === false ||
        file_put_contents($settingsFile, $json, LOCK_EX) === false) {
        $message = '설정 저장에 실패했습니다. 서버 권한을 확인해 주세요.';
    } else {
        @chmod($settingsFile, 0640);
        $message = '접근 설정을 저장했습니다.';
    }
}

$settings = access_read_settings($settingsFile);
$blockedNow = $settings['blocked'];
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>페이지 접근 관리</title>
<style>
body{margin:0;padding:20px;background:#111;color:#eee;font:16px sans-serif}
main{max-width:760px;margin:auto}
h1{font-size:24px}
.note{color:#bbb;line-height:1.7}
.item{display:flex;gap:12px;align-items:center;padding:14px;margin:9px 0;background:#1c1c1c;border:1px solid #393939;border-radius:9px}
.item input{width:19px;height:19px}
button,a{font:inherit}
button{padding:11px 16px;background:#b9e3ff;color:#172b3a;border:0;border-radius:7px;font-weight:bold;cursor:pointer}
a{color:#9bd5ff}
.message{color:#9fdfac}
</style>
</head>
<body>
<main>
<h1>페이지별 접근 관리</h1>
<p class="note">체크한 페이지는 일반 사용자에게 차단됩니다. 관리자는 차단된 페이지도 확인할 수 있습니다. 체크하지 않은 페이지는 기존 인증 규칙을 따릅니다.</p>
<?php if ($message !== ''): ?><p class="message"><?=site_e($message)?></p><?php endif; ?>
<form method="post">
<input type="hidden" name="csrf" value="<?=site_e(site_csrf())?>">
<?php foreach ($pages as $path => $label): ?>
<label class="item">
<input type="checkbox" name="blocked[]" value="<?=site_e($path)?>" <?=in_array($path, $blockedNow, true) ? 'checked' : ''?>>
<span><strong><?=site_e($label)?></strong><br><small><?=site_e($path)?></small></span>
</label>
<?php endforeach; ?>
<p><button type="submit">접근 설정 저장</button></p>
</form>
<p><a href="/index.php">메인 메뉴</a> · <a href="/admin_members.php">회원 관리</a></p>
</main>
</body>
</html>
