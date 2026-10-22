<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('SITE_MEMBERS_FILE', '/var/lib/site-live/members.json');
define('SITE_MEMBERS_LOCK', '/var/lib/site-live/members.lock');

function site_e($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function site_member_data() {
    if (!is_file(SITE_MEMBERS_FILE)) {
        return ['users' => []];
    }
    $raw = @file_get_contents(SITE_MEMBERS_FILE);
    $data = json_decode($raw ?: '', true);
    return is_array($data) && isset($data['users']) && is_array($data['users'])
        ? $data : ['users' => []];
}

function site_save_members($data) {
    $lock = @fopen(SITE_MEMBERS_LOCK, 'c');
    if (!$lock) {
        throw new RuntimeException('회원 데이터 잠금 파일을 열 수 없습니다.');
    }
    try {
        if (!flock($lock, LOCK_EX)) {
            throw new RuntimeException('회원 데이터 잠금에 실패했습니다.');
        }
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false ||
            file_put_contents(SITE_MEMBERS_FILE, $json, LOCK_EX) === false) {
            throw new RuntimeException('회원 데이터를 저장하지 못했습니다.');
        }
        flock($lock, LOCK_UN);
    } finally {
        fclose($lock);
    }
}

function site_nick_key($nickname) {
    $nickname = trim((string)$nickname);
    $nickname = preg_replace('/\s+/u', ' ', $nickname);
    return function_exists('mb_strtolower')
        ? mb_strtolower($nickname, 'UTF-8')
        : strtolower($nickname);
}

function site_nickname_taken($nickname, $exceptId = '') {
    $key = site_nick_key($nickname);
    foreach (site_member_data()['users'] as $u) {
        if (($u['id'] ?? '') === $exceptId) continue;
        if (site_nick_key($u['nickname'] ?? '') === $key) return true;
    }
    return false;
}

function site_username_taken($username) {
    foreach (site_member_data()['users'] as $u) {
        if (strcasecmp((string)($u['username'] ?? ''), trim($username)) === 0) {
            return true;
        }
    }
    return false;
}

function site_member_by_id($id) {
    if (!$id) return null;
    foreach (site_member_data()['users'] as $u) {
        if (($u['id'] ?? '') === $id) return $u;
    }
    return null;
}

function site_current_member() {
    $id = (string)($_SESSION['site_member_id'] ?? '');
    if ($id === '') return null;
    $u = site_member_by_id($id);
    if (!$u || !empty($u['suspended'])) {
        unset($_SESSION['site_member_id']);
        return null;
    }
    return $u;
}

function site_csrf() {
    if (empty($_SESSION['site_csrf'])) {
        $_SESSION['site_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['site_csrf'];
}

function site_valid_csrf() {
    return isset($_POST['csrf'], $_SESSION['site_csrf'])
        && hash_equals((string)$_SESSION['site_csrf'], (string)$_POST['csrf']);
}

function site_role_label($role) {
    $labels = [
        'member' => '💠 회원',
        'operator' => '🔷 운영자',
        'angel' => '🪽 천사',
        'special_guest' => '🍀 특별 게스트'
    ];
    return $labels[$role] ?? '💠 회원';
}
