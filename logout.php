<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// 관리자 권한만 해제하고 다른 세션 데이터는 유지
unset($_SESSION['admin_logged_in']);

// 세션 ID를 재발급해 이전 ID의 재사용을 방지
session_regenerate_id(true);

header('Location: index.php');
exit;
