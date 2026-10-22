<?php
require_once __DIR__ . '/lib/members_lib.php';
unset($_SESSION['guest_mode'], $_SESSION['guest_name']);
header('Location: /index.php');
exit;
