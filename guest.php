<?php
require_once __DIR__ . '/lib/members_lib.php';
if (empty($_SESSION['guest_name'])) {
    $_SESSION['guest_name'] = '게스트_' . strtoupper(bin2hex(random_bytes(2)));
}
$_SESSION['guest_mode'] = true;
header('Location: board.php');
exit;
