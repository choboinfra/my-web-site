<?php
require_once '/var/lib/site-live/members_lib.php';
unset($_SESSION['guest_mode'], $_SESSION['guest_name']);
header('Location: /index.php');
exit;
