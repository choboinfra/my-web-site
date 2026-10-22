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

$_SERVER['SCRIPT_FILENAME'] = $file;
$_SERVER['SCRIPT_NAME'] = '/' . $target;
$_SERVER['PHP_SELF'] = '/' . $target;

require $file;
