<?php
require_once '/var/lib/site-live/members_lib.php';
unset($_SESSION['site_member_id']);
session_regenerate_id(true);
header('Location: index.php');
exit;
