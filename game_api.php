<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/members_lib.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

function reply(array $d, int $status=200): void {
    http_response_code($status);
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function fail(string $m, int $s=400): void { reply(['ok'=>false,'error'=>$m,'csrf'=>site_csrf()],$s); }
function current_game_member(): ?array {
    $m = site_current_member();
    if (is_array($m)) return $m;
    if (!empty($_SESSION['admin_logged_in'])) return [
        'id'=>'admin-account-choadmin','username'=>'choadmin','nickname'=>'관리자','role'=>'operator'
    ];
    return null;
}
$member = current_game_member();
if (!$member) fail('게임은 로그인 회원만 이용할 수 있습니다. 로그인 후 다시 시도해 주세요.',401);
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && (string)($_GET['bootstrap'] ?? '') === '1') {
    reply(['ok'=>true,'csrf'=>site_csrf()]);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') fail('POST 요청만 허용됩니다.',405);
if (!site_valid_csrf()) fail('요청 토큰이 만료되었습니다. 페이지를 새로고침해 주세요.',403);

const STATE_FILE = '/var/lib/site-live/game_state.json';
const LOCK_FILE = '/var/lib/site-live/game_state.lock';
const COUNTRIES = ['korea'=>'한국','japan'=>'일본','china'=>'중국','mongolia'=>'몽골'];
const FLAGS = ['korea'=>'🇰🇷','japan'=>'🇯🇵','china'=>'🇨🇳','mongolia'=>'🇲🇳'];
const REGIONS = [
 ['id'=>'far_east','name'=>'극동','x'=>80,'y'=>80,'neighbors'=>['mongolia_n','manchuria'],'yield'=>['food'=>3,'metal'=>2]],
 ['id'=>'mongolia_n','name'=>'북몽골','x'=>230,'y'=>80,'neighbors'=>['far_east','mongolia_s','manchuria'],'yield'=>['food'=>3,'metal'=>2]],
 ['id'=>'manchuria','name'=>'만주','x'=>380,'y'=>80,'neighbors'=>['far_east','mongolia_n','north_china','korea_n'],'yield'=>['food'=>3,'metal'=>4]],
 ['id'=>'hokkaido','name'=>'홋카이도','x'=>680,'y'=>80,'neighbors'=>['honshu'],'yield'=>['food'=>3,'metal'=>2]],
 ['id'=>'mongolia_s','name'=>'남몽골','x'=>230,'y'=>200,'neighbors'=>['mongolia_n','north_china','west_china'],'yield'=>['food'=>4,'metal'=>2]],
 ['id'=>'north_china','name'=>'화북','x'=>380,'y'=>200,'neighbors'=>['manchuria','mongolia_s','west_china','east_china','korea_n'],'yield'=>['food'=>4,'metal'=>3]],
 ['id'=>'korea_n','name'=>'북한','x'=>520,'y'=>200,'neighbors'=>['manchuria','north_china','korea_s'],'yield'=>['food'=>3,'metal'=>2]],
 ['id'=>'honshu','name'=>'혼슈','x'=>680,'y'=>200,'neighbors'=>['hokkaido','kyushu'],'yield'=>['food'=>3,'metal'=>3]],
 ['id'=>'west_china','name'=>'서중국','x'=>230,'y'=>320,'neighbors'=>['mongolia_s','north_china','south_china'],'yield'=>['food'=>3,'metal'=>4]],
 ['id'=>'east_china','name'=>'화동','x'=>380,'y'=>320,'neighbors'=>['north_china','south_china','taiwan'],'yield'=>['food'=>4,'metal'=>3]],
 ['id'=>'korea_s','name'=>'남한','x'=>520,'y'=>320,'neighbors'=>['korea_n','kyushu'],'yield'=>['food'=>4,'metal'=>2]],
 ['id'=>'kyushu','name'=>'규슈','x'=>680,'y'=>320,'neighbors'=>['honshu','korea_s','taiwan'],'yield'=>['food'=>3,'metal'=>2]],
 ['id'=>'south_china','name'=>'화남','x'=>380,'y'=>440,'neighbors'=>['west_china','east_china','taiwan'],'yield'=>['food'=>5,'metal'=>2]],
 ['id'=>'taiwan','name'=>'대만','x'=>520,'y'=>440,'neighbors'=>['east_china','south_china','kyushu'],'yield'=>['food'=>2,'metal'=>1]]
];
function read_state(): array {
    if (!is_file(STATE_FILE)) return ['profiles'=>[],'rooms'=>[]];
    $d=json_decode((string)@file_get_contents(STATE_FILE),true);
    return is_array($d) && isset($d['profiles'],$d['rooms']) ? $d : ['profiles'=>[],'rooms'=>[]];
}
function write_state(array $s): void {
    $tmp=STATE_FILE.'.tmp.'.bin2hex(random_bytes(4));
    $json=json_encode($s,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
    if (file_put_contents($tmp,$json,LOCK_EX)===false) throw new RuntimeException('게임 저장 실패');
    @chmod($tmp,0660);
    if (!rename($tmp,STATE_FILE)) { @unlink($tmp); throw new RuntimeException('게임 저장 교체 실패'); }
    @chmod(STATE_FILE,0660);
}
function level_data(int $xp): array {
    $lv=1; $left=max(0,$xp);
    while($lv<100){$need=100+(($lv-1)*25);if($left<$need)break;$left-=$need;$lv++;}
    return ['level'=>$lv,'level_xp'=>$left,'next_level_xp'=>100+(($lv-1)*25)];
}
function profile_for(array &$s,array $m): array {
    $id=(string)$m['id'];
    if(!isset($s['profiles'][$id])||!is_array($s['profiles'][$id]))$s['profiles'][$id]=['xp'=>0,'wins'=>0,'captures'=>0,'games'=>0];
    $p=$s['profiles'][$id];$l=level_data((int)($p['xp']??0));
    return ['id'=>$id,'username'=>(string)($m['username']??''),'nickname'=>(string)($m['nickname']??$m['username']??'지휘관'),
      'xp'=>(int)($p['xp']??0),'level'=>$l['level'],'level_xp'=>$l['level_xp'],'next_level_xp'=>$l['next_level_xp'],
      'wins'=>(int)($p['wins']??0),'captures'=>(int)($p['captures']??0),'games'=>(int)($p['games']??0)];
}
function room_idx(array $s,string $code): ?int { foreach($s['rooms'] as $i=>$r)if(strcasecmp((string)($r['code']??''),$code)===0)return $i;return null; }
function room_code(array $rooms): string {
    $abc='ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    for($n=0;$n<50;$n++){ $c='';for($i=0;$i<5;$i++)$c.=$abc[random_int(0,strlen($abc)-1)];$used=false;foreach($rooms as $r)if(($r['code']??'')===$c)$used=true;if(!$used)return $c;}
    return strtoupper(bin2hex(random_bytes(3)));
}
function logline(array &$r,string $msg): void { $r['log'][]='['.date('H:i:s').'] '.$msg;$r['log']=array_slice($r['log'],-40); }
function region_templates(): array {
    $out=[];foreach(REGIONS as $t)$out[]=$t+['owner_id'=>null,'troops'=>0,'food'=>0,'metal'=>0];return $out;
}
function tick_economy(array &$r): void {
    if(($r['status']??'')!=='playing'||empty($r['map_version']))return;
    $now=time();$last=(int)($r['last_tick']??$now);$ticks=max(0,min(60,$now-$last));if(!$ticks)return;
    foreach($r['players'] as $p){
        $owned=0;foreach($r['territories'] as $t)if(($t['owner_id']??null)===$p['id'])$owned++;
        $id=$p['id'];if(!isset($r['economy'][$id]))$r['economy'][$id]=['food'=>50,'metal'=>30];
        $r['economy'][$id]['food']+=(int)floor($owned*0.8*$ticks);
        $r['economy'][$id]['metal']+=(int)floor($owned*0.45*$ticks);
    }
    $r['last_tick']=$now;
}
function finish_game(array &$s,array &$r): void {
    if(($r['status']??'')!=='playing'||!empty($r['result_awarded']))return;
    $scores=[];foreach($r['players'] as $p)$scores[$p['id']]=0;
    foreach($r['territories'] as $t)if(isset($scores[$t['owner_id']??'']))$scores[$t['owner_id']]++;
    arsort($scores);$winnerId=array_key_first($scores);$winnerName='지휘관';
    foreach($r['players'] as $p)if($p['id']===$winnerId)$winnerName=$p['nickname'];
    foreach($r['players'] as $p){$id=$p['id'];if(!isset($s['profiles'][$id]))$s['profiles'][$id]=['xp'=>0,'wins'=>0,'captures'=>0,'games'=>0];
      $s['profiles'][$id]['xp']=(int)($s['profiles'][$id]['xp']??0)+50+($id===$winnerId?50:0);
      $s['profiles'][$id]['games']=(int)($s['profiles'][$id]['games']??0)+1;if($id===$winnerId)$s['profiles'][$id]['wins']=(int)($s['profiles'][$id]['wins']??0)+1;}
    $r['status']='finished';$r['winner_id']=$winnerId;$r['winner_name']=$winnerName;$r['result_awarded']=true;logline($r,'전쟁 종료. 승리: '.$winnerName.' (경험치 지급 완료)');
}
function public_room(array $r): array {
    $players=[];foreach($r['players']??[] as $p)$players[]=['id'=>$p['id'],'nickname'=>$p['nickname'],'username'=>$p['username']??'','country'=>$p['country'],'level'=>$p['level']??1,'ready'=>(bool)($p['ready']??false)];
    $territories=[];foreach($r['territories']??[] as $t){$owner=null;foreach($players as $p)if($p['id']===($t['owner_id']??null))$owner=$p;
      $territories[]=['id'=>$t['id']??null,'name'=>$t['name'],'x'=>$t['x']??0,'y'=>$t['y']??0,'neighbors'=>$t['neighbors']??[],
        'yield'=>$t['yield']??[],'owner_id'=>$t['owner_id']??null,'owner_name'=>$owner['nickname']??null,'owner_flag'=>FLAGS[$owner['country']??'']??'',
        'troops'=>(int)($t['troops']??0)];}
    $eco=[];foreach($players as $p)$eco[$p['id']]=$r['economy'][$p['id']]??['food'=>0,'metal'=>0];
    return ['code'=>$r['code'],'status'=>$r['status'],'host_id'=>$r['host_id'],'players'=>$players,'territories'=>$territories,
      'log'=>array_values($r['log']??[]),'ends_at'=>(int)($r['ends_at']??0),'winner_name'=>$r['winner_name']??null,
      'result_awarded'=>(bool)($r['result_awarded']??false),'map_version'=>(int)($r['map_version']??1),'economy'=>$eco];
}
$lock=@fopen(LOCK_FILE,'c');if(!$lock||!flock($lock,LOCK_EX))fail('게임 잠금을 얻지 못했습니다. 잠시 후 다시 시도해 주세요.',503);
try {
    $s=read_state();$action=(string)($_POST['action']??'');$id=(string)$member['id'];$profile=profile_for($s,$member);
    $code=strtoupper(trim((string)($_POST['room']??'')));$ri=$code!==''?room_idx($s,$code):null;
    $active=null;foreach($s['rooms'] as $r)if(in_array($id,array_column($r['players']??[],'id'),true)&&in_array($r['status']??'', ['waiting','playing'],true)){$active=$r['code'];break;}
    if($action==='bootstrap'){write_state($s);reply(['ok'=>true,'csrf'=>site_csrf(),'member'=>['id'=>$id],'profile'=>$profile,'active_room'=>$active]);}
    if($action==='state'){
        if($ri===null)reply(['ok'=>true,'csrf'=>site_csrf(),'profile'=>$profile,'room'=>null]);
        $r=&$s['rooms'][$ri];if(($r['status']??'')==='playing'){tick_economy($r);if(time()>=(int)($r['ends_at']??0))finish_game($s,$r);}
        write_state($s);reply(['ok'=>true,'csrf'=>site_csrf(),'profile'=>$profile,'room'=>public_room($r)]);
    }
    if($action==='create'){
        foreach($s['rooms'] as $r)if(in_array($id,array_column($r['players']??[],'id'),true)&&in_array($r['status']??'', ['waiting','playing'],true))fail('이미 참가 중인 전투실이 있습니다.',409);
        $country=(string)($_POST['country']??'');if(!isset(COUNTRIES[$country]))fail('국가를 선택해 주세요.');
        $new=['code'=>room_code($s['rooms']),'status'=>'waiting','host_id'=>$id,'created_at'=>time(),'ends_at'=>0,'winner_id'=>null,'winner_name'=>null,'result_awarded'=>false,
          'players'=>[['id'=>$id,'username'=>$profile['username'],'nickname'=>$profile['nickname'],'country'=>$country,'level'=>$profile['level'],'ready'=>true]],
          'territories'=>region_templates(),'log'=>[],'map_version'=>2,'economy'=>[$id=>['food'=>80,'metal'=>50]],'last_tick'=>time()];
        $s['rooms'][]=$new;$ri=count($s['rooms'])-1;logline($s['rooms'][$ri],$profile['nickname'].' 지휘관이 작전실을 개설했습니다.');write_state($s);
        reply(['ok'=>true,'csrf'=>site_csrf(),'profile'=>$profile,'room'=>public_room($s['rooms'][$ri])]);
    }
    if($action==='join'||$action==='quick_join'){
        $country=(string)($_POST['country']??'');if(!isset(COUNTRIES[$country]))fail('국가를 선택해 주세요.');
        if($action==='quick_join'){$ri=null;foreach($s['rooms'] as $i=>$r)if(($r['status']??'')==='waiting'&&count($r['players']??[])<4&&((int)($r['map_version']??1)===2)){$ri=$i;break;}}
        if($ri===null)fail($action==='quick_join'?'참가 가능한 새 지도 전투실이 없습니다. 새 전투실을 만들어 주세요.':'전투실 코드를 찾을 수 없습니다.',404);
        $r=&$s['rooms'][$ri];if(($r['status']??'')!=='waiting')fail('이미 시작된 전투입니다.',409);
        if(count($r['players'])>=4)fail('전투실이 가득 찼습니다.',409);
        foreach($s['rooms'] as $other)if(in_array($id,array_column($other['players']??[],'id'),true)&&in_array($other['status']??'', ['waiting','playing'],true))fail('이미 참가 중인 전투실이 있습니다.',409);
        foreach($r['players'] as $p)if($p['country']===$country)fail('해당 국가는 이미 선택되었습니다.',409);
        $r['players'][]=['id'=>$id,'username'=>$profile['username'],'nickname'=>$profile['nickname'],'country'=>$country,'level'=>$profile['level'],'ready'=>false];
        $r['economy'][$id]=['food'=>80,'metal'=>50];logline($r,$profile['nickname'].' 지휘관이 참가했습니다.');write_state($s);
        reply(['ok'=>true,'csrf'=>site_csrf(),'profile'=>$profile,'room'=>public_room($r)]);
    }
    if($ri===null)fail('전투실을 찾을 수 없습니다.',404);
    $r=&$s['rooms'][$ri];$pi=null;foreach($r['players'] as $i=>$p)if($p['id']===$id)$pi=$i;
    if($pi===null)fail('이 전투실의 참가자가 아닙니다.',403);
    if($action==='ready'){
        if($r['status']!=='waiting')fail('이미 시작된 전투입니다.',409);
        $r['players'][$pi]['ready']=(string)($_POST['ready']??'0')==='1';logline($r,$profile['nickname'].($r['players'][$pi]['ready']?' 준비 완료':' 준비 취소'));
    } elseif($action==='start'){
        if($r['host_id']!==$id)fail('방장만 시작할 수 있습니다.',403);
        if(count($r['players'])<2)fail('최소 2명이 참가해야 합니다.',409);
        foreach($r['players'] as $p)if(empty($p['ready']))fail('모든 참가자가 준비 완료해야 합니다.',409);
        if((int)($r['map_version']??1)!==2)fail('이 방은 이전 버전의 전투입니다. 기존 전투를 종료한 뒤 새 방을 만들어 주세요.',409);
        $r['status']='playing';$r['started_at']=time();$r['ends_at']=time()+900;$r['last_tick']=time();
        $startRegion=['korea'=>'korea_s','japan'=>'honshu','china'=>'east_china','mongolia'=>'mongolia_s'];
        foreach($r['players'] as $p){$start=$startRegion[$p['country']];foreach($r['territories'] as &$t)if($t['id']===$start){$t['owner_id']=$p['id'];$t['troops']=30;}unset($t);}
        logline($r,'전쟁 개시! 자원을 모아 병력을 모집하고 인접 지역을 공격하세요.');
    } elseif($action==='recruit'){
        if($r['status']!=='playing'||empty($r['map_version']))fail('진행 중인 새 지도 전투에서만 모집할 수 있습니다.',409);
        tick_economy($r);$amount=max(1,min(50,(int)($_POST['amount']??10)));$eco=&$r['economy'][$id];
        $costFood=$amount*2;$costMetal=(int)ceil($amount/5);
        if(($eco['food']??0)<$costFood||($eco['metal']??0)<$costMetal)fail('자원이 부족합니다. 영토를 더 확보하고 기다려 주세요.',409);
        $owned=[];foreach($r['territories'] as &$t)if(($t['owner_id']??null)===$id)$owned[]=&$t;unset($t);
        if(!$owned)fail('병력을 배치할 영토가 없습니다.',409);
        $eco['food']-=$costFood;$eco['metal']-=$costMetal;$owned[0]['troops']+=(int)$amount;
        logline($r,$profile['nickname'].' 지휘관이 병력 '.$amount.'명을 모집했습니다.');
    } elseif($action==='attack'){
        if($r['status']!=='playing'||empty($r['map_version']))fail('진행 중인 새 지도 전투에서만 공격할 수 있습니다.',409);
        tick_economy($r);$from=(string)($_POST['from']??'');$to=(string)($_POST['to']??'');$fi=$ti=null;
        foreach($r['territories'] as $i=>$t){if(($t['id']??'')===$from)$fi=$i;if(($t['id']??'')===$to)$ti=$i;}
        if($fi===null||$ti===null||$fi===$ti)fail('공격 지역을 선택해 주세요.');
        $src=&$r['territories'][$fi];$dst=&$r['territories'][$ti];
        if(($src['owner_id']??null)!==$id)fail('자신이 보유한 지역에서만 공격할 수 있습니다.',403);
        if(!in_array($to,$src['neighbors']??[],true))fail('인접한 지역만 공격할 수 있습니다.',409);
        if(($dst['owner_id']??null)===$id)fail('이미 보유한 지역입니다.',409);
        $send=max(1,min((int)($_POST['troops']??10),(int)$src['troops']-1));
        if($send<5)fail('공격하려면 출발 지역에 최소 6명 이상의 병력이 필요합니다.',409);
        $def=(int)$dst['troops'];$attackPower=$send*random_int(85,115)/100;$defPower=max(1,$def)*random_int(85,115)/100;
        $src['troops']-=$send;
        if($attackPower>$defPower){
            $dst['owner_id']=$id;$dst['troops']=max(1,(int)floor($send-$def*0.55));$dst['food']=0;$dst['metal']=0;
            if(!isset($s['profiles'][$id]))$s['profiles'][$id]=['xp'=>0,'wins'=>0,'captures'=>0,'games'=>0];
            $s['profiles'][$id]['captures']=(int)($s['profiles'][$id]['captures']??0)+1;$s['profiles'][$id]['xp']=(int)($s['profiles'][$id]['xp']??0)+5;
            logline($r,$profile['nickname'].' 지휘관이 '.$dst['name'].'을(를) 점령했습니다!');
        } else {$dst['troops']=max(0,(int)ceil($def-$send*0.35));logline($r,$profile['nickname'].' 지휘관의 '.$dst['name'].' 공격이 격퇴되었습니다.');}
        $profile=profile_for($s,$member);
        $owned=0;foreach($r['territories'] as $t)if(($t['owner_id']??null)===$id)$owned++;
        $all=0;foreach($r['territories'] as $t)if(!empty($t['owner_id']))$all++;
        if($all===count($r['territories'])||time()>=(int)$r['ends_at'])finish_game($s,$r);
    } elseif($action==='capture'){
        fail('이 버전에서는 지도에서 공격할 지역을 선택한 뒤 공격하세요.',410);
    } elseif($action==='leave'){
        if($r['status']==='waiting'){$r['players']=array_values(array_filter($r['players'],fn($p)=>$p['id']!==$id));unset($r['economy'][$id]);
          if(!$r['players']){array_splice($s['rooms'],$ri,1);write_state($s);reply(['ok'=>true,'csrf'=>site_csrf(),'profile'=>$profile]);}
          if($r['host_id']===$id)$r['host_id']=$r['players'][0]['id'];logline($r,$profile['nickname'].' 지휘관이 대기실을 나갔습니다.');
        } else fail('진행 중인 전쟁에서는 나갈 수 없습니다. 전투 종료 후 나가 주세요.',409);
    } else fail('알 수 없는 요청입니다.');
    if(($r['status']??'')==='playing'&&time()>=(int)($r['ends_at']??0))finish_game($s,$r);
    write_state($s);reply(['ok'=>true,'csrf'=>site_csrf(),'profile'=>$profile,'room'=>public_room($r)]);
} catch(Throwable $e) {
    error_log('[strategy-game-v2] '.$e->getMessage());
    fail('게임 처리 중 오류가 발생했습니다. 잠시 후 다시 시도해 주세요.',500);
} finally { flock($lock,LOCK_UN);fclose($lock); }
