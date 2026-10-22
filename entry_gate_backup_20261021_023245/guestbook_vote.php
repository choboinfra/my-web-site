<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$dataFile = '/var/lib/site-live/guestbook_votes.json';
$lockFile = '/var/lib/site-live/guestbook_votes.lock';
$guestbookFile = __DIR__ . '/guestbook_data.txt';
$userKey = hash('sha256', session_id());

function reply(array $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function validId(string $id): bool {
    return (bool)preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $id);
}

function loadVotes(string $file): array {
    if (!is_file($file)) return [];
    $data = json_decode((string)file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function countsFor(array $votes, string $id, string $userKey): array {
    $items = $votes[$id] ?? [];
    if (!is_array($items)) $items = [];

    $likes = 0;
    $dislikes = 0;
    foreach ($items as $vote) {
        if ($vote === 'like') $likes++;
        if ($vote === 'dislike') $dislikes++;
    }

    return [
        'likes' => $likes,
        'dislikes' => $dislikes,
        'mine' => $items[$userKey] ?? ''
    ];
}

function postExists(string $id, string $file): bool {
    if (!is_file($file)) return false;
    $entries = json_decode((string)file_get_contents($file), true);
    if (!is_array($entries)) return false;

    foreach ($entries as $entry) {
        if ((string)($entry['id'] ?? '') === $id) return true;
    }
    return false;
}

if (!is_file($lockFile) || !is_file($dataFile)) {
    reply(['error' => '투표 저장소를 사용할 수 없습니다.'], 500);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $ids = explode(',', (string)($_GET['ids'] ?? ''));
    $ids = array_slice(array_unique(array_filter($ids, 'validId')), 0, 100);

    $lock = fopen($lockFile, 'c');
    if (!$lock || !flock($lock, LOCK_SH)) {
        reply(['error' => '투표 데이터를 읽을 수 없습니다.'], 500);
    }

    $votes = loadVotes($dataFile);
    $result = [];
    foreach ($ids as $id) {
        $result[$id] = countsFor($votes, $id, $userKey);
    }

    flock($lock, LOCK_UN);
    fclose($lock);
    reply(['votes' => $result]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    reply(['error' => '지원하지 않는 요청입니다.'], 405);
}

$id = (string)($_POST['post_id'] ?? '');
$choice = (string)($_POST['vote'] ?? '');

if (!validId($id) || !in_array($choice, ['like', 'dislike'], true)) {
    reply(['error' => '잘못된 투표입니다.'], 400);
}

if (!postExists($id, $guestbookFile)) {
    reply(['error' => '게시글을 찾을 수 없습니다.'], 404);
}

$lock = fopen($lockFile, 'c');
if (!$lock || !flock($lock, LOCK_EX)) {
    reply(['error' => '투표 저장소 잠금 실패'], 500);
}

$votes = loadVotes($dataFile);
if (!isset($votes[$id]) || !is_array($votes[$id])) {
    $votes[$id] = [];
}

$current = $votes[$id][$userKey] ?? '';
if ($current === $choice) {
    unset($votes[$id][$userKey]);
} else {
    $votes[$id][$userKey] = $choice;
}

if (empty($votes[$id])) unset($votes[$id]);

$json = json_encode($votes, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
if ($json === false || file_put_contents($dataFile, $json, LOCK_EX) === false) {
    flock($lock, LOCK_UN);
    fclose($lock);
    reply(['error' => '투표 저장 실패'], 500);
}

$result = countsFor($votes, $id, $userKey);
flock($lock, LOCK_UN);
fclose($lock);

reply(['ok' => true, 'vote' => $result]);
