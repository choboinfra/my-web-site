<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$dataDir = '/var/lib/site-live';
$dataFile = "$dataDir/presence.json";
$lockFile = "$dataDir/presence.lock";

function respond(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function updateVisitors(bool $heartbeat): array {
    global $dataFile, $lockFile;

    $lock = fopen($lockFile, 'c');
    if (!$lock || !flock($lock, LOCK_EX)) {
        respond(['error' => '저장소 잠금 실패'], 500);
    }

    $raw = @file_get_contents($dataFile);
    $visitors = json_decode($raw ?: '{}', true);
    if (!is_array($visitors)) $visitors = [];

    $now = time();

    // 60초 이상 활동이 없는 방문자는 제거
    foreach ($visitors as $key => $visitor) {
        if (!is_array($visitor) || ($now - (int)($visitor['time'] ?? 0)) > 60) {
            unset($visitors[$key]);
        }
    }

    if ($heartbeat) {
        session_start();

        // IP 주소는 저장하지 않음
        $id = hash('sha256', session_id());
        $page = basename((string)($_POST['page'] ?? 'index.html'));
        $page = preg_replace('/[^a-zA-Z0-9_.-]/', '', $page);
        if ($page === '') $page = 'index.html';

        $pageNames = [
            'index.html' => '메인 페이지',
            'chat.php' => '실시간 채팅',
            '실시간채팅' => '실시간 채팅',
            'guestbook.php' => '방명록',
            'admin_login.php' => '관리자 페이지',
            'lunch.html' => '점심 메뉴',
            'starwars.html' => '스타크래프트 턴 게임',
            'dark_path.php' => '비밀 페이지',
        ];
        $page = $pageNames[$page] ?? $page;

        $visitors[$id] = [
            'time' => $now,
            'page' => $page
        ];
    }

    $json = json_encode($visitors, JSON_UNESCAPED_UNICODE);
    $ok = $json !== false &&
        file_put_contents($dataFile, $json, LOCK_EX) !== false;

    flock($lock, LOCK_UN);
    fclose($lock);

    if (!$ok) respond(['error' => '저장 실패'], 500);
    return $visitors;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['action'] ?? '') !== 'heartbeat') {
        respond(['error' => '잘못된 요청'], 400);
    }

    $visitors = updateVisitors(true);
    respond(['ok' => true, 'count' => count($visitors)]);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' &&
    isset($_GET['stats'])) {
    $visitors = updateVisitors(false);
    $pages = [];

    foreach ($visitors as $visitor) {
        $page = (string)($visitor['page'] ?? 'unknown');
        $pages[$page] = ($pages[$page] ?? 0) + 1;
    }

    arsort($pages);
    respond([
        'count' => count($visitors),
        'pages' => $pages,
        'updated' => date('H:i:s')
    ]);
}

respond(['error' => '잘못된 요청'], 400);
