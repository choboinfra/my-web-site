<?php
// PATCH_NOTES_V1
require_once __DIR__ . '/patch_notes_widget.php';
echo "<!-- Server: VM1 -->";
?>

<?php
require_once(__DIR__ . '/lib/members_lib.php');
$isAdmin = !empty($_SESSION['admin_logged_in']);
$siteMember = site_current_member();
if (!$siteMember && empty($_SESSION['guest_mode']) && !$isAdmin) {
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>입장 선택</title>
<style>
*{box-sizing:border-box}
body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;
padding:22px;background:radial-gradient(circle at top,#203642,#101216 65%);
color:#eee;font-family:system-ui,-apple-system,sans-serif}
.panel{width:100%;max-width:440px;padding:34px 26px;background:#1b2026;
border:1px solid #36414b;border-radius:18px;box-shadow:0 20px 60px #0008;text-align:center}
.mark{font-size:38px;margin-bottom:12px}
h1{font-size:25px;margin:0 0 12px;color:#fff}
.desc{color:#aab5bf;font-size:14px;line-height:1.8;margin:0 0 26px}
a.btn{display:block;padding:15px 14px;margin-top:12px;border-radius:9px;
text-decoration:none;font-weight:700;font-size:15px;transition:.15s}
a.btn:hover{transform:translateY(-1px);filter:brightness(1.1)}
.guest{background:#b9e3ff;color:#172b3a}
.login{background:#2d3944;color:#fff;border:1px solid #52616e}
.signup{background:#172c32;color:#75d8e5;border:1px solid #247887}
.foot{font-size:12px;color:#71808b;margin-top:24px}

/* PATCH_NOTES_V1 */
.pn-layout{display:flex;align-items:flex-start;justify-content:center;gap:24px;
max-width:1100px;margin:0 auto;width:100%}
.pn-layout>.container{margin:0;flex:1;min-width:0}
.pn-layout>.panel{flex:1;min-width:0}
.pn-side{width:340px;flex-shrink:0;min-width:0}
.pn-panel{background:#1e2329;color:#eee;border:1px solid #3b4650;
border-radius:12px;padding:20px;box-shadow:0 6px 22px #0004;text-align:left;
font-family:system-ui,-apple-system,sans-serif}
.pn-heading{display:flex;justify-content:space-between;align-items:center;
gap:8px;padding-bottom:12px;border-bottom:1px solid #46505a}
.pn-heading strong{font-size:19px}
.pn-panel a{color:#80d8ff;text-decoration:none}
.pn-panel a:hover{text-decoration:underline}
.pn-item{display:flex;flex-direction:column;gap:5px;padding:13px 0;
border-bottom:1px solid #353e47;overflow-wrap:anywhere}
.pn-item span{font-weight:600;color:#f0f0f0}
.pn-item small,.pn-detail small{color:#9da9b4;font-size:12px}
.pn-detail h3{overflow-wrap:anywhere}
.pn-body{white-space:normal;overflow-wrap:anywhere;line-height:1.8;
padding:16px 0;border-bottom:1px solid #46505a}
.pn-panel input,.pn-panel textarea{width:100%;box-sizing:border-box;
padding:10px;margin:8px 0;background:#111820;color:#fff;
border:1px solid #52616e;border-radius:6px;font:inherit}
.pn-panel button{background:#176a7d;color:#fff;border:0;
border-radius:6px;padding:10px 14px;cursor:pointer}
.pn-actions{display:flex;align-items:center;gap:16px;margin-top:15px}
.pn-actions form{margin:0}
.pn-error{color:#ff8b8b}
@media(max-width:800px){
.pn-layout{flex-direction:column;align-items:stretch;gap:18px}
.pn-layout>.container,.pn-layout>.panel,.pn-side{width:100%;max-width:100%;
box-sizing:border-box}
}

</style>
</head>
<body>
<div class="pn-layout">
<main class="panel">
<div class="mark">🌐</div>
<h1>조승훈과 함께 춤을</h1>
<p class="desc">방문해 주셔서 감사합니다.<br>입장 방법을 선택해 주세요.</p>
<a class="btn guest" href="/guest.php">👤 게스트로 입장</a>
<a class="btn login" href="/login.php">🔑 회원 로그인</a>
<a class="btn signup" href="/signup.php">💠 회원가입</a>
<div class="foot">입장 후 사이트의 콘텐츠를 이용할 수 있습니다.</div>
</main>
<aside class="pn-side"><?php pn_render(); ?></aside>
</div>
</body>
</html>
<?php
    exit;
}

?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <title>조승훈과 함께 춤을 メインページ</title>
    <style>
        body { font-family: sans-serif; background: #121212; color: #e0e0e0; margin: 0; padding: 40px; }
        .container { max-width: 600px; margin: 0 auto; background: #1e1e1e; padding: 30px; border-radius: 8px; border: 1px solid #333; box-shadow: 0 4px 10px rgba(0,0,0,0.5); }
        h1 { color: #fff; border-bottom: 2px solid #00acc1; padding-bottom: 10px; margin-top: 0; }
        .menu-list { list-style: none; padding: 0; }
        .menu-list li { margin: 15px 0; }
        .menu-list a { display: block; padding: 14px 20px; background: #2d2d2d; color: #fff; text-decoration: none; border-radius: 4px; transition: 0.2s; border: 1px solid #444; font-size: 16px; }
        .menu-list a:hover { background: #00acc1; color: #000; font-weight: bold; }
        .admin-btn { background: #b71c1c !important; border-color: #d32f2f !important; }
        .admin-btn:hover { background: #e53935 !important; color: #fff !important; }

        .admin-status {
            position: fixed;
            top: 14px;
            left: 16px;
            z-index: 10000;
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            background: #173b2b;
            color: #b9f6ca;
            border: 1px solid #2e7d52;
            border-radius: 8px;
            font-size: 14px;
            font-weight: bold;
            box-shadow: 0 3px 12px rgba(0,0,0,.35);
        }
        .admin-status form { margin: 0; }
        .admin-logout {
            display: inline-block;
            padding: 6px 10px;
            background: #b71c1c;
            color: #fff;
            border: 1px solid #ef5350;
            border-radius: 5px;
            text-decoration: none;
            font-size: 12px;
            font-weight: bold;
        }
        .admin-logout:hover { background: #e53935; }
        @media (max-width: 480px) {
            .admin-status {
                top: 8px;
                left: 8px;
                padding: 8px 10px;
                font-size: 12px;
            }
            body { padding: 65px 14px 20px !important; }
        }

    
/* PATCH_NOTES_V1 */
.pn-layout{display:flex;align-items:flex-start;justify-content:center;gap:24px;
max-width:1100px;margin:0 auto;width:100%}
.pn-layout>.container{margin:0;flex:1;min-width:0}
.pn-layout>.panel{flex:1;min-width:0}
.pn-side{width:340px;flex-shrink:0;min-width:0}
.pn-panel{background:#1e2329;color:#eee;border:1px solid #3b4650;
border-radius:12px;padding:20px;box-shadow:0 6px 22px #0004;text-align:left;
font-family:system-ui,-apple-system,sans-serif}
.pn-heading{display:flex;justify-content:space-between;align-items:center;
gap:8px;padding-bottom:12px;border-bottom:1px solid #46505a}
.pn-heading strong{font-size:19px}
.pn-panel a{color:#80d8ff;text-decoration:none}
.pn-panel a:hover{text-decoration:underline}
.pn-item{display:flex;flex-direction:column;gap:5px;padding:13px 0;
border-bottom:1px solid #353e47;overflow-wrap:anywhere}
.pn-item span{font-weight:600;color:#f0f0f0}
.pn-item small,.pn-detail small{color:#9da9b4;font-size:12px}
.pn-detail h3{overflow-wrap:anywhere}
.pn-body{white-space:normal;overflow-wrap:anywhere;line-height:1.8;
padding:16px 0;border-bottom:1px solid #46505a}
.pn-panel input,.pn-panel textarea{width:100%;box-sizing:border-box;
padding:10px;margin:8px 0;background:#111820;color:#fff;
border:1px solid #52616e;border-radius:6px;font:inherit}
.pn-panel button{background:#176a7d;color:#fff;border:0;
border-radius:6px;padding:10px 14px;cursor:pointer}
.pn-actions{display:flex;align-items:center;gap:16px;margin-top:15px}
.pn-actions form{margin:0}
.pn-error{color:#ff8b8b}
@media(max-width:800px){
.pn-layout{flex-direction:column;align-items:stretch;gap:18px}
.pn-layout>.container,.pn-layout>.panel,.pn-side{width:100%;max-width:100%;
box-sizing:border-box}
}

</style>
</head>
<body>

    <?php if ($isAdmin): ?>
    <div class="admin-status">
        <span>🔐 관리자 모드입니다.</span>
        <a class="admin-logout" href="logout.php"
           onclick="return confirm('관리자 모드에서 로그아웃할까요?');">로그아웃</a>
    </div>
    <?php endif; ?>

    <div class="pn-layout">
<div class="container">
        <h1>조승훈과 함께 춤을　メインページ</h1>
        <p style="color: #aaa;">테스트 중 입니다 テスト中です</p>
        
        <ul class="menu-list">
            <?php if ($siteMember): ?>
            <li><a href="member.php">내 계정 · <?=site_e(site_role_label($siteMember['role']))?> <?=site_e($siteMember['nickname'])?></a></li>
            <li><a href="member_logout.php">회원 로그아웃</a></li>
            <?php elseif (!empty($_SESSION['guest_mode'])): ?>
            <li><a href="#" onclick="return false;">👤 게스트 입장 중</a></li>
            <li><a href="guest_logout.php" onclick="return confirm('입장을 종료할까요?');">입장 종료</a></li>
            <?php endif; ?>
            <?php if ($isAdmin): ?>
            <li><a href="site_access_admin.php" class="admin-btn">🔒 페이지별 접근 설정</a></li>
            <li><a href="/file_share_admin.php" class="admin-btn">&#128193; &#54028;&#51068; &#44277;&#50976; &#44288;&#47532;</a></li>
            <li><a href="admin_members.php" class="admin-btn">👑 회원 관리 센터</a></li>
            <?php endif; ?>

            <li><a href="lunch.html">오늘의 점심 메뉴 추천 ランダムメニュー</a></li>
            <li><a href="starwars.html">스타크래프트 턴 게임　ゲーム</a></li>
            <li><a href="guestbook.php">방명록　コメント</a></li>
            <li><a href="chat.php">실시간 채팅 / 접속자 현황판 ライブチャット</a></li>
            <!-- 관리자 인증 페이지로 연결되는 버튼 -->
            <li><a href="admin_login.php" class="admin-btn">🛠️ 관리자 페이지　管理者専用 </a></li>
        <li><a href="board.php" style="display:inline-block;background:#b9e3ff;color:#172b3a;border:1px solid #91cefa;border-radius:8px;padding:9px 16px;font-weight:bold;text-decoration:none;">익명 게시판</a></li>
</ul>
    </div>
<aside class="pn-side"><?php pn_render(); ?></aside>
</div>
<!-- [우측 상단 빨간색 작은 '어둠의 경로' 링크] -->
<style>
    .secret-link { 
        position: fixed; 
        top: 15px; 
        right: 20px; 
        font-size: 11px; 
        color: #b71c1c; /* 은은하고 어두운 다크 레드 */
        text-decoration: none; 
        z-index: 9999; 
        transition: 0.3s; 
    }
    .secret-link:hover { color: #ff5252; /* 마우스 올리면 밝은 빨간색으로 */ }

    /* 입장 종료 버튼 색상 구분 */
    .menu-list a[href="guest_logout.php"] {
        background: #7f1d1d !important;
        color: #fff !important;
        border: 1px solid #ef4444 !important;
        border-radius: 8px;
        padding: 9px 16px;
        font-weight: bold;
        text-decoration: none;
    }
    .menu-list a[href="guest_logout.php"]:hover {
        background: #b91c1c !important;
    }
    .menu-list a[href="member_logout.php"] {
        background: #713f12 !important;
        color: #fff !important;
        border: 1px solid #f59e0b !important;
        border-radius: 8px;
        padding: 9px 16px;
        font-weight: bold;
        text-decoration: none;
    }

</style>

<!-- FILE_SHARE_NAV_V1 -->
<style>
.file-share-shortcut {
    position: fixed;
    top: 39px;
    right: 20px;
    z-index: 9999;
    display: inline-block;
    padding: 5px 9px;
    border: 1px solid #91cefa;
    border-radius: 7px;
    background: #c7eaff;
    color: #24536e;
    font-size: 11px;
    font-weight: 700;
    text-decoration: none;
    box-shadow: 0 2px 8px #0004;
}
.file-share-shortcut:hover {
    background: #e0f4ff;
}
</style>
<a href="/file_share.php" class="file-share-shortcut">&#128193; &#54028;&#51068; &#44277;&#50976;</a>

<a href="dark_path.php" class="secret-link">어둠의 경로</a>

<script>
(function () {
    async function heartbeat() {
        try {
            await fetch('presence.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
                body: new URLSearchParams({
                    action: 'heartbeat',
                    page: location.pathname.split('/').pop() || 'index.html'
                }),
                cache: 'no-store',
                credentials: 'same-origin'
            });
        } catch (e) {}
    }
    heartbeat();
    setInterval(heartbeat, 10000);
})();
</script>

</body>
</html><!-- Server: VM1 -->
<!-- Server: VM1 -->
