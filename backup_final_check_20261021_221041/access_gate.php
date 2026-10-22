<?php
require_once '/var/lib/site-live/members_lib.php';

$target = (string)($_GET['__target'] ?? 'index.php');
unset($_GET['__target']);

if ($target === '') {
    $target = 'index.php';
}

if (strpos($target, "\0") !== false ||
    preg_match('#(^|/)\.\.?(/|$)#', $target)) {
    http_response_code(400);
    exit('잘못된 요청입니다.');
}

$root = realpath(__DIR__);
$file = realpath(__DIR__ . '/' . $target);

if (!$root || !$file ||
    strpos($file, $root . DIRECTORY_SEPARATOR) !== 0 ||
    !is_file($file)) {
    http_response_code(404);
    exit('페이지를 찾을 수 없습니다.');
}

$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
if (!in_array($ext, ['php', 'html', 'htm'], true)) {
    http_response_code(404);
    exit('페이지를 찾을 수 없습니다.');
}

$publicPages = [
    'index.php',
    'index.html',
    'login.php',
    'signup.php',
    'guest.php',
    'admin_login.php'
];

$member = site_current_member();
$authorized = is_array($member)
    || !empty($_SESSION['guest_mode'])
    || !empty($_SESSION['admin_logged_in']);

if (!$authorized && !in_array($target, $publicPages, true)) {
    header('Location: /index.php', true, 302);
    exit;
}


/* site_access_control_insert_v1 */
$siteAccessFile = '/var/lib/site-live/site_access.json';
$siteManagedPages = [
    'lunch.html' => '오늘의 점심 메뉴',
    'starwars.html' => '턴 게임',
    'guestbook.php' => '방명록',
    'chat.php' => '실시간 채팅',
    'board.php' => '익명 게시판',
    'dark_path.php' => '어둠의 경로',
    'filemanager.php' => '파일 관리자',
    'upload.php' => '파일 업로드',
];

$siteIsAdmin = !empty($_SESSION['admin_logged_in']);
if (!$siteIsAdmin && isset($siteManagedPages[$target])) {
    $siteAccess = ['blocked' => []];
    if (is_file($siteAccessFile)) {
        $rawAccess = @file_get_contents($siteAccessFile);
        $decodedAccess = json_decode($rawAccess ?: '', true);
        if (is_array($decodedAccess)
            && isset($decodedAccess['blocked'])
            && is_array($decodedAccess['blocked'])) {
            $siteAccess = $decodedAccess;
        }
    }

    if (in_array($target, $siteAccess['blocked'], true)) {
        http_response_code(503);
        header('Retry-After: 300');
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!doctype html><html lang="ko"><meta charset="utf-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<title>페이지 점검 중</title>';
        echo '<body style="background:#111;color:#eee;font:16px sans-serif;';
        echo 'max-width:680px;margin:15vh auto;padding:24px">';
        echo '<h1>현재 페이지를 점검 중입니다.</h1>';
        echo '<p>관리자가 해당 페이지의 접근을 일시적으로 제한했습니다.</p>';
        echo '<p><a style="color:#8cf" href="/index.php">메인 메뉴로 돌아가기</a></p>';
        echo '</body></html>';
        exit;
    }
}
/* end site_access_control_insert_v1 */

$_SERVER['SCRIPT_FILENAME'] = $file;
$_SERVER['SCRIPT_NAME'] = '/' . $target;
$_SERVER['PHP_SELF'] = '/' . $target;

require $file;
