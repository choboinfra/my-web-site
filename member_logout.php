<?php
require_once __DIR__ . '/lib/members_lib.php';
unset($_SESSION['site_member_id']);
session_regenerate_id(true);
header('Location: index.php');
exit;
