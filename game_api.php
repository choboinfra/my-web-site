<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/members_lib.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

function game_reply(array $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function game_error(string $message, int $status = 400): void {
    game_reply(['ok'=>false, 'error'=>$message, 'csrf'=>site_csrf()], $status);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && (string)($_GET['bootstrap'] ?? '') === '1') {
    $member = site_current_member();
    if (!$member) game_error('게임은 로그인 회원만 이용할 수 있습니다. 로그인 후 다시 시도해 주세요.', 401);
    game_reply(['ok'=>true,'csrf'=>site_csrf()]);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') game_error('POST 요청만 허용됩니다.', 405);
$member = site_current_member();
if (!$member) game_error('게임은 로그인 회원만 이용할 수 있습니다. 로그인 후 다시 시도해 주세요.', 401);
if (!site_valid_csrf()) game_error('요청 토큰이 만료되었습니다. 페이지를 새로고침해 주세요.', 403);

const GAME_STATE_FILE = '/var/lib/site-live/game_state.json';
const GAME_LOCK_FILE = '/var/lib/site-live/game_state.lock';
const GAME_COUNTRIES = ['korea'=>'한국','japan'=>'일본','china'=>'중국','mongolia'=>'몽골'];
const GAME_FLAGS = ['korea'=>'🇰🇷','japan'=>'🇯🇵','china'=>'🇨🇳','mongolia'=>'🇲🇳'];
function game_level(int $xp): array {
    $level = 1; $remaining = max(0, $xp);
    while ($level < 100) {
        $need = 100 + (($level - 1) * 25);
        if ($remaining < $need) break;
        $remaining -= $need; $level++;
    }
    return ['level'=>$level, 'level_xp'=>$remaining, 'next_level_xp'=>100+(($level-1)*25)];
}
function game_read_state(): array {
    if (!is_file(GAME_STATE_FILE)) return ['profiles'=>[], 'rooms'=>[]];
    $raw = @file_get_contents(GAME_STATE_FILE);
    $data = json_decode($raw ?: '', true);
    return is_array($data) && isset($data['profiles'], $data['rooms'])
        ? $data : ['profiles'=>[], 'rooms'=>[]];
}
function game_profile(array &$state, array $member): array {
    $id = (string)$member['id'];
    if (!isset($state['profiles'][$id]) || !is_array($state['profiles'][$id])) {
        $state['profiles'][$id] = ['xp'=>0,'wins'=>0,'captures'=>0,'games'=>0,'updated_at'=>date(DATE_ATOM)];
    }
    $p = $state['profiles'][$id];
    $lv = game_level((int)($p['xp'] ?? 0));
    return [
        'username'=>(string)($member['username'] ?? ''),
        'nickname'=>(string)($member['nickname'] ?? $member['username'] ?? '지휘관'),
        'xp'=>(int)($p['xp'] ?? 0), 'level'=>$lv['level'],
        'level_xp'=>$lv['level_xp'], 'next_level_xp'=>$lv['next_level_xp'],
        'wins'=>(int)($p['wins'] ?? 0), 'captures'=>(int)($p['captures'] ?? 0),
        'games'=>(int)($p['games'] ?? 0)
    ];
}
function game_room_public(array $room): array {
    $players = [];
    foreach (($room['players'] ?? []) as $p) {
        $players[] = [
            'id'=>$p['id'], 'nickname'=>$p['nickname'], 'username'=>$p['username'],
            'country'=>$p['country'], 'level'=>$p['level'], 'ready'=>(bool)$p['ready']
        ];
    }
    $territories = [];
    foreach (($room['territories'] ?? []) as $t) {
        $owner = null;
        foreach ($players as $p) if (($p['id'] ?? '') === ($t['owner_id'] ?? '')) $owner = $p;
        $territories[] = [
            'name'=>$t['name'], 'score'=>(int)$t['score'], 'owner_id'=>$t['owner_id'] ?? null,
            'owner_name'=>$owner['nickname'] ?? null,
            'owner_flag'=>GAME_FLAGS[$owner['country'] ?? ''] ?? ''
        ];
    }
    $host = null;
    foreach ($players as $p) if ($p['id'] === ($room['host_id'] ?? '')) $host = $p;
    return [
        'code'=>$room['code'], 'status'=>$room['status'], 'host_id'=>$room['host_id'],
        'host_name'=>$host['nickname'] ?? '', 'players'=>$players,
        'territories'=>$territories, 'log'=>array_values($room['log'] ?? []),
        'ends_at'=>(int)($room['ends_at'] ?? 0), 'winner_name'=>$room['winner_name'] ?? null,
        'result_awarded'=>(bool)($room['result_awarded'] ?? false)
    ];
}
function game_room_index(array $state, string $code): ?int {
    foreach ($state['rooms'] as $i=>$room) if (strcasecmp((string)($room['code'] ?? ''), $code) === 0) return $i;
    return null;
}
function game_make_code(array $rooms): string {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    for ($n=0;$n<30;$n++) {
        $code='';
        for ($i=0;$i<5;$i++) $code .= $alphabet[random_int(0, strlen($alphabet)-1)];
        $exists=false; foreach ($rooms as $r) if (($r['code'] ?? '') === $code) $exists=true;
        if (!$exists) return $code;
    }
    return strtoupper(bin2hex(random_bytes(3)));
}
function game_add_log(array &$room, string $message): void {
    $room['log'][] = '['.date('H:i:s').'] '.$message;
    $room['log'] = array_slice($room['log'], -30);
}
function game_finalize(array &$state, array &$room): void {
    if (($room['status'] ?? '') !== 'playing' || !empty($room['result_awarded'])) return;
    $scores=[];
    foreach ($room['players'] as $p) $scores[$p['id']] = 0;
    foreach ($room['territories'] as $t) if (!empty($t['owner_id']) && isset($scores[$t['owner_id']])) $scores[$t['owner_id']] += (int)$t['score'];
    arsort($scores);
    $winnerId = array_key_first($scores);
    $winner = null;
    foreach ($room['players'] as $p) if ($p['id'] === $winnerId) $winner = $p;
    foreach ($room['players'] as $p) {
        $id=$p['id'];
        if (!isset($state['profiles'][$id])) $state['profiles'][$id]=['xp'=>0,'wins'=>0,'captures'=>0,'games'=>0];
        $state['profiles'][$id]['xp'] = (int)($state['profiles'][$id]['xp'] ?? 0) + 50 + (($id === $winnerId) ? 50 : 0);
        $state['profiles'][$id]['games'] = (int)($state['profiles'][$id]['games'] ?? 0) + 1;
        if ($id === $winnerId) $state['profiles'][$id]['wins'] = (int)($state['profiles'][$id]['wins'] ?? 0) + 1;
        $state['profiles'][$id]['updated_at']=date(DATE_ATOM);
    }
    $room['status']='finished';$room['winner_id']=$winnerId;$room['winner_name']=$winner['nickname'] ?? '지휘관';
    $room['result_awarded']=true;
    game_add_log($room, '전투 종료. 승리: '.$room['winner_name'].' (경험치 지급 완료)');
}
function game_write_state(array $state): void {
    $tmp = GAME_STATE_FILE.'.tmp.'.bin2hex(random_bytes(5));
    $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    if (file_put_contents($tmp, $json, LOCK_EX) === false) throw new RuntimeException('게임 데이터를 저장하지 못했습니다.');
    @chmod($tmp, 0660);
    if (!rename($tmp, GAME_STATE_FILE)) { @unlink($tmp); throw new RuntimeException('게임 데이터 교체에 실패했습니다.'); }
    @chmod(GAME_STATE_FILE, 0660);
}
$lock = @fopen(GAME_LOCK_FILE, 'c');
if (!$lock || !flock($lock, LOCK_EX)) game_error('게임 데이터 잠금을 얻지 못했습니다. 잠시 후 다시 시도해 주세요.', 503);
try {
    $state = game_read_state();
    $action = (string)($_POST['action'] ?? '');
    $memberId = (string)$member['id'];
    $profile = game_profile($state, $member);
    $roomCode = strtoupper(trim((string)($_POST['room'] ?? '')));
    $roomIndex = $roomCode !== '' ? game_room_index($state, $roomCode) : null;
    $activeRoom = null;
    foreach ($state['rooms'] as $r) {
        if (in_array($memberId, array_column($r['players'] ?? [], 'id'), true)
            && in_array($r['status'] ?? '', ['waiting','playing'], true)) {
            $activeRoom = $r['code']; break;
        }
    }
    if ($action === 'bootstrap') {
        game_write_state($state);
        game_reply(['ok'=>true,'csrf'=>site_csrf(),'member'=>['id'=>$memberId],'profile'=>$profile,'active_room'=>$activeRoom]);
    }
    if ($action === 'state') {
        if ($roomIndex === null) game_reply(['ok'=>true,'csrf'=>site_csrf(),'profile'=>$profile,'room'=>null]);
        $room = &$state['rooms'][$roomIndex];
        if (($room['status'] ?? '') === 'playing' && time() >= (int)$room['ends_at']) game_finalize($state,$room);
        game_write_state($state);
        game_reply(['ok'=>true,'csrf'=>site_csrf(),'profile'=>$profile,'room'=>game_room_public($room)]);
    }
    if ($action === 'create') {
        foreach ($state['rooms'] as $r) if (in_array($memberId, array_column($r['players'] ?? [], 'id'), true) && in_array($r['status'] ?? '', ['waiting','playing'], true)) game_error('이미 참가 중인 전투실이 있습니다.', 409);
        $country=(string)($_POST['country'] ?? '');
        if (!isset(GAME_COUNTRIES[$country])) game_error('국가를 선택해 주세요.');
        $code=game_make_code($state['rooms']);
        $state['rooms'][]=[
            'code'=>$code,'status'=>'waiting','host_id'=>$memberId,'created_at'=>time(),
            'ends_at'=>0,'winner_id'=>null,'winner_name'=>null,'result_awarded'=>false,
            'players'=>[['id'=>$memberId,'username'=>$profile['username'],'nickname'=>$profile['nickname'],'country'=>$country,'level'=>$profile['level'],'ready'=>true,'last_capture'=>0]],
            'territories'=>[
                ['name'=>'한반도','score'=>0,'owner_id'=>null],['name'=>'일본 열도','score'=>0,'owner_id'=>null],
                ['name'=>'중원','score'=>0,'owner_id'=>null],['name'=>'몽골 초원','score'=>0,'owner_id'=>null],
                ['name'=>'만주','score'=>0,'owner_id'=>null],['name'=>'동중국해','score'=>0,'owner_id'=>null],
                ['name'=>'황해','score'=>0,'owner_id'=>null],['name'=>'시베리아 관문','score'=>0,'owner_id'=>null]
            ],'log'=>[]
        ];
        game_add_log($state['rooms'][count($state['rooms'])-1], $profile['nickname'].' 지휘관이 전투실을 개설했습니다.');
        game_write_state($state);
        game_reply(['ok'=>true,'csrf'=>site_csrf(),'profile'=>$profile,'room'=>game_room_public($state['rooms'][count($state['rooms'])-1])]);
    }
    if ($action === 'join' || $action === 'quick_join') {
        $country=(string)($_POST['country'] ?? '');
        if (!isset(GAME_COUNTRIES[$country])) game_error('국가를 선택해 주세요.');
        if ($action === 'quick_join') {
            $roomIndex=null;
            foreach ($state['rooms'] as $i=>$r) if (($r['status'] ?? '')==='waiting' && count($r['players'] ?? [])<4) {$roomIndex=$i;break;}
        }
        if ($roomIndex === null) game_error($action==='quick_join'?'참가 가능한 전투실이 없습니다. 새 전투실을 만들어 주세요.':'방 코드를 찾을 수 없습니다.',404);
        $room = &$state['rooms'][$roomIndex];
        if (($room['status'] ?? '') !== 'waiting') game_error('이미 시작된 전투실입니다.',409);
        if (count($room['players']) >= 4) game_error('전투실이 가득 찼습니다.',409);
        foreach ($state['rooms'] as $r) if (in_array($memberId, array_column($r['players'] ?? [], 'id'), true) && in_array($r['status'] ?? '', ['waiting','playing'], true)) game_error('이미 참가 중인 전투실이 있습니다.',409);
        foreach ($room['players'] as $p) if ($p['country']===$country) game_error('해당 국가는 이미 선택되었습니다. 다른 국가를 골라 주세요.',409);
        $room['players'][]=['id'=>$memberId,'username'=>$profile['username'],'nickname'=>$profile['nickname'],'country'=>$country,'level'=>$profile['level'],'ready'=>false,'last_capture'=>0];
        game_add_log($room,$profile['nickname'].' 지휘관이 참가했습니다.');
        game_write_state($state);
        game_reply(['ok'=>true,'csrf'=>site_csrf(),'profile'=>$profile,'room'=>game_room_public($room)]);
    }
    if ($roomIndex === null) game_error('전투실을 찾을 수 없습니다. 다시 참가해 주세요.',404);
    $room = &$state['rooms'][$roomIndex];
    $playerIndex=null; foreach ($room['players'] as $i=>$p) if ($p['id']===$memberId) {$playerIndex=$i;break;}
    if ($playerIndex === null) game_error('이 전투실의 참가자가 아닙니다.',403);
    if ($action === 'ready') {
        if (($room['status'] ?? '')!=='waiting') game_error('이미 시작된 전투입니다.',409);
        $room['players'][$playerIndex]['ready'] = (string)($_POST['ready'] ?? '0') === '1';
        game_add_log($room,$profile['nickname'].($room['players'][$playerIndex]['ready']?' 준비 완료':' 준비 취소'));
    } elseif ($action === 'start') {
        if ($room['host_id'] !== $memberId) game_error('방장만 게임을 시작할 수 있습니다.',403);
        if (count($room['players'])<2) game_error('최소 2명이 참가해야 합니다.',409);
        foreach ($room['players'] as $p) if (empty($p['ready'])) game_error('모든 참가자가 준비 완료해야 합니다.',409);
        $room['status']='playing';$room['started_at']=time();$room['ends_at']=time()+600;
        foreach ($room['players'] as $p) {
            $country=$p['country'];
            $idx=['korea'=>0,'japan'=>1,'china'=>2,'mongolia'=>3][$country];
            $room['territories'][$idx]['owner_id']=$p['id'];
            $room['territories'][$idx]['score']=10;
        }
        game_add_log($room,'전투 개시! 각 지휘관은 영토를 점령해 점수를 확보하세요.');
    } elseif ($action === 'capture') {
        if (($room['status'] ?? '')!=='playing') game_error('현재 점령할 수 있는 전투가 아닙니다.',409);
        if (time()-(int)($room['players'][$playerIndex]['last_capture'] ?? 0)<1) game_error('너무 빠릅니다. 잠시 후 다시 시도해 주세요.',429);
        $country=(string)($room['players'][$playerIndex]['country'] ?? '');
        $ownedIndexes=[];
        foreach ($room['territories'] as $i=>$t) if (($t['owner_id'] ?? '')===$memberId) $ownedIndexes[]=$i;
        $targets=[];
        foreach ($room['territories'] as $i=>$t) if (($t['owner_id'] ?? '')!==$memberId) $targets[]=$i;
        if (!$targets) game_error('점령 가능한 영토가 없습니다.',409);
        // Deterministic selection per action prevents client-selected target spoofing.
        $targetIndex=$targets[random_int(0,count($targets)-1)];
        $room['territories'][$targetIndex]['owner_id']=$memberId;
        $room['territories'][$targetIndex]['score']=(int)$room['territories'][$targetIndex]['score']+10;
        $room['players'][$playerIndex]['last_capture']=time();
        if (!isset($state['profiles'][$memberId])) $state['profiles'][$memberId]=['xp'=>0,'wins'=>0,'captures'=>0,'games'=>0];
        $state['profiles'][$memberId]['captures']=(int)($state['profiles'][$memberId]['captures'] ?? 0)+1;
        $state['profiles'][$memberId]['xp']=(int)($state['profiles'][$memberId]['xp'] ?? 0)+5;
        $state['profiles'][$memberId]['updated_at']=date(DATE_ATOM);
        $profile=game_profile($state,$member);
        game_add_log($room,$profile['nickname'].' 지휘관이 '.$room['territories'][$targetIndex]['name'].'을(를) 점령했습니다.');
        if (time()>=(int)$room['ends_at']) game_finalize($state,$room);
    } elseif ($action === 'leave') {
        if (($room['status'] ?? '')==='waiting') {
            $room['players']=array_values(array_filter($room['players'],fn($p)=>$p['id']!==$memberId));
            if (!$room['players']) { array_splice($state['rooms'],$roomIndex,1); game_write_state($state); game_reply(['ok'=>true,'csrf'=>site_csrf(),'profile'=>$profile]); }
            if ($room['host_id']===$memberId) $room['host_id']=$room['players'][0]['id'];
            game_add_log($room,$profile['nickname'].' 지휘관이 대기실을 나갔습니다.');
        } else {
            game_error('진행 중인 전투에서 나가도 결과 집계는 전투 종료 후 진행됩니다. 전투 화면을 닫아도 됩니다.',409);
        }
    } else {
        game_error('알 수 없는 요청입니다.');
    }
    game_write_state($state);
    game_reply(['ok'=>true,'csrf'=>site_csrf(),'profile'=>$profile,'room'=>game_room_public($room)]);
} catch (Throwable $e) {
    error_log('[strategy-game] '.$e->getMessage());
    game_error('게임 처리 중 오류가 발생했습니다. 잠시 후 다시 시도해 주세요.',500);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
