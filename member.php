<?php
require_once __DIR__ . '/lib/members_lib.php';
$member = site_current_member();
if (!$member) { header('Location: login.php'); exit; }
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!site_valid_csrf()) {
        $message = '요청이 만료되었습니다. 새로고침해 주세요.';
    } elseif (($_POST['action'] ?? '') === 'nickname') {
        $newNick = trim((string)($_POST['nickname'] ?? ''));
        if (mb_strlen($newNick, 'UTF-8') < 2 || mb_strlen($newNick, 'UTF-8') > 20 ||
            preg_match('/[\x00-\x1F\x7F]/', $newNick)) {
            $message = '닉네임은 2~20자로 입력해 주세요.';
        } elseif (site_nickname_taken($newNick, $member['id'])) {
            $message = '이미 사용 중인 닉네임입니다.';
        } elseif (site_nick_key($newNick) === site_nick_key($member['nickname'])) {
            $message = '현재 닉네임과 같습니다.';
        } else {
            $data = site_member_data();
            foreach ($data['users'] as &$u) {
                if (($u['id'] ?? '') !== $member['id']) continue;
                $free = in_array($u['role'] ?? 'member', ['operator','angel','special_guest'], true)
                    || empty($u['last_nickname_change'])
                    || (time() - (int)$u['last_nickname_change'] >= 7 * 86400);
                if ($free) {
                    $u['nickname'] = $newNick;
                    $u['last_nickname_change'] = time();
                    $u['nickname_request'] = null;
                    $message = '닉네임이 변경되었습니다.';
                } else {
                    $u['nickname_request'] = [
                        'nickname' => $newNick,
                        'requested_at' => date('Y-m-d H:i:s')
                    ];
                    $message = '무료 변경 기간이 지나지 않아 관리자 승인 요청을 보냈습니다.';
                }
                break;
            }
            unset($u);
            site_save_members($data);
            $member = site_current_member();
            $member = site_member_by_id($_SESSION['site_member_id']);
        }
    }
}
$remaining = max(0, 7 * 86400 - (time() - (int)($member['last_nickname_change'] ?? 0)));
$freeNow = empty($member['last_nickname_change']) || $remaining === 0
    || in_array($member['role'] ?? '', ['operator','angel','special_guest'], true);
?>
<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>내 계정</title>
<style>body{margin:0;padding:24px;background:#121212;color:#eee;font-family:sans-serif}.box{max-width:650px;margin:30px auto;background:#1e1e1e;border:1px solid #333;border-radius:10px;padding:24px}input{box-sizing:border-box;width:100%;padding:12px;margin:7px 0 15px;background:#111;color:#fff;border:1px solid #444;border-radius:5px}button,.btn{display:inline-block;padding:10px 14px;background:#b9e3ff;color:#172b3a;border:0;border-radius:5px;font-weight:bold;text-decoration:none;cursor:pointer}.muted{color:#aaa}.message{color:#a5f3b7}.nick{font-weight:bold}.member{color:#60a5fa}.operator{color:#60a5fa}.special_guest{color:#facc15}.angel{color:#fff}</style></head><body><div class="box">
<h1>내 계정</h1>
<p>아이디: <b><?=site_e($member['username'])?></b></p>
<p>닉네임: <span class="nick <?=site_e($member['role'])?>"><?=site_e(site_role_label($member['role']))?> <?=site_e($member['nickname'])?></span></p>
<p>가입일: <?=site_e($member['created_at'])?></p>
<p>등급: <?=site_e(site_role_label($member['role']))?></p>
<?php if ($message !== ''): ?><p class="message"><?=site_e($message)?></p><?php endif; ?>
<h2>닉네임 변경</h2>
<p class="muted"><?php if($freeNow):?>무료 변경 가능<?php else:?>다음 무료 변경까지 약 <?=ceil($remaining/86400)?>일 · 지금 요청하면 관리자 승인이 필요합니다.<?php endif;?></p>
<?php if (!empty($member['nickname_request'])): ?>
<p>승인 대기 중: <b><?=site_e($member['nickname_request']['nickname'] ?? '')?></b></p>
<?php endif;?>
<form method="post"><input type="hidden" name="csrf" value="<?=site_e(site_csrf())?>"><input type="hidden" name="action" value="nickname">
<label>새 닉네임 (2~20자)</label><input name="nickname" required maxlength="20">
<button type="submit">변경 또는 승인 요청</button></form>
<p><a class="btn" href="board.php">게시판</a> <a class="btn" href="member_logout.php">로그아웃</a> <a class="btn" href="index.php">메인으로</a></p>
</div></body></html>
