
<?php
$file = __DIR__ . '/guestbook_data.txt';

// 실제 접속 IP 가져오기
function getRealIP() {
    // 프록시를 사용하지 않는 일반 서버 기준
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

// IP 마스킹
function maskIP($ip) {
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $parts = explode('.', $ip);
        return $parts[0] . '.' . $parts[1] . '.*.*';
    }

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        return 'IPv6';
    }

    return '?.?.?.?';
}

// 유튜브 URL을 임베드 URL로 변환
function getYouTubeEmbedUrl($url) {
    $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');

    $patterns = [
        '~https?://(?:www\.)?youtube\.com/watch\?(?:[^#\s]*&)?v=([a-zA-Z0-9_-]{11})~i',
        '~https?://youtu\.be/([a-zA-Z0-9_-]{11})~i',
        '~https?://(?:www\.)?youtube\.com/shorts/([a-zA-Z0-9_-]{11})~i',
        '~https?://(?:www\.)?youtube\.com/embed/([a-zA-Z0-9_-]{11})~i',
        '~https?://(?:www\.)?youtube-nocookie\.com/embed/([a-zA-Z0-9_-]{11})~i'
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $url, $matches)) {
            return 'https://www.youtube-nocookie.com/embed/' . $matches[1];
        }
    }

    return false;
}

// 게시글 내용 출력: HTML 이스케이프 + 유튜브 미리보기
function renderContent($content) {
    $content = html_entity_decode(
        (string)$content,
        ENT_QUOTES | ENT_HTML5,
        'UTF-8'
    );

    $lines = preg_split('/\r\n|\r|\n/', $content);

    foreach ($lines as $line) {
        $safeLine = htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        echo '<div>' . $safeLine . '</div>';

        if (preg_match(
            '~https?://[^\s<>"\']+~i',
            $line,
            $matches
        )) {
            $url = rtrim($matches[0], ".,!?;:)]}");
            $embedUrl = getYouTubeEmbedUrl($url);

            if ($embedUrl !== false) {
                $safeEmbedUrl = htmlspecialchars(
                    $embedUrl,
                    ENT_QUOTES | ENT_SUBSTITUTE,
                    'UTF-8'
                );

                echo '<div class="youtube-preview">';
                echo '<iframe src="' . $safeEmbedUrl . '" ';
                echo 'title="YouTube video player" ';
                echo 'loading="lazy" ';
                echo 'referrerpolicy="strict-origin-when-cross-origin" ';
                echo 'allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" ';
                echo 'allowfullscreen></iframe>';
                echo '</div>';
            }
        }
    }
}

// 데이터 읽기
function loadEntries($file) {
    if (!file_exists($file)) {
        return [];
    }

    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

// POST 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 새 글 작성
    if ($action === 'write') {
        $dev_answer = trim($_POST['dev_answer'] ?? '');

        if ($dev_answer !== '조승훈') {
            echo "<script>alert('개발자 이름 정답이 틀렸습니다! 글을 쓸 수 없습니다.'); history.back();</script>";
            exit;
        }

        $name = trim($_POST['name'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        $content = trim($_POST['content'] ?? '');

        if ($name !== '' && $password !== '' && $content !== '') {
            $entries = loadEntries($file);

            array_unshift($entries, [
                'id' => time() . mt_rand(100, 999),
                'type' => 'post',
                'name' => htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                'password' => $password,
                'content' => htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                'ip' => maskIP(getRealIP()),
                'date' => date('Y-m-d H:i:s'),
                'comments' => []
            ]);

            file_put_contents(
                $file,
                json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                LOCK_EX
            );
        }

        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    // 댓글 작성
    if ($action === 'comment') {
        $post_id = (string)($_POST['post_id'] ?? '');
        $c_name = trim($_POST['c_name'] ?? '');
        $c_pw = (string)($_POST['c_pw'] ?? '');
        $c_content = trim($_POST['c_content'] ?? '');

        if ($c_name !== '' && $c_pw !== '' && $c_content !== '') {
            $entries = loadEntries($file);

            foreach ($entries as &$entry) {
                if ((string)($entry['id'] ?? '') === $post_id) {
                    if (!isset($entry['comments']) || !is_array($entry['comments'])) {
                        $entry['comments'] = [];
                    }

                    $entry['comments'][] = [
                        'c_id' => time() . mt_rand(1000, 9999),
                        'c_name' => htmlspecialchars($c_name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                        'c_pw' => $c_pw,
                        'c_content' => htmlspecialchars($c_content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                        'c_ip' => maskIP(getRealIP()),
                        'date' => date('Y-m-d H:i:s')
                    ];

                    break;
                }
            }
            unset($entry);

            file_put_contents(
                $file,
                json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                LOCK_EX
            );
        }

        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    // 글 또는 댓글 삭제
    if ($action === 'delete') {
        $target_id = (string)($_POST['target_id'] ?? '');
        $del_pw = (string)($_POST['password'] ?? '');
        $is_comment = isset($_POST['is_comment']);

        $entries = loadEntries($file);

        if ($is_comment) {
            foreach ($entries as &$entry) {
                if (empty($entry['comments']) || !is_array($entry['comments'])) {
                    continue;
                }

                foreach ($entry['comments'] as $idx => $comm) {
                    if (
                        (string)($comm['c_id'] ?? '') === $target_id &&
                        (string)($comm['c_pw'] ?? '') === $del_pw
                    ) {
                        unset($entry['comments'][$idx]);
                        $entry['comments'] = array_values($entry['comments']);
                        break;
                    }
                }
            }
            unset($entry);
        } else {
            $entries = array_values(array_filter(
                $entries,
                function ($entry) use ($target_id, $del_pw) {
                    if ((string)($entry['id'] ?? '') === $target_id) {
                        return (string)($entry['password'] ?? '') !== $del_pw;
                    }

                    return true;
                }
            ));
        }

        file_put_contents(
            $file,
            json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            LOCK_EX
        );

        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

$entries = loadEntries($file);
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>방명록</title>

<style>
* {
    box-sizing: border-box;
}

body {
    font-family: sans-serif;
    background: #121212;
    color: #e0e0e0;
    margin: 0;
    padding: 20px;
}

.container {
    max-width: 750px;
    margin: 0 auto;
    background: #1e1e1e;
    padding: 20px;
    border: 1px solid #333;
    border-radius: 6px;
}

h2 {
    border-bottom: 2px solid #00acc1;
    padding-bottom: 10px;
    color: #fff;
}

a {
    color: #80deea;
    text-decoration: none;
}

a:hover {
    text-decoration: underline;
}

.write-toggle-btn {
    background: #00acc1;
    color: #000;
    font-weight: bold;
    border: none;
    padding: 12px 20px;
    cursor: pointer;
    width: 100%;
    border-radius: 4px;
    font-size: 16px;
    margin-bottom: 20px;
}

.write-toggle-btn:hover {
    background: #26c6da;
}

.form-box {
    display: none;
    background: #2a2a2a;
    padding: 15px;
    border-radius: 4px;
    border: 1px solid #444;
    margin-bottom: 20px;
}

.form-group {
    margin-bottom: 10px;
}

label {
    display: block;
    font-size: 0.9em;
    margin-bottom: 4px;
    color: #b0b0b0;
}

input,
textarea {
    width: 100%;
    padding: 8px;
    background: #121212;
    border: 1px solid #444;
    color: #fff;
    border-radius: 3px;
}

button.submit-btn {
    background: #00acc1;
    color: #000;
    font-weight: bold;
    border: none;
    padding: 10px 20px;
    cursor: pointer;
    border-radius: 3px;
}

.post {
    background: #222;
    border: 1px solid #333;
    padding: 15px;
    margin-bottom: 15px;
    border-radius: 4px;
    min-width: 0;
}

.post-header {
    display: flex;
    justify-content: space-between;
    gap: 10px;
    font-weight: bold;
    color: #ffb74d;
    border-bottom: 1px solid #333;
    padding-bottom: 8px;
    margin-bottom: 10px;
}

.post-date {
    font-size: 0.8em;
    color: #888;
    font-weight: normal;
}

.post-ip {
    font-size: 0.75em;
    color: #80deea;
    font-weight: normal;
    margin-left: 5px;
}

.post-content {
    overflow-wrap: anywhere;
    margin-bottom: 15px;
    line-height: 1.5;
}

.youtube-preview {
    position: relative;
    width: 100%;
    max-width: 560px;
    aspect-ratio: 16 / 9;
    margin: 12px 0;
    background: #000;
    border-radius: 8px;
    overflow: hidden;
}

.youtube-preview iframe {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    border: 0;
}

.del-form {
    display: inline-block;
    float: right;
}

.del-form input {
    width: 80px;
    padding: 2px;
    font-size: 0.8em;
    display: inline-block;
}

.del-form button {
    background: #d32f2f;
    color: #fff;
    border: none;
    padding: 3px 8px;
    font-size: 0.8em;
    cursor: pointer;
    border-radius: 2px;
}

.comments-section {
    background: #181818;
    padding: 10px;
    border-radius: 4px;
    margin-top: 10px;
    border-top: 1px dashed #444;
}

.comment-item {
    font-size: 0.9em;
    padding: 6px 0;
    border-bottom: 1px solid #2a2a2a;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 8px;
}

.comment-form {
    display: flex;
    gap: 5px;
    margin-top: 10px;
}

.comment-form input {
    font-size: 0.85em;
    padding: 5px;
    min-width: 0;
}

.comment-form button {
    background: #444;
    color: #fff;
    border: none;
    padding: 5px 10px;
    cursor: pointer;
    font-size: 0.85em;
}


/* 좋아요 / 싫어요 */
.guestbook-vote-controls {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin: 12px 0 16px;
}
.guestbook-vote-btn {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 9px 14px;
    border: 1px solid #444;
    border-radius: 22px;
    background: #181818;
    color: #ddd;
    cursor: pointer;
    font-size: 14px;
    transition: background .15s, border-color .15s;
}
.guestbook-vote-btn:hover {
    background: #303030;
    border-color: #00acc1;
}
.guestbook-vote-btn.selected {
    background: #10383c;
    border-color: #00acc1;
    color: #80deea;
}
.guestbook-vote-btn:disabled {
    opacity: .6;
    cursor: wait;
}
.guestbook-vote-btn .vote-count {
    font-weight: bold;
    min-width: 1ch;
}

@media (max-width: 600px) {
    body {
        padding: 8px;
    }

    .container {
        padding: 12px;
    }

    .post-header {
        flex-direction: column;
    }

    .comment-form {
        flex-wrap: wrap;
    }

    .comment-form input {
        flex: 1 1 30%;
    }

    .comment-form input[name="c_content"] {
        flex: 1 1 100%;
    }
}

/* 댓글별 좋아요/싫어요 */
.comment-vote-controls {
    display: flex;
    gap: 6px;
    margin: 5px 0 8px;
    flex-wrap: wrap;
}
.comment-vote-btn {
    background: #202a30;
    color: #ddd;
    border: 1px solid #45545c;
    border-radius: 6px;
    padding: 4px 9px;
    font-size: 12px;
    cursor: pointer;
}
.comment-vote-btn:hover {
    background: #30434b;
}
.comment-vote-btn.selected {
    background: #075e54;
    border-color: #26a69a;
    color: #fff;
}
.comment-vote-btn:disabled {
    opacity: .6;
    cursor: wait;
}

</style>

<script>
function checkDeveloper() {
    const answer = prompt("개발자 이름은?");
    if (answer === null) return;

    if (answer.trim() === "조승훈") {
        const box = document.getElementById("writeBox");
        box.style.display =
            box.style.display === "none" || box.style.display === ""
                ? "block"
                : "none";
    } else {
        alert("틀렸습니다! 개발자 이름이 아닙니다.");
    }
}
</script>
</head>

<body>
<div class="container">

<h2>방명록</h2>

<p><a href="index.html">← 메인으로 돌아가기</a></p>

<button class="write-toggle-btn" onclick="checkDeveloper()">
    방명록 (개발자 암호 필요)
</button>

<div id="writeBox" class="form-box">
    <form method="POST">
        <input type="hidden" name="action" value="write">
        <input type="hidden" name="dev_answer" value="조승훈">

        <div style="display:flex;gap:10px" class="form-group">
            <div style="flex:1;min-width:0">
                <label>이름 (닉네임)</label>
                <input type="text" name="name" required placeholder="ㅇㅇ">
            </div>

            <div style="flex:1;min-width:0">
                <label>비밀번호 (삭제용)</label>
                <input type="password" name="password" required>
            </div>
        </div>

        <div class="form-group">
            <label>내용 (유튜브 링크를 붙여넣으면 영상이 표시됩니다)</label>
            <textarea name="content" rows="4" required placeholder="내용과 유튜브 링크를 입력하세요.&#10;https://www.youtube.com/watch?v=영상ID"></textarea>
        </div>

        <button type="submit" class="submit-btn">등록하기</button>
    </form>
</div>

<div class="post-list">

<?php if (empty($entries)): ?>
    <p style="text-align:center;color:#777;padding:20px">
        아직 작성된 방명록이 없습니다.
    </p>
<?php else: ?>

    <?php foreach ($entries as $entry): ?>
    <div class="post">

        <div class="post-header">
            <span>
                <?= $entry['name'] ?? '익명' ?>

                <?php if (!empty($entry['ip'])): ?>
                    <span class="post-ip">(<?= $entry['ip'] ?>)</span>
                <?php endif; ?>
            </span>

            <div>
                <span class="post-date"><?= $entry['date'] ?? '' ?></span>

                <form method="POST" class="del-form"
                      onsubmit="return confirm('정말 삭제하시겠습니까?');">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="target_id"
                           value="<?= htmlspecialchars((string)($entry['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="password" name="password" placeholder="비번" required>
                    <button type="submit">삭제</button>
                </form>
            </div>
        </div>

        <div class="post-content"><?php renderContent($entry['content'] ?? ''); ?></div>

        <div class="guestbook-vote-controls" data-post-id="<?= htmlspecialchars((string)($entry['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            <button type="button" class="guestbook-vote-btn" data-vote="like" aria-label="좋아요">
                👍 좋아요 <span class="vote-count">0</span>
            </button>
            <button type="button" class="guestbook-vote-btn" data-vote="dislike" aria-label="싫어요">
                👎 싫어요 <span class="vote-count">0</span>
            </button>
        </div>

        <div class="comments-section">
            <div style="font-size:.85em;font-weight:bold;color:#aaa;margin-bottom:5px">
                댓글
            </div>

            <?php if (!empty($entry['comments']) && is_array($entry['comments'])): ?>
                <?php foreach ($entry['comments'] as $comm): ?>
                <div class="comment-item">
                    <div style="min-width:0;overflow-wrap:anywhere">
                        <strong style="color:#90caf9"><?= $comm['c_name'] ?? '익명' ?></strong>

                        <?php if (!empty($comm['c_ip'])): ?>
                            <span style="font-size:.7em;color:#64b5f6">
                                (<?= $comm['c_ip'] ?>)
                            </span>
                        <?php endif; ?>

                        : <?= $comm['c_content'] ?? '' ?>

                        <span style="font-size:.75em;color:#666">
                            (<?= $comm['date'] ?? '' ?>)
                        </span>
                    </div>

                    <div class="comment-vote-controls"
                         data-comment-id="<?= htmlspecialchars((string)($comm['c_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                        <button type="button" class="comment-vote-btn" data-vote="like">
                            👍 <span class="vote-count">0</span>
                        </button>
                        <button type="button" class="comment-vote-btn" data-vote="dislike">
                            👎 <span class="vote-count">0</span>
                        </button>
                    </div>

                    <form method="POST" style="display:inline;flex-shrink:0"
                          onsubmit="return confirm('댓글을 삭제하시겠습니까?');">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="target_id"
                               value="<?= htmlspecialchars((string)($comm['c_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="is_comment" value="1">
                        <input type="password" name="password" placeholder="비번"
                               style="width:50px;font-size:.75em;background:#121212;color:#fff;border:1px solid #444"
                               required>
                        <button type="submit"
                                style="background:#b71c1c;color:#fff;border:none;padding:2px 5px;font-size:.75em;cursor:pointer">
                            삭제
                        </button>
                    </form>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <form method="POST" class="comment-form">
                <input type="hidden" name="action" value="comment">
                <input type="hidden" name="post_id"
                       value="<?= htmlspecialchars((string)($entry['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">

                <input type="text" name="c_name" placeholder="닉네임"
                       style="width:20%" required>
                <input type="password" name="c_pw" placeholder="비번"
                       style="width:20%" required>
                <input type="text" name="c_content" placeholder="댓글을 입력하세요"
                       style="width:50%" required>
                <button type="submit">등록</button>
            </form>
        </div>

    </div>
    <?php endforeach; ?>

<?php endif; ?>

</div>
</div>

<script>
(() => {
    const boxes = [...document.querySelectorAll('.guestbook-vote-controls')];
    if (!boxes.length) return;

    function updateBox(box, vote) {
        for (const type of ['like', 'dislike']) {
            const btn = box.querySelector('[data-vote="' + type + '"]');
            const count = btn.querySelector('.vote-count');
            count.textContent = vote[type === 'like' ? 'likes' : 'dislikes'] ?? 0;
            btn.classList.toggle('selected', vote.mine === type);
            btn.setAttribute('aria-pressed', vote.mine === type ? 'true' : 'false');
        }
    }

    async function loadCounts() {
        const ids = boxes.map(box => box.dataset.postId).filter(Boolean);
        if (!ids.length) return;

        try {
            const response = await fetch(
                'guestbook_vote.php?ids=' + encodeURIComponent(ids.join(',')),
                {cache: 'no-store', credentials: 'same-origin'}
            );
            const data = await response.json();
            if (!response.ok || !data.votes) throw new Error('load failed');

            for (const box of boxes) {
                const vote = data.votes[box.dataset.postId];
                if (vote) updateBox(box, vote);
            }
        } catch (error) {
            console.error('투표 현황을 불러오지 못했습니다.', error);
        }
    }

    for (const box of boxes) {
        box.addEventListener('click', async event => {
            const button = event.target.closest('button[data-vote]');
            if (!button || button.disabled) return;

            button.disabled = true;
            try {
                const body = new URLSearchParams({
                    post_id: box.dataset.postId,
                    vote: button.dataset.vote
                });
                const response = await fetch('guestbook_vote.php', {
                    method: 'POST',
                    body,
                    credentials: 'same-origin',
                    headers: {'X-Requested-With': 'fetch'}
                });
                const data = await response.json();
                if (!response.ok || !data.ok) {
                    throw new Error(data.error || '투표 실패');
                }
                updateBox(box, data.vote);
            } catch (error) {
                alert(error.message || '투표 처리에 실패했습니다.');
            } finally {
                button.disabled = false;
            }
        });
    }

    loadCounts();
})();
</script>


<script>
(() => {
    const boxes = document.querySelectorAll('.comment-vote-controls');

    async function refresh(box) {
        const id = box.dataset.commentId;
        try {
            const r = await fetch(
                'guestbook_comment_vote.php?comment_id=' + encodeURIComponent(id),
                { cache: 'no-store', credentials: 'same-origin' }
            );
            const d = await r.json();
            if (!r.ok || !d.ok) return;
            update(box, d);
        } catch (e) {
            console.error('댓글 투표 현황을 불러오지 못했습니다.', e);
        }
    }

    function update(box, d) {
        for (const type of ['like', 'dislike']) {
            const btn = box.querySelector('[data-vote="' + type + '"]');
            if (!btn) continue;
            const count = btn.querySelector('.vote-count');
            count.textContent = d[type === 'like' ? 'likes' : 'dislikes'] ?? 0;
            const selected = d.mine === type;
            btn.classList.toggle('selected', selected);
            btn.setAttribute('aria-pressed', selected ? 'true' : 'false');
        }
    }

    boxes.forEach(box => {
        box.querySelectorAll('button[data-vote]').forEach(btn => {
            btn.addEventListener('click', async () => {
                if (btn.disabled) return;
                btn.disabled = true;
                try {
                    const body = new URLSearchParams({
                        action: 'vote',
                        comment_id: box.dataset.commentId,
                        vote: btn.dataset.vote
                    });
                    const r = await fetch('guestbook_comment_vote.php', {
                        method: 'POST',
                        body,
                        credentials: 'same-origin'
                    });
                    const d = await r.json();
                    if (!r.ok || !d.ok) {
                        alert(d.error || '투표 처리에 실패했습니다.');
                        return;
                    }
                    update(box, d);
                } catch (e) {
                    alert('서버와 통신하지 못했습니다. 잠시 후 다시 시도하세요.');
                } finally {
                    btn.disabled = false;
                }
            });
        });
        refresh(box);
    });
})();
</script>

</body>
</html>
