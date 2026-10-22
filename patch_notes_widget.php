<?php
// Patch notes core - shared NFS storage
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pnFile = '/var/lib/site-live/patch_notes/patch_notes.json';
$pnLock = '/var/lib/site-live/patch_notes/patch_notes.lock';
$pnAdmin = !empty($_SESSION['admin_logged_in']);
$pnError = '';

function pn_h($value) {
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function pn_read() {
    global $pnFile;

    if (!is_file($pnFile)) {
        return [];
    }

    $raw = @file_get_contents($pnFile);
    $rows = json_decode($raw === false ? '' : $raw, true);

    return is_array($rows)
        ? array_values(array_filter($rows, 'is_array'))
        : [];
}

function pn_sort($rows) {
    usort($rows, static function ($a, $b) {
        return strcmp(
            (string)($b['created'] ?? ''),
            (string)($a['created'] ?? '')
        );
    });
    return $rows;
}

if (empty($_SESSION['pn_token'])) {
    $_SESSION['pn_token'] = bin2hex(random_bytes(24));
}

if (
    ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && isset($_POST['pn_action'])
) {
    if (!$pnAdmin) {
        http_response_code(403);
        exit('관리자만 수정할 수 있습니다.');
    }

    if (!hash_equals(
        $_SESSION['pn_token'],
        (string)($_POST['pn_token'] ?? '')
    )) {
        http_response_code(403);
        exit('요청 인증이 만료되었습니다.');
    }

    $fp = @fopen($pnLock, 'c');

    if (!$fp || !flock($fp, LOCK_EX)) {
        http_response_code(503);
        exit('저장소 잠금에 실패했습니다.');
    }

    try {
        $rows = pn_read();
        $action = (string)$_POST['pn_action'];
        $id = (string)($_POST['pn_id'] ?? '');

        if ($action === 'save') {
            $title = trim((string)($_POST['pn_title'] ?? ''));
            $body = trim((string)($_POST['pn_body'] ?? ''));

            if (
                $title === '' || $body === ''
                || mb_strlen($title, 'UTF-8') > 120
                || mb_strlen($body, 'UTF-8') > 15000
            ) {
                throw new RuntimeException(
                    '제목 또는 본문 길이를 확인해 주세요.'
                );
            }

            if ($id === '') {
                $id = bin2hex(random_bytes(12));
                $rows[] = [
                    'id' => $id,
                    'title' => $title,
                    'body' => $body,
                    'created' => date('Y-m-d H:i:s')
                ];
            } else {
                $found = false;

                foreach ($rows as &$row) {
                    if (($row['id'] ?? '') === $id) {
                        $row['title'] = $title;
                        $row['body'] = $body;
                        $row['updated'] = date('Y-m-d H:i:s');
                        $found = true;
                        break;
                    }
                }
                unset($row);

                if (!$found) {
                    throw new RuntimeException(
                        '수정할 글을 찾을 수 없습니다.'
                    );
                }
            }
        } elseif ($action === 'delete') {
            $before = count($rows);

            $rows = array_values(array_filter(
                $rows,
                static function ($row) use ($id) {
                    return ($row['id'] ?? '') !== $id;
                }
            ));

            if (count($rows) === $before) {
                throw new RuntimeException(
                    '삭제할 글을 찾을 수 없습니다.'
                );
            }
        } else {
            throw new RuntimeException('지원하지 않는 요청입니다.');
        }

        $json = json_encode(
            $rows,
            JSON_UNESCAPED_UNICODE
            | JSON_PRETTY_PRINT
            | JSON_INVALID_UTF8_SUBSTITUTE
        );

        if ($json === false) {
            throw new RuntimeException('JSON 저장 오류');
        }

        $temp = $pnFile . '.tmp.' . bin2hex(random_bytes(6));

        if (file_put_contents($temp, $json) === false) {
            throw new RuntimeException('임시 파일 저장 실패');
        }

        chmod($temp, 0660);

        if (!rename($temp, $pnFile)) {
            @unlink($temp);
            throw new RuntimeException('파일 저장 실패');
        }

        flock($fp, LOCK_UN);
        fclose($fp);

        header('Location: /index.php?pn=' . rawurlencode($id), true, 303);
        exit;

    } catch (Throwable $e) {
        $pnError = $e->getMessage();
    }

    flock($fp, LOCK_UN);
    fclose($fp);
}

$pnRows = pn_sort(pn_read());
$pnSelected = null;
$pnId = (string)($_GET['pn'] ?? '');

foreach ($pnRows as $row) {
    if (($row['id'] ?? '') === $pnId) {
        $pnSelected = $row;
        break;
    }
}

$pnEdit = $pnAdmin && (
    ($_GET['pn_edit'] ?? '') === 'new'
    || (isset($_GET['pn_edit']) && $pnSelected !== null)
);


// PATCH_NOTES_V1 - presentation
function pn_render() {
    global $pnRows, $pnSelected, $pnEdit, $pnAdmin, $pnError;

    $token = pn_h($_SESSION['pn_token']);
    ?>
    <section class="pn-panel" id="patch-notes">
      <div class="pn-heading">
        <strong>📢 패치노트</strong>
        <?php if ($pnAdmin): ?>
          <a href="/index.php?pn_edit=new#patch-notes">+ 글쓰기</a>
        <?php endif; ?>
      </div>

      <?php if ($pnError !== ''): ?>
        <p class="pn-error"><?= pn_h($pnError) ?></p>
      <?php endif; ?>

      <?php if ($pnEdit): ?>
        <?php
          $editing = $pnSelected !== null
            && ($_GET['pn_edit'] ?? '') !== 'new';
          $entry = $editing ? $pnSelected : [];
        ?>
        <h3><?= $editing ? '패치노트 수정' : '새 패치노트' ?></h3>
        <form method="post" action="/index.php#patch-notes">
          <input type="hidden" name="pn_action" value="save">
          <input type="hidden" name="pn_token" value="<?= $token ?>">
          <input type="hidden" name="pn_id"
                 value="<?= pn_h($entry['id'] ?? '') ?>">
          <input name="pn_title" maxlength="120" required
                 placeholder="제목"
                 value="<?= pn_h($entry['title'] ?? '') ?>">
          <textarea name="pn_body" rows="10" maxlength="15000"
                    required placeholder="업데이트 내용을 입력하세요"><?= pn_h($entry['body'] ?? '') ?></textarea>
          <button type="submit">저장하기</button>
          <a href="/index.php#patch-notes">취소</a>
        </form>

      <?php elseif ($pnSelected !== null): ?>
        <article class="pn-detail">
          <h3><?= pn_h($pnSelected['title'] ?? '') ?></h3>
          <small><?= pn_h($pnSelected['created'] ?? '') ?></small>
          <div class="pn-body"><?= nl2br(pn_h($pnSelected['body'] ?? '')) ?></div>
          <a href="/index.php#patch-notes">← 목록으로</a>

          <?php if ($pnAdmin): ?>
            <div class="pn-actions">
              <a href="/index.php?pn=<?= urlencode($pnSelected['id']) ?>&amp;pn_edit=1#patch-notes">수정</a>
              <form method="post" action="/index.php#patch-notes"
                    onsubmit="return confirm('이 패치노트를 삭제할까요?')">
                <input type="hidden" name="pn_action" value="delete">
                <input type="hidden" name="pn_token" value="<?= $token ?>">
                <input type="hidden" name="pn_id"
                       value="<?= pn_h($pnSelected['id']) ?>">
                <button type="submit">삭제</button>
              </form>
            </div>
          <?php endif; ?>
        </article>

      <?php else: ?>
        <div class="pn-list">
          <?php if (!$pnRows): ?>
            <p>아직 등록된 업데이트가 없습니다.</p>
          <?php endif; ?>

          <?php foreach ($pnRows as $entry): ?>
            <a class="pn-item"
               href="/index.php?pn=<?= urlencode($entry['id']) ?>#patch-notes">
              <span><?= pn_h($entry['title'] ?? '') ?></span>
              <small><?= pn_h(substr($entry['created'] ?? '', 0, 10)) ?></small>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
    <?php
}
