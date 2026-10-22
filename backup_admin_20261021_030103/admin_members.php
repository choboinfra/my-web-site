<?php
require_once '/var/lib/site-live/members_lib.php';
if (empty($_SESSION['admin_logged_in'])) {
    http_response_code(403);
    exit('관리자만 접근할 수 있습니다.');
}
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!site_valid_csrf()) {
        $message = '요청이 만료되었습니다. 새로고침해 주세요.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        $id = (string)($_POST['id'] ?? '');
        $data = site_member_data();
        foreach ($data['users'] as &$u) {
            if (($u['id'] ?? '') !== $id) continue;
            if ($action === 'role') {
                $role = (string)($_POST['role'] ?? '');
                if (!in_array($role, ['member','operator','special_guest'], true)) {
                    $message = '올바르지 않은 등급입니다.';
                } else {
                    $u['role'] = $role;
                    $message = '회원 등급을 변경했습니다.';
                }
            } elseif ($action === 'approve_nick' || $action === 'reject_nick') {
                $request = $u['nickname_request'] ?? null;
                if (!is_array($request) || empty($request['nickname'])) {
                    $message = '대기 중인 닉네임 요청이 없습니다.';
                } elseif ($action === 'reject_nick') {
                    $u['nickname_request'] = null;
                    $message = '닉네임 변경 요청을 거절했습니다.';
                } else {
                    $newNick = trim((string)$request['nickname']);
                    if (site_nickname_taken($newNick, $id)) {
                        $message = '해당 닉네임은 이미 사용 중입니다. 요청을 거절하거나 회원에게 다른 이름을 요청하세요.';
                    } else {
                        $u['nickname'] = $newNick;
                        $u['last_nickname_change'] = time();
                        $u['nickname_request'] = null;
                        $message = '닉네임 변경을 승인했습니다.';
                    }
                }
            } elseif ($action === 'suspend') {
                $u['suspended'] = !empty($u['suspended']) ? false : true;
                $message = !empty($u['suspended']) ? '계정을 정지했습니다.' : '계정 정지를 해제했습니다.';
            }
            break;
        }
        unset($u);
        site_save_members($data);
    }
}
$users = site_member_data()['users'];
?>
<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>관리자 회원 관리</title>
<style>
body{margin:0;padding:20px;background:#121212;color:#eee;font-family:sans-serif}main{max-width:1050px;margin:auto}.panel{background:#1e1e1e;border:1px solid #333;border-radius:9px;padding:16px;margin:12px 0;overflow-wrap:anywhere}.user{border-top:1px solid #333;padding:14px 0}.muted{color:#aaa;font-size:13px}input,select{padding:9px;background:#111;color:#eee;border:1px solid #555;border-radius:5px}button,.btn{display:inline-block;padding:9px 12px;background:#b9e3ff;color:#172b3a;border:0;border-radius:5px;font-weight:bold;text-decoration:none;cursor:pointer;margin:3px}.danger{background:#a92323;color:white}.member{color:#60a5fa}.special_guest{color:#facc15}.operator{color:#60a5fa}.msg{color:#a5f3b7}.row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
</style></head><body><main>
<h1>👑 회원 관리 센터</h1><p class="muted">관리자 전용 페이지입니다. 비밀번호 및 해시값은 표시하지 않습니다.</p>
<?php if($message!==''):?><p class="msg"><?=site_e($message)?></p><?php endif;?>
<p><a class="btn" href="index.php">메인으로</a> <a class="btn" href="admin_members.php">새로고침</a></p>
<section class="panel"><h2>회원 목록 (<?=count($users)?>)</h2>
<?php if(!$users):?><p>가입한 회원이 없습니다.</p><?php endif;?>
<?php foreach($users as $u):?>
<div class="user">
<div><b class="<?=site_e($u['role'] ?? 'member')?>"><?=site_e(site_role_label($u['role'] ?? 'member'))?> <?=site_e($u['nickname'] ?? '')?></b>
<span class="muted"> · 아이디 <?=site_e($u['username'] ?? '')?> · 가입 <?=site_e($u['created_at'] ?? '')?></span>
<?php if(!empty($u['suspended'])):?><b style="color:#ff7777"> · 정지됨</b><?php endif;?></div>
<form method="post" class="row"><input type="hidden" name="csrf" value="<?=site_e(site_csrf())?>"><input type="hidden" name="id" value="<?=site_e($u['id'])?>"><input type="hidden" name="action" value="role">
<select name="role">
<option value="member" <?=($u['role']??'')==='member'?'selected':''?>>💠 일반 회원</option>
<option value="special_guest" <?=($u['role']??'')==='special_guest'?'selected':''?>>🍀 특별 게스트</option>
<option value="operator" <?=($u['role']??'')==='operator'?'selected':''?>>🔷 운영자</option>
</select><button type="submit">등급 변경</button></form>
<?php if(!empty($u['nickname_request']['nickname'])):?>
<p>닉네임 변경 요청: <b><?=site_e($u['nickname'] ?? '')?> → <?=site_e($u['nickname_request']['nickname'])?></b>
<span class="muted"><?=site_e($u['nickname_request']['requested_at'] ?? '')?></span></p>
<div class="row">
<form method="post"><input type="hidden" name="csrf" value="<?=site_e(site_csrf())?>"><input type="hidden" name="id" value="<?=site_e($u['id'])?>"><input type="hidden" name="action" value="approve_nick"><button type="submit">승인</button></form>
<form method="post"><input type="hidden" name="csrf" value="<?=site_e(site_csrf())?>"><input type="hidden" name="id" value="<?=site_e($u['id'])?>"><input type="hidden" name="action" value="reject_nick"><button class="danger" type="submit">거절</button></form>
</div><?php endif;?>
<form method="post" class="row"><input type="hidden" name="csrf" value="<?=site_e(site_csrf())?>"><input type="hidden" name="id" value="<?=site_e($u['id'])?>"><input type="hidden" name="action" value="suspend"><button class="danger" type="submit"><?=!empty($u['suspended'])?'정지 해제':'계정 정지/해제'?></button></form>
</div>
<?php endforeach;?>
</section></main></body></html>
