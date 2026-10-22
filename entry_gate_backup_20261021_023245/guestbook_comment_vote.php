<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

session_start();

$commentId = (string)($_POST['comment_id'] ?? $_GET['comment_id'] ?? '');
$voteType = (string)($_POST['vote'] ?? '');

if ($commentId === '' ||
    !preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $commentId)) {
    respond(['error' => '잘못된 댓글 ID'], 400);
}

/* 실제 방명록에 존재하는 댓글인지 확인 */
$guestbookFile = __DIR__ . '/guestbook_data.txt';
$entries = json_decode(
    (string)@file_get_contents($guestbookFile),
    true
);

if (!is_array($entries)) {
    respond(['error' => '방명록 데이터를 읽을 수 없습니다.'], 500);
}

$found = false;
foreach ($entries as $entry) {
    foreach (($entry['comments'] ?? []) as $comment) {
        if ((string)($comment['c_id'] ?? '') === $commentId) {
            $found = true;
            break 2;
        }
    }
}

if (!$found) {
    respond(['error' => '댓글을 찾을 수 없습니다.'], 404);
}

$dataDir = '/var/lib/site-live';
$dataFile = $dataDir . '/guestbook_comment_votes.json';
$lockFile = $dataDir . '/guestbook_comment_votes.lock';

$lock = @fopen($lockFile, 'c');
if ($lock === false) {
    respond(['error' => '투표 저장소에 접근할 수 없습니다.'], 500);
}

$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';

if ($isPost) {
    if (($_POST['action'] ?? '') !== 'vote' ||
        !in_array($voteType, ['like', 'dislike'], true)) {
        flock($lock, LOCK_UN);
        fclose($lock);
        respond(['error' => '잘못된 투표 요청'], 400);
    }
    flock($lock, LOCK_EX);
} else {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        flock($lock, LOCK_UN);
        fclose($lock);
        respond(['error' => '지원하지 않는 요청'], 405);
    }
    flock($lock, LOCK_SH);
}

$raw = @file_get_contents($dataFile);
$allVotes = json_decode($raw ?: '{}', true);
if (!is_array($allVotes)) {
    $allVotes = [];
}

if (!isset($allVotes[$commentId]) ||
    !is_array($allVotes[$commentId])) {
    $allVotes[$commentId] = [];
}

$sessionKey = hash('sha256', session_id());

if ($isPost) {
    $current = $allVotes[$commentId][$sessionKey] ?? null;

    // 같은 버튼을 다시 누르면 취소, 다른 버튼이면 변경
    if ($current === $voteType) {
        unset($allVotes[$commentId][$sessionKey]);
    } else {
        $allVotes[$commentId][$sessionKey] = $voteType;
    }

    $json = json_encode($allVotes, JSON_UNESCAPED_UNICODE);
    if ($json === false ||
        file_put_contents($dataFile, $json, LOCK_EX) === false) {
        flock($lock, LOCK_UN);
        fclose($lock);
        respond(['error' => '투표 저장에 실패했습니다.'], 500);
    }
}

$likes = 0;
$dislikes = 0;
$mine = $allVotes[$commentId][$sessionKey] ?? null;

foreach ($allVotes[$commentId] as $voterVote) {
    if ($voterVote === 'like') {
        $likes++;
    } elseif ($voterVote === 'dislike') {
        $dislikes++;
    }
}

flock($lock, LOCK_UN);
fclose($lock);

respond([
    'ok' => true,
    'comment_id' => $commentId,
    'likes' => $likes,
    'dislikes' => $dislikes,
    'mine' => $mine
]);
