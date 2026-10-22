<?php
require_once '/var/lib/site-live/members_lib.php';

if (empty($_SESSION['admin_logged_in'])) {
    http_response_code(403);
    exit('관리자만 접근할 수 있습니다.');
}

$message = '';
$messageType = 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!site_valid_csrf()) {
        $message = '요청이 만료되었습니다. 페이지를 새로고침해 주세요.';
        $messageType = 'error';
    } else {
        $action = (string)($_POST['action'] ?? '');
        $id = (string)($_POST['id'] ?? '');

        try {
            $data = site_member_data();
            $found = false;

            foreach ($data['users'] as $i => $u) {
                if ((string)($u['id'] ?? '') !== $id) {
                    continue;
                }

                $found = true;

                if ($action === 'role') {
                    $role = (string)($_POST['role'] ?? '');
                    if (!in_array($role, ['member', 'operator', 'special_guest'], true)) {
                        throw new RuntimeException('올바르지 않은 등급입니다.');
                    }

                    if (($u['role'] ?? 'member') === 'operator'
                        && $role !== 'operator') {
                        $operators = 0;
                        foreach ($data['users'] as $x) {
                            if (($x['role'] ?? '') === 'operator') $operators++;
                        }
                        if ($operators <= 1) {
                            throw new RuntimeException('마지막 운영자의 등급은 변경할 수 없습니다.');
                        }
                    }

                    $data['users'][$i]['role'] = $role;
                    $message = '회원 등급을 변경했습니다.';

                } elseif ($action === 'approve_nick' || $action === 'reject_nick') {
                    $request = $u['nickname_request'] ?? null;
                    if (!is_array($request) || empty($request['nickname'])) {
                        throw new RuntimeException('대기 중인 닉네임 요청이 없습니다.');
                    }

                    if ($action === 'reject_nick') {
                        $data['users'][$i]['nickname_request'] = null;
                        $message = '닉네임 변경 요청을 거절했습니다.';
                    } else {
                        $newNick = trim((string)$request['nickname']);
                        if ($newNick === '' || site_nickname_taken($newNick, $id)) {
                            throw new RuntimeException('닉네임이 비어 있거나 이미 사용 중입니다.');
                        }
                        $data['users'][$i]['nickname'] = $newNick;
                        $data['users'][$i]['last_nickname_change'] = time();
                        $data['users'][$i]['nickname_request'] = null;
                        $message = '닉네임 변경을 승인했습니다.';
                    }

                } elseif ($action === 'suspend') {
                    $data['users'][$i]['suspended'] = empty($u['suspended']);
                    $message = !empty($data['users'][$i]['suspended'])
                        ? '계정을 정지했습니다.' : '계정 정지를 해제했습니다.';

                } elseif ($action === 'reset_password') {
                    $password = (string)($_POST['new_password'] ?? '');
                    if (strlen($password) < 10 || strlen($password) > 200) {
                        throw new RuntimeException('새 비밀번호는 10~200바이트로 입력해 주세요.');
                    }
                    $data['users'][$i]['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
                    $message = '비밀번호를 새로 설정했습니다. 새 비밀번호는 다시 표시되지 않습니다.';

                } elseif ($action === 'delete') {
                    if (($_POST['confirm_delete'] ?? '') !== 'DELETE') {
                        throw new RuntimeException('삭제 확인 값이 올바르지 않습니다.');
                    }

                    if (($u['role'] ?? '') === 'operator') {
                        $operators = 0;
                        foreach ($data['users'] as $x) {
                            if (($x['role'] ?? '') === 'operator') $operators++;
                        }
                        if ($operators <= 1) {
                            throw new RuntimeException('마지막 운영자 계정은 삭제할 수 없습니다.');
                        }
                    }

                    array_splice($data['users'], $i, 1);
                    $message = '계정을 삭제했습니다. 기존 게시글과 댓글은 보존됩니다.';
                } else {
                    throw new RuntimeException('알 수 없는 요청입니다.');
                }

                break;
            }

            if (!$found) {
                throw new RuntimeException('해당 회원을 찾을 수 없습니다.');
            }

            site_save_members($data);

        } catch (Throwable $e) {
            $message = $e->getMessage();
            $messageType = 'error';
        }
    }
}

$users = site_member_data()['users'];
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>관리자 회원 관리</title>
<style>
*{box-sizing:border-box}
body{margin:0;padding:20px;background:#121212;color:#eee;font-family:system-ui,sans-serif}
main{max-width:1050px;margin:auto}
.panel{background:#1e1e1e;border:1px solid #333;border-radius:10px;padding:16px;margin:12px 0;overflow-wrap:anywhere}
.user{border-top:1px solid #383838;padding:18px 0}
.muted{color:#aaa;font-size:13px}
input,select{max-width:100%;padding:9px;background:#111;color:#eee;border:1px solid #555;border-radius:5px}
button,.btn{display:inline-block;padding:9px 12px;background:#b9e3ff;color:#172b3a;border:0;border-radius:5px;font-weight:bold;text-decoration:none;cursor:pointer;margin:3px}
button:hover,.btn:hover{filter:brightness(1.1)}
.danger{background:#b42332;color:white}
.reset{background:#7653b5;color:white}
.member{color:#60a5fa}.special_guest{color:#facc15}.operator{color:#60a5fa}
.msg{padding:10px;border-radius:6px;background:#193524;color:#a5f3b7}
.msg.error{background:#451d22;color:#ffb4b4}
.row{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:8px 0}
form{margin:8px 0}
input[type=password]{min-width:220px}
hr{border:0;border-top:1px solid #383838;margin:15px 0}
@media(max-width:600px){body{padding:12px}.panel{padding:12px}}
</style>
</head>
<body>
<main>
<h1>👑 회원 관리 센터</h1>
<p class="muted">관리자 전용 · 비밀번호 원문은 조회할 수 없으며 새 비밀번호 설정만 가능합니다.</p>
<?php if ($message !== ''): ?>
<p class="msg <?= $messageType === 'error' ? 'error' : '' ?>"><?=site_e($message)?></p>
<?php endif; ?>
<p><a class="btn" href="index.php">메인으로</a> <a class="btn" href="admin_members.php">새로고침</a></p>

<section class="panel">
<h2>회원 목록 (<?=count($users)?>)</h2>
<?php if (!$users): ?><p>가입한 회원이 없습니다.</p><?php endif; ?>

<?php foreach ($users as $u): ?>
<div class="user">
<div>
<b class="<?=site_e($u['role'] ?? 'member')?>"><?=site_e(site_role_label($u['role'] ?? 'member'))?> <?=site_e($u['nickname'] ?? '')?></b>
<span class="muted"> · 아이디 <?=site_e($u['username'] ?? '')?> · 가입 <?=site_e($u['created_at'] ?? '')?></span>
<?php if (!empty($u['suspended'])): ?><b style="color:#ff7777"> · 정지됨</b><?php endif; ?>
</div>

<form method="post" class="row">
<input type="hidden" name="csrf" value="<?=site_e(site_csrf())?>">
<input type="hidden" name="id" value="<?=site_e($u['id'] ?? '')?>">
<input type="hidden" name="action" value="role">
<select name="role">
<option value="member" <?=($u['role'] ?? '') === 'member' ? 'selected' : ''?>>💠 일반 회원</option>
<option value="special_guest" <?=($u['role'] ?? '') === 'special_guest' ? 'selected' : ''?>>🍀 특별 게스트</option>
<option value="operator" <?=($u['role'] ?? '') === 'operator' ? 'selected' : ''?>>🔷 운영자</option>
</select>
<button type="submit">등급 변경</button>
</form>

<?php if (!empty($u['nickname_request']['nickname'])): ?>
<p>닉네임 변경 요청: <b><?=site_e($u['nickname'] ?? '')?> → <?=site_e($u['nickname_request']['nickname'])?></b>
<span class="muted"><?=site_e($u['nickname_request']['requested_at'] ?? '')?></span></p>
<div class="row">
<form method="post">
<input type="hidden" name="csrf" value="<?=site_e(site_csrf())?>">
<input type="hidden" name="id" value="<?=site_e($u['id'] ?? '')?>">
<input type="hidden" name="action" value="approve_nick">
<button type="submit">닉네임 승인</button>
</form>
<form method="post">
<input type="hidden" name="csrf" value="<?=site_e(site_csrf())?>">
<input type="hidden" name="id" value="<?=site_e($u['id'] ?? '')?>">
<input type="hidden" name="action" value="reject_nick">
<button class="danger" type="submit">닉네임 거절</button>
</form>
</div>
<?php endif; ?>

<form method="post" class="row">
<input type="hidden" name="csrf" value="<?=site_e(site_csrf())?>">
<input type="hidden" name="id" value="<?=site_e($u['id'] ?? '')?>">
<input type="hidden" name="action" value="reset_password">
<input type="password" name="new_password" minlength="10" maxlength="200" placeholder="새 비밀번호 (10자 이상)" required autocomplete="new-password">
<button class="reset" type="submit" onclick="return confirm('이 회원의 비밀번호를 변경할까요?');">비밀번호 재설정</button>
</form>

<form method="post" class="row">
<input type="hidden" name="csrf" value="<?=site_e(site_csrf())?>">
<input type="hidden" name="id" value="<?=site_e($u['id'] ?? '')?>">
<input type="hidden" name="action" value="suspend">
<button class="danger" type="submit" onclick="return confirm('이 계정의 정지 상태를 변경할까요?');"><?=!empty($u['suspended']) ? '계정 정지 해제' : '계정 정지'?></button>
</form>

<hr>
<form method="post" class="row" onsubmit="return confirm('정말 계정을 삭제할까요? 기존 게시글과 댓글은 남지만 회원 계정은 복구하기 어렵습니다.');">
<input type="hidden" name="csrf" value="<?=site_e(site_csrf())?>">
<input type="hidden" name="id" value="<?=site_e($u['id'] ?? '')?>">
<input type="hidden" name="action" value="delete">
<input type="text" name="confirm_delete" placeholder="삭제하려면 DELETE 입력" required>
<button class="danger" type="submit">계정 삭제</button>
</form>
</div>
<?php endforeach; ?>
</section>
</main>
</body>
</html>
