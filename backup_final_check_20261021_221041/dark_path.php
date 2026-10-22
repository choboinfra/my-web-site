<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');

if (session_status() === PHP_SESSION_NONE) { session_start(); }

$data_file = __DIR__ . '/dark_path_data.txt';
$upload_dir = __DIR__ . '/uploads/';
$upload_url = 'uploads/';
require_once '/var/lib/site-live/dark_path_secrets.php';
require_once '/var/lib/site-live/members_lib.php';

if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0775, true);
}

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function load_entries($file) {
    if (!is_file($file)) {
        return [];
    }

    $json = file_get_contents($file);
    $data = json_decode($json, true);

    return is_array($data) ? $data : [];
}

function save_entries($file, $entries) {
    $json = json_encode(
        array_values($entries),
        JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
    );

    if ($json === false) {
        return false;
    }

    return file_put_contents($file, $json, LOCK_EX) !== false;
}

function redirect_self() {
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

$login_error = '';
$notice = '';

$darkMember = site_current_member();
$darkMemberAllowed = is_array($darkMember)
    && empty($darkMember['suspended'])
    && !empty($darkMember['dark_path_access']);

// 임시 진단: 비밀정보는 기록하지 않음
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && ($_POST['action'] ?? '') === 'write') {
    @file_put_contents(
        '/tmp/dark_path_diag.log',
        date('c')
        . ' method=' . ($_SERVER['REQUEST_METHOD'] ?? '')
        . ' action=' . ($_POST['action'] ?? '')
        . ' member=' . (is_array($darkMember) ? 'yes' : 'no')
        . ' allowed=' . ($darkMemberAllowed ? 'yes' : 'no')
        . ' auth=' . (
            ($_SESSION['dark_path_auth'] ?? null) === 'member'
                ? 'member'
                : (($_SESSION['dark_path_auth'] ?? null) === true ? 'key' : 'none')
        )
        . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

if ($darkMemberAllowed && empty($_SESSION['dark_path_member_skip'])) {
    $_SESSION['dark_path_auth'] = 'member';
} elseif (($_SESSION['dark_path_auth'] ?? null) === 'member'
    && !$darkMemberAllowed) {
    unset($_SESSION['dark_path_auth']);
}


if (isset($_GET['exit_secret'])) {
    if (($_SESSION['dark_path_auth'] ?? null) === 'member'
        && $darkMemberAllowed) {
        $_SESSION['dark_path_member_skip'] = true;
    }
    unset($_SESSION['dark_path_auth']);
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'member_login') {
    if ($darkMemberAllowed) {
        session_regenerate_id(true);
        unset($_SESSION['dark_path_member_skip']);
        $_SESSION['dark_path_auth'] = 'member';
        redirect_self();
    } else {
        $login_error = '로그인 및 어둠의 경로 권한이 필요합니다.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'login') {

    $input_key = (string)($_POST['dev_login_answer'] ?? '');

    if (hash_equals($login_key, $input_key)) {
        session_regenerate_id(true);
        unset($_SESSION['dark_path_member_skip']);
        $_SESSION['dark_path_auth'] = true;
        redirect_self();
    } else {
        $login_error = '인증 실패: 접근 권한이 없습니다.';
    }
}

if (empty($_SESSION['dark_path_auth'])):
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>어둠의 경로 - 접근 제한</title>
<style>
body{font-family:sans-serif;background:#0a0a0a;color:#888;display:flex;justify-content:center;align-items:center;min-height:100vh;margin:0}
.login-box{background:#141414;padding:30px;border:1px solid #222;border-radius:8px;width:300px;max-width:calc(100% - 60px);text-align:center}
h3{color:#d32f2f;margin-top:0}
input{width:100%;padding:10px;box-sizing:border-box;background:#000;border:1px solid #333;color:#fff;border-radius:4px;margin-bottom:15px}
button{background:#d32f2f;color:#fff;font-weight:bold;border:none;padding:10px;width:100%;cursor:pointer;border-radius:4px}
.error{color:#ff5252;font-size:.85em;margin-bottom:10px}
.back-link{display:block;margin-top:15px;color:#555;font-size:.8em;text-decoration:none}
</style>
</head>
<body>
<div class="login-box">
<h3>🔒 RESTRICTED AREA</h3>
<?php if ($login_error !== ''): ?>
<div class="error"><?= h($login_error) ?></div>
<?php endif; ?>
<form method="POST">
<input type="hidden" name="action" value="login">
<input type="password" name="dev_login_answer" placeholder="ACCESS KEY" required autofocus>
<button type="submit">ENTER</button>
</form>
<?php if ($darkMemberAllowed): ?>
<form method="POST" style="margin-top:12px">
<input type="hidden" name="action" value="member_login">
<button type="submit">😈 회원 권한으로 입장</button>
</form>
<?php endif; ?>
<a href="index.php" class="back-link">← 돌아가기</a>
</div>
</body>
</html>
<?php
exit;
endif;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'write') {
        $name = trim((string)($_POST['name'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $content = trim((string)($_POST['content'] ?? ''));
        if ($darkMemberAllowed) {
            $name = trim((string)($darkMember['nickname'] ?? $darkMember['username'] ?? $name));
        }

        $file_url = '';
        $file_name = '';

        if ($name === '' || $password === '') {
            $notice = '닉네임과 삭제용 비밀번호를 입력해 주세요.';
        } elseif (
            $content === ''
            && (!isset($_FILES['upload_file'])
                || $_FILES['upload_file']['error'] === UPLOAD_ERR_NO_FILE)
        ) {
            $notice = '내용을 입력하거나 파일을 첨부해 주세요.';
        } else {
            $upload_error = '';

            if (isset($_FILES['upload_file'])
                && $_FILES['upload_file']['error'] !== UPLOAD_ERR_NO_FILE) {

                $upload = $_FILES['upload_file'];

                if ($upload['error'] !== UPLOAD_ERR_OK) {
                    $upload_error = match ((int)$upload['error']) {
                        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
                            '파일이 서버의 업로드 용량 제한을 초과했습니다.',
                        UPLOAD_ERR_PARTIAL =>
                            '파일이 일부만 전송되었습니다.',
                        UPLOAD_ERR_NO_TMP_DIR =>
                            '서버의 임시 폴더가 없습니다.',
                        UPLOAD_ERR_CANT_WRITE =>
                            '서버에 파일을 쓸 수 없습니다.',
                        UPLOAD_ERR_EXTENSION =>
                            '서버 설정에 의해 업로드가 중단되었습니다.',
                        default => '파일 업로드 오류가 발생했습니다.'
                    };
                } elseif (!is_uploaded_file($upload['tmp_name'])) {
                    $upload_error = '업로드 파일을 확인할 수 없습니다.';
                } elseif ((int)($upload['size'] ?? 0) > 50 * 1024 * 1024) {
                    $upload_error = '파일은 장당 50MB 이하만 업로드할 수 있습니다.';
                } else {
                    $original_name = basename((string)$upload['name']);
                    $extension = strtolower(
                        pathinfo($original_name, PATHINFO_EXTENSION)
                    );

                    // 실행 가능한 파일 확장자 차단
                    $blocked = [
                        'php', 'php3', 'php4', 'php5', 'php7', 'php8',
                        'phtml', 'phar', 'cgi', 'pl', 'py', 'sh',
                        'bash', 'exe', 'com', 'bat', 'cmd', 'msi', 'htaccess', 'ini', 'shtml', 'asp', 'aspx', 'jsp', 'jspx', 'jar', 'dll', 'so'
                    ];

                    if (in_array($extension, $blocked, true)) {
                        $upload_error = '보안상 허용되지 않는 파일 형식입니다.';
                    } else {
                        try {
                            $random_name = bin2hex(random_bytes(16));
                        } catch (Throwable $e) {
                            $random_name = uniqid('', true);
                            $random_name = preg_replace('/[^a-zA-Z0-9]/', '', $random_name);
                        }

                        $safe_name = $random_name
                            . ($extension !== '' ? '.' . $extension : '');

                        $destination = $upload_dir . $safe_name;

                        if (move_uploaded_file($upload['tmp_name'], $destination)) {
                            chmod($destination, 0644);
                            $file_url = $upload_url . $safe_name;
                            $file_name = $original_name;
                        } else {
                            $upload_error = '파일 저장에 실패했습니다. 폴더 권한을 확인해 주세요.';
                        }
                    }
                }
            }

            if ($upload_error !== '') {
                $notice = $upload_error;
            } else {
                $entries = load_entries($data_file);

                array_unshift($entries, [
                    'id' => bin2hex(random_bytes(12)),
                    'name' => $name,
                    'is_dark_member' => $darkMemberAllowed,
                    'member_id' => $darkMemberAllowed ? (string)($darkMember['id'] ?? '') : '',
                    'password' => $password,
                    'content' => $content,
                    'file_url' => $file_url,
                    'file_name' => $file_name,
                    'date' => date('Y-m-d H:i:s')
                ]);

                if (save_entries($data_file, $entries)) {
                    redirect_self();
                } else {
                    $notice = '게시물 저장에 실패했습니다. 데이터 파일 권한을 확인해 주세요.';
                    error_log('게시물 JSON 저장 실패: ' . $data_file);
                }
            }
        }
    }

    if ($action === 'delete') {
        $target_id = (string)($_POST['target_id'] ?? '');
        $delete_password = (string)($_POST['password'] ?? '');

        $entries = load_entries($data_file);
        $deleted = false;

        foreach ($entries as $index => $entry) {
            if (
                isset($entry['id'])
                && hash_equals((string)$entry['id'], $target_id)
                && (
                    hash_equals((string)($entry['password'] ?? ''), $delete_password)
                    || hash_equals($master_password, $delete_password)
                )
            ) {
                unset($entries[$index]);
                $deleted = true;

                $url = (string)($entry['file_url'] ?? '');

                // uploads 폴더 안의 파일만 삭제
                $prefix = 'uploads/';
                if (str_starts_with($url, $prefix)) {
                    $basename = basename($url);
                    $path = $upload_dir . $basename;

                    if (is_file($path)) {
                        @unlink($path);
                    }
                }

                break;
            }
        }

        if ($deleted && save_entries($data_file, $entries)) {
            redirect_self();
        }

        $notice = $deleted
            ? '게시물 삭제 처리 중 저장 오류가 발생했습니다.'
            : '비밀번호가 틀렸거나 게시물이 없습니다.';
    }
}

$entries = load_entries($data_file);
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>어둠의 경로</title>
<style>
*{box-sizing:border-box}
body{font-family:sans-serif;background:#0f0f0f;color:#d0d0d0;margin:0;padding:20px}
.container{max-width:750px;margin:0 auto;background:#161616;padding:20px;border:1px solid #282828;border-radius:6px}
h2{border-bottom:2px solid #d32f2f;padding-bottom:10px;color:#fff;display:flex;justify-content:space-between;align-items:center;gap:10px}
a{color:#90caf9;text-decoration:none}a:hover{text-decoration:underline}
.write-box{background:#1e1e1e;padding:15px;border-radius:4px;border:1px solid #333;margin-bottom:20px}
.form-group{margin-bottom:10px}
label{display:block;font-size:.85em;margin-bottom:4px;color:#888}
input,textarea{width:100%;padding:8px;background:#0a0a0a;border:1px solid #333;color:#fff;border-radius:3px}
button.submit-btn{background:#d32f2f;color:#fff;font-weight:bold;border:none;padding:10px 20px;cursor:pointer;border-radius:3px;width:100%}
.post{background:#1a1a1a;border:1px solid #2a2a2a;padding:15px;margin-bottom:15px;border-radius:4px;overflow:hidden;word-break:break-word}
.post-header{display:flex;justify-content:space-between;gap:10px;font-weight:bold;color:#ffb74d;border-bottom:1px solid #262626;padding-bottom:8px;margin-bottom:10px}
.post-date{font-size:.8em;color:#666;font-weight:normal}
.post-content{white-space:pre-wrap;margin-bottom:15px;line-height:1.4;word-break:break-word}
.attachment-box{background:#111;padding:12px;border-radius:4px;border:1px dashed #333;margin-top:10px;overflow:hidden}
.attachment-info{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px}
.download-btn{background:#1976d2;color:#fff;padding:6px 12px;border-radius:4px;font-size:.85em;font-weight:bold;display:inline-block}
.attachment-box img,.attachment-box video,.attachment-box audio{max-width:100%;height:auto;margin-top:10px;border-radius:4px}
.attachment-box img{max-height:400px;object-fit:contain;display:block;margin-left:auto;margin-right:auto}
.attachment-box video,.attachment-box audio{width:100%}
.del-form{display:flex;gap:4px;align-items:center;margin-top:6px}
.del-form input{width:100px;padding:4px;font-size:.75em}
.del-form button{background:#444;color:#fff;border:none;padding:4px 6px;font-size:.75em;cursor:pointer;border-radius:2px}
.notice{background:#3b1717;border:1px solid #733;color:#ffcdd2;padding:10px;margin-bottom:15px;border-radius:4px}
@media(max-width:520px){body{padding:8px}.container{padding:12px}.post-header{flex-direction:column}.del-form input{width:100px}}
.container{width:100%;max-width:900px}.post-content{overflow-wrap:anywhere}.attachment-box{border-color:#3a3a3a}input:focus,textarea:focus{outline:1px solid #d32f2f;border-color:#d32f2f}@media(max-width:600px){body{padding:8px}.container{padding:12px}.write-box{padding:10px}.form-group[style*="display:flex"]{display:block!important}.form-group[style*="display:flex"]>div{margin-bottom:10px}.post-header{flex-direction:column;gap:8px}.del-form{width:100%;flex-wrap:wrap}.del-form input{flex:1;min-width:0;width:auto}.attachment-info{align-items:flex-start;flex-direction:column}}
</style>
</head>
<body>
<div class="container">
<h2>
<span>어둠의 경로 (Secret Archive)</span>
<a href="?exit_secret=1" style="font-size:.6em;color:#ff5252;white-space:nowrap">[잠그고 나가기]</a>
</h2>

<p style="font-size:.85em;color:#666"><a href="?exit_secret=1">← 메인으로 돌아가기</a></p>

<?php if ($notice !== ''): ?>
<div class="notice"><?= h($notice) ?></div>
<?php endif; ?>

<div class="write-box">
<form method="POST" enctype="multipart/form-data">
<input type="hidden" name="action" value="write">

<div style="display:flex;gap:10px" class="form-group">
<div style="flex:1">
<label>닉네임</label>
<input type="text" name="name" required placeholder="익명" maxlength="100">
</div>
<div style="flex:1">
<label>삭제용 비밀번호</label>
<input type="password" name="password" required maxlength="200">
</div>
</div>

<div class="form-group">
<label>내용</label>
<textarea name="content" rows="3" placeholder="공유할 내용을 적으세요"></textarea>
</div>

<div class="form-group">
<label>파일 / 영상 / 사진 첨부</label>
<input type="file" name="upload_file" style="background:none;border:none;padding:0">
</div>

<button type="submit" class="submit-btn">업로드 등록</button>
</form>
</div>

<div class="post-list">
<?php if (empty($entries)): ?>
<p style="text-align:center;color:#555;padding:30px">기록된 자료가 없습니다.</p>
<?php else: ?>
<?php foreach ($entries as $entry): ?>
<div class="post">
<div class="post-header">
<span><?= h($entry['name'] ?? '') ?><?= !empty($entry['is_dark_member']) ? ' 😈' : '' ?></span>
<div>
<span class="post-date"><?= h($entry['date'] ?? '') ?></span>

<form method="POST" class="del-form" onsubmit="return confirm('정말 삭제하시겠습니까?');">
<input type="hidden" name="action" value="delete">
<input type="hidden" name="target_id" value="<?= h($entry['id'] ?? '') ?>">
<input type="password" name="password" placeholder="비번/마스터" required>
<button type="submit">삭제</button>
</form>
</div>
</div>

<?php if (!empty($entry['content'])): ?>
<div class="post-content"><?= nl2br(h($entry['content'])) ?></div>
<?php endif; ?>

<?php
$file_url = (string)($entry['file_url'] ?? '');
$file_name = (string)($entry['file_name'] ?? '');

$is_local_upload = str_starts_with($file_url, 'uploads/')
    && basename($file_url) === substr($file_url, strlen('uploads/'));

if ($file_url !== '' && $is_local_upload && is_file($upload_dir . basename($file_url))):
    $ext = strtolower(pathinfo($file_url, PATHINFO_EXTENSION));
    $safe_url = $upload_url . rawurlencode(basename($file_url));
?>
<div class="attachment-box">
<div class="attachment-info">
<span style="font-size:.9em;color:#ccc;word-break:break-all">
첨부파일: <b><?= h($file_name) ?></b>
</span>
<a href="<?= h($safe_url) ?>" class="download-btn" download>다운로드</a>
</div>

<?php if (in_array($ext, ['jpg','jpeg','png','gif','webp'], true)): ?>
<img src="<?= h($safe_url) ?>" alt="uploaded image">
<?php elseif (in_array($ext, ['mp4','webm'], true)): ?>
<video controls preload="metadata"><source src="<?= h($safe_url) ?>"></video>
<?php elseif (in_array($ext, ['mp3','wav','ogg'], true)): ?>
<audio controls preload="metadata"><source src="<?= h($safe_url) ?>"></audio>
<?php endif; ?>
</div>
<?php endif; ?>

</div>
<?php endforeach; ?>
<?php endif; ?>
</div>
</div>
</body>
</html>