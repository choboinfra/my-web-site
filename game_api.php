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
    $out=[];foreach(REGIONS as $t)$out[]=$t+['owner_id'=>null,'troops'=>0,'food'=>0,'metal'=>0,'fort_level'=>0];return $out;
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
function game_decided(array $r): bool {
    if (($r['status'] ?? '') !== 'playing') return false;
    $active = array_values(array_filter($r['players'] ?? [], fn($p) => empty($p['surrendered'])));
    if (count($active) <= 1) return count($r['players'] ?? []) >= 2;
    if (($r['mode'] ?? 'free') === 'team') {
        $teams = [];
        foreach ($active as $p) $teams[(string)($p['team_id'] ?? '')] = true;
        return count($teams) <= 1 && count($r['players'] ?? []) >= 2;
    }
    return false;
}
function team_label(string $team): string { return $team === 'red' ? '적색팀' : '청색팀'; }

function tick_armies(array &$s, array &$r): void {
    if (($r['status'] ?? '') !== 'playing') return;
    if (!isset($r['armies']) || !is_array($r['armies'])) $r['armies'] = [];
    $now = microtime(true); $remaining = [];
    foreach ($r['armies'] as $a) {
        if (($a['status'] ?? 'moving') !== 'moving') continue;
        if ($now < (float)($a['arrives_at'] ?? 0)) { $remaining[] = $a; continue; }
        $fi=$ti=null;
        foreach ($r['territories'] as $i=>$t) { if (($t['id']??'')===($a['from']??'')) $fi=$i; if (($t['id']??'')===($a['to']??'')) $ti=$i; }
        if ($fi===null || $ti===null) continue;
        $src=&$r['territories'][$fi]; $dst=&$r['territories'][$ti]; $sender=(string)$a['owner_id']; $troops=(int)$a['troops'];
        if (($dst['owner_id']??null)===$sender) { $dst['troops']=(int)($dst['troops']??0)+$troops; logline($r,'증원 병력이 '.$dst['name'].'에 도착했습니다.'); unset($src,$dst); continue; }
        if (($r['mode']??'free')==='team' && !empty($dst['owner_id'])) {
            $senderTeam=null;$targetTeam=null;
            foreach($r['players'] as $pl){if((string)$pl['id']===$sender)$senderTeam=(string)($pl['team_id']??'');if((string)$pl['id']===(string)$dst['owner_id'])$targetTeam=(string)($pl['team_id']??'');}
            if($senderTeam!==null && $senderTeam!=='' && $senderTeam===$targetTeam){$dst['troops']=(int)($dst['troops']??0)+$troops;logline($r,($a['nickname']??'아군').'의 지원 병력이 아군 '.$dst['name'].'에 합류했습니다.');unset($src,$dst);continue;}
        }
        $def=(int)($dst['troops']??0); $power=$troops*random_int(88,112)/100; $fortLevel=max(0,min(3,(int)($dst['fort_level']??0))); $defPower=max(1,$def)*random_int(88,112)/100*(1+0.18*$fortLevel);
        if ($power>$defPower) {
            $oldOwner=$dst['owner_id']??null; $dst['owner_id']=$sender; $dst['troops']=max(1,(int)floor($troops-$def*.55));
            if (!isset($s['profiles'][$sender])) $s['profiles'][$sender]=['xp'=>0,'wins'=>0,'captures'=>0,'games'=>0];
            $s['profiles'][$sender]['captures']=(int)($s['profiles'][$sender]['captures']??0)+1; $s['profiles'][$sender]['xp']=(int)($s['profiles'][$sender]['xp']??0)+5;
            logline($r,($a['nickname']??'지휘관').'의 병력이 '.$dst['name'].'을(를) 점령했습니다!');
            if ($oldOwner && $oldOwner!==$sender) { $left=0; foreach($r['territories'] as $t) if(($t['owner_id']??null)===$oldOwner)$left++; if($left===0) surrender_player($s,$r,$oldOwner,'영토 상실'); }
        } else { $dst['troops']=max(0,(int)ceil($def-$troops*.35)); logline($r,($a['nickname']??'지휘관').'의 '.$dst['name'].' 공격이 격퇴되었습니다.'); }
        unset($src,$dst);
    }
    $r['armies']=$remaining;
    if (game_decided($r)) finish_game($s,$r);
}
function surrender_player(array &$s, array &$r, string $id, string $reason='항복'): void {
    foreach ($r['players'] as &$p) if (($p['id']??'')===$id) { $p['surrendered']=true; $p['ready']=false; $name=$p['nickname']??'지휘관'; } unset($p);
    foreach ($r['territories'] as &$t) if (($t['owner_id']??null)===$id) { $t['owner_id']=null; $t['troops']=0; } unset($t);
    unset($r['economy'][$id]);
    $r['armies']=array_values(array_filter($r['armies']??[],fn($a)=>($a['owner_id']??'')!==$id));
    logline($r,($name??'지휘관').'이(가) '.$reason.'했습니다.');
    if (game_decided($r)) finish_game($s,$r);
}
function finish_game(array &$s,array &$r): void {
    if(($r['status']??'')!=='playing'||!empty($r['result_awarded']))return;
    $teamMode = (($r['mode'] ?? 'free') === 'team');
    $scores=[];
    foreach($r['players'] as $p) if(empty($p['surrendered'])) $scores[$teamMode ? (string)($p['team_id'] ?? 'blue') : (string)$p['id']] = 0;
    if(!$scores){$r['status']='finished';$r['winner_id']=null;$r['winner_name']='무승부';$r['result_awarded']=true;return;}
    foreach($r['territories'] as $t){
        $owner=(string)($t['owner_id']??'');
        if($owner==='')continue;
        if($teamMode){foreach($r['players'] as $p)if((string)$p['id']===$owner&&array_key_exists((string)($p['team_id']??'blue'),$scores)){$scores[(string)($p['team_id']??'blue')]++;break;}}
        elseif(isset($scores[$owner]))$scores[$owner]++;
    }
    arsort($scores);$winnerSide=array_key_first($scores);$winnerId=null;$winnerName='지휘관';
    if($teamMode){$winnerName=team_label((string)$winnerSide);foreach($r['players'] as $p)if(empty($p['surrendered'])&&(string)($p['team_id']??'blue')===(string)$winnerSide){$winnerId=$p['id'];break;}}
    else{$winnerId=(string)$winnerSide;foreach($r['players'] as $p)if((string)$p['id']===$winnerId)$winnerName=$p['nickname']??'지휘관';}
    foreach($r['players'] as $p){$id=(string)$p['id'];if(!isset($s['profiles'][$id]))$s['profiles'][$id]=['xp'=>0,'wins'=>0,'captures'=>0,'games'=>0];
      $won=$teamMode ? (empty($p['surrendered'])&&(string)($p['team_id']??'blue')===(string)$winnerSide) : ($id===(string)$winnerId);
      $s['profiles'][$id]['xp']=(int)($s['profiles'][$id]['xp']??0)+50+($won?50:0);
      $s['profiles'][$id]['games']=(int)($s['profiles'][$id]['games']??0)+1;if($won)$s['profiles'][$id]['wins']=(int)($s['profiles'][$id]['wins']??0)+1;}
    $r['status']='finished';$r['winner_id']=$winnerId;$r['winner_name']=$winnerName;$r['result_awarded']=true;logline($r,'전쟁 종료. 승리: '.$winnerName.' (경험치 지급 완료)');
}
function public_room(array $r): array {
    $players=[];foreach($r['players']??[] as $p)$players[]=['id'=>$p['id'],'nickname'=>$p['nickname'],'username'=>$p['username']??'','country'=>$p['country'],'level'=>$p['level']??1,'ready'=>(bool)($p['ready']??false),'surrendered'=>(bool)($p['surrendered']??false),'team_id'=>$p['team_id']??null];
    $territories=[];foreach($r['territories']??[] as $t){$owner=null;foreach($players as $p)if($p['id']===($t['owner_id']??null))$owner=$p;
      $territories[]=['id'=>$t['id']??null,'name'=>$t['name'],'x'=>$t['x']??0,'y'=>$t['y']??0,'neighbors'=>$t['neighbors']??[],
        'yield'=>$t['yield']??[],'owner_id'=>$t['owner_id']??null,'owner_name'=>$owner['nickname']??null,'owner_flag'=>FLAGS[$owner['country']??'']??'',
        'troops'=>(int)($t['troops']??0),'fort_level'=>max(0,min(3,(int)($t['fort_level']??0)))];}
    $eco=[];foreach($players as $p)$eco[$p['id']]=$r['economy'][$p['id']]??['food'=>0,'metal'=>0];
    return ['code'=>$r['code'],'status'=>$r['status'],'mode'=>$r['mode']??'free','host_id'=>$r['host_id'],'players'=>$players,'territories'=>$territories,
      'log'=>array_values($r['log']??[]),'ends_at'=>(int)($r['ends_at']??0),'winner_name'=>$r['winner_name']??null,
      'result_awarded'=>(bool)($r['result_awarded']??false),'map_version'=>(int)($r['map_version']??1),'economy'=>$eco,'armies'=>array_values($r['armies']??[])];
}
$lock=@fopen(LOCK_FILE,'c');if(!$lock||!flock($lock,LOCK_EX))fail('게임 잠금을 얻지 못했습니다. 잠시 후 다시 시도해 주세요.',503);
try {
    $s=read_state();$action=(string)($_POST['action']??'');$id=(string)$member['id'];$profile=profile_for($s,$member);
    $code=strtoupper(trim((string)($_POST['room']??'')));$ri=$code!==''?room_idx($s,$code):null;
    $active=null;foreach($s['rooms'] as $r)if(in_array($id,array_column($r['players']??[],'id'),true)&&in_array($r['status']??'', ['waiting','playing'],true)){$active=$r['code'];break;}
    if($action==='bootstrap'){write_state($s);reply(['ok'=>true,'csrf'=>site_csrf(),'member'=>['id'=>$id],'profile'=>$profile,'active_room'=>$active]);}
    if($action==='state'){
        if($ri===null)reply(['ok'=>true,'csrf'=>site_csrf(),'profile'=>$profile,'room'=>null]);
        $r=&$s['rooms'][$ri];if(($r['status']??'')==='playing'){tick_economy($r);tick_armies($s,$r);if(time()>=(int)($r['ends_at']??0))finish_game($s,$r);}
        write_state($s);reply(['ok'=>true,'csrf'=>site_csrf(),'profile'=>$profile,'room'=>public_room($r)]);
    }
    if($action==='create'){
        foreach($s['rooms'] as $r)if(in_array($id,array_column($r['players']??[],'id'),true)&&in_array($r['status']??'', ['waiting','playing'],true))fail('이미 참가 중인 전투실이 있습니다.',409);
        $country=(string)($_POST['country']??'');if(!isset(COUNTRIES[$country]))fail('국가를 선택해 주세요.');
        $mode=(string)($_POST['mode']??'free');if(!in_array($mode,['free','team'],true))$mode='free';
        $team=(string)($_POST['team_id']??'blue');if(!in_array($team,['blue','red'],true))$team='blue';
        $new=['code'=>room_code($s['rooms']),'mode'=>$mode,'status'=>'waiting','host_id'=>$id,'created_at'=>time(),'ends_at'=>0,'winner_id'=>null,'winner_name'=>null,'result_awarded'=>false,
          'players'=>[['id'=>$id,'username'=>$profile['username'],'nickname'=>$profile['nickname'],'country'=>$country,'level'=>$profile['level'],'ready'=>true,'team_id'=>$mode==='team'?$team:null]],
          'territories'=>region_templates(),'log'=>[],'map_version'=>2,'armies'=>[],'economy'=>[$id=>['food'=>80,'metal'=>50]],'last_tick'=>time()];
        $s['rooms'][]=$new;$ri=count($s['rooms'])-1;logline($s['rooms'][$ri],$profile['nickname'].' 지휘관이 작전실을 개설했습니다.');write_state($s);
        reply(['ok'=>true,'csrf'=>site_csrf(),'profile'=>$profile,'room'=>public_room($s['rooms'][$ri])]);
    }
    if($action==='join'||$action==='quick_join'){
        $country=(string)($_POST['country']??'');if(!isset(COUNTRIES[$country]))fail('국가를 선택해 주세요.');
        $requestedMode=(string)($_POST['mode']??'free');if(!in_array($requestedMode,['free','team'],true))$requestedMode='free';
        $requestedTeam=(string)($_POST['team_id']??'blue');if(!in_array($requestedTeam,['blue','red'],true))$requestedTeam='blue';
        if($action==='quick_join'){$ri=null;foreach($s['rooms'] as $i=>$r)if(($r['status']??'')==='waiting'&&count($r['players']??[])<4&&((int)($r['map_version']??1)===2)&&(($r['mode']??'free')===$requestedMode)){$ri=$i;break;}}
        if($ri===null)fail($action==='quick_join'?'선택한 모드에서 참가 가능한 대기실이 없습니다. 새 전투실을 만들어 주세요.':'전투실 코드를 찾을 수 없습니다.',404);
        $r=&$s['rooms'][$ri];if(($r['status']??'')!=='waiting')fail('이미 시작된 전투입니다.',409);
        if(count($r['players'])>=4)fail('전투실이 가득 찼습니다.',409);
        foreach($s['rooms'] as $other)if(in_array($id,array_column($other['players']??[],'id'),true)&&in_array($other['status']??'', ['waiting','playing'],true))fail('이미 참가 중인 전투실이 있습니다.',409);
        foreach($r['players'] as $p)if($p['country']===$country)fail('해당 국가는 이미 선택되었습니다.',409);
        $mode=(string)($r['mode']??'free');$teamId=null;
        if($mode==='team'){$teamId=$requestedTeam;$teamCount=0;foreach($r['players'] as $p)if((string)($p['team_id']??'blue')===$teamId)$teamCount++;if($teamCount>=2)fail('해당 팀은 이미 2명으로 가득 찼습니다.',409);}
        $r['players'][]=['id'=>$id,'username'=>$profile['username'],'nickname'=>$profile['nickname'],'country'=>$country,'level'=>$profile['level'],'ready'=>false,'team_id'=>$teamId];
        $r['economy'][$id]=['food'=>80,'metal'=>50];logline($r,$profile['nickname'].' 지휘관이 참가했습니다.');write_state($s);
        reply(['ok'=>true,'csrf'=>site_csrf(),'profile'=>$profile,'room'=>public_room($r)]);
    }
    if($ri===null)fail('전투실을 찾을 수 없습니다.',404);
    $r=&$s['rooms'][$ri];$pi=null;foreach($r['players'] as $i=>$p)if($p['id']===$id)$pi=$i;
    if($pi===null)fail('이 전투실의 참가자가 아닙니다.',403);if(!empty($r['players'][$pi]['surrendered']) && $action!=='state')fail('항복한 지휘관은 더 이상 명령을 내릴 수 없습니다.',403);
    if($action==='ready'){
        if($r['status']!=='waiting')fail('이미 시작된 전투입니다.',409);
        $r['players'][$pi]['ready']=(string)($_POST['ready']??'0')==='1';logline($r,$profile['nickname'].($r['players'][$pi]['ready']?' 준비 완료':' 준비 취소'));
    } elseif($action==='start'){
        if($r['host_id']!==$id)fail('방장만 시작할 수 있습니다.',403);
        if(count($r['players'])<2)fail('최소 2명이 참가해야 합니다.',409);
        foreach($r['players'] as $p)if(empty($p['ready']))fail('모든 참가자가 준비 완료해야 합니다.',409);
        if(($r['mode']??'free')==='team'){$teams=[];foreach($r['players'] as $p)$teams[(string)($p['team_id']??'blue')]=true;if(count($teams)<2)fail('팀전은 청색팀과 적색팀에 각각 1명 이상 필요합니다.',409);}
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
    } elseif($action==='fortify'){
        if($r['status']!=='playing'||empty($r['map_version']))fail('진행 중인 새 지도 전투에서만 요새화할 수 있습니다.',409);
        tick_economy($r);$target=(string)($_POST['territory']??'');$idx=null;
        foreach($r['territories'] as $i=>$t)if(($t['id']??'')===$target)$idx=$i;
        if($idx===null)fail('지역을 찾을 수 없습니다.',404);
        if(($r['territories'][$idx]['owner_id']??null)!==$id)fail('자신이 보유한 지역만 요새화할 수 있습니다.',403);
        $level=max(0,min(3,(int)($r['territories'][$idx]['fort_level']??0)));
        if($level>=3)fail('이 지역의 방어 시설은 최대 단계입니다.',409);
        $costFood=20+($level*15);$costMetal=15+($level*20);$eco=&$r['economy'][$id];
        if(($eco['food']??0)<$costFood||($eco['metal']??0)<$costMetal)fail('자원이 부족합니다. 요새화 비용: 식량 '.$costFood.', 금속 '.$costMetal,409);
        $eco['food']-=$costFood;$eco['metal']-=$costMetal;$r['territories'][$idx]['fort_level']=$level+1;
        logline($r,$profile['nickname'].' 지휘관이 '.$r['territories'][$idx]['name'].' 방어 시설을 '.$level.' → '.($level+1).'단계로 강화했습니다.');
    } elseif($action==='attack'){
        if($r['status']!=='playing'||empty($r['map_version']))fail('진행 중인 새 지도 전투에서만 공격할 수 있습니다.',409);
        tick_economy($r);tick_armies($s,$r);
        $from=(string)($_POST['from']??'');$to=(string)($_POST['to']??'');$fi=$ti=null;
        foreach($r['territories'] as $i=>$t){if(($t['id']??'')===$from)$fi=$i;if(($t['id']??'')===$to)$ti=$i;}
        if($fi===null||$ti===null||$fi===$ti)fail('공격 지역을 선택해 주세요.');
        $src=&$r['territories'][$fi];$dst=&$r['territories'][$ti];
        if(($src['owner_id']??null)!==$id)fail('자신이 보유한 지역에서만 공격할 수 있습니다.',403);
        if(!in_array($to,$src['neighbors']??[],true))fail('인접한 지역만 공격할 수 있습니다.',409);
        if(($dst['owner_id']??null)===$id)fail('이미 보유한 지역입니다.',409);
        if(($r['mode']??'free')==='team' && !empty($dst['owner_id'])){foreach($r['players'] as $ally){if((string)$ally['id']===(string)$dst['owner_id'] && (string)($ally['team_id']??'')===(string)($r['players'][$pi]['team_id']??''))fail('팀전에서는 아군 지역을 공격할 수 없습니다.',409);}}
        $send=max(1,min((int)($_POST['troops']??10),(int)($src['troops']??0)-1));
        if($send<5)fail('공격하려면 출발 지역에 최소 6명 이상의 병력이 필요합니다.',409);
        $src['troops']-=$send;unset($src,$dst);
        if(!isset($r['armies'])||!is_array($r['armies']))$r['armies']=[];
        $fromDef=$r['territories'][$fi];$toDef=$r['territories'][$ti];
        $distance=hypot(((float)$toDef['x']-(float)$fromDef['x']),((float)$toDef['y']-(float)$fromDef['y']));
        $travel=max(4,min(12,(int)round($distance/32)));
        $r['armies'][]=['id'=>bin2hex(random_bytes(5)),'owner_id'=>$id,'nickname'=>$profile['nickname'],'from'=>$from,'to'=>$to,'troops'=>$send,'started_at'=>microtime(true),'arrives_at'=>microtime(true)+$travel,'status'=>'moving'];
        logline($r,$profile['nickname'].' 지휘관이 '.$fromDef['name'].'에서 '.$toDef['name'].'으로 병력 '.$send.'명을 출격시켰습니다. 도착 예정 '.$travel.'초.');
    } elseif($action==='surrender'){
        if(($r['status']??'')!=='playing')fail('진행 중인 전쟁에서만 항복할 수 있습니다.',409);
        surrender_player($s,$r,$id,'항복');
    } elseif($action==='capture'){
        fail('이 버전에서는 지도에서 공격할 지역을 선택한 뒤 공격하세요.',410);
    } elseif($action==='leave'){
        if($r['status']==='waiting'){$r['players']=array_values(array_filter($r['players'],fn($p)=>$p['id']!==$id));unset($r['economy'][$id]);
          if(!$r['players']){array_splice($s['rooms'],$ri,1);write_state($s);reply(['ok'=>true,'csrf'=>site_csrf(),'profile'=>$profile]);}
          if($r['host_id']===$id)$r['host_id']=$r['players'][0]['id'];logline($r,$profile['nickname'].' 지휘관이 대기실을 나갔습니다.');
        } else fail('진행 중인 전쟁에서는 나갈 수 없습니다. 전투 종료 후 나가 주세요.',409);
    } else fail('알 수 없는 요청입니다.');
    if(($r['status']??'')==='playing'){tick_economy($r);tick_armies($s,$r);if(time()>=(int)($r['ends_at']??0))finish_game($s,$r);}
    write_state($s);reply(['ok'=>true,'csrf'=>site_csrf(),'profile'=>$profile,'room'=>public_room($r)]);
} catch(Throwable $e) {
    error_log('[strategy-game-v2] '.$e->getMessage());
    fail('게임 처리 중 오류가 발생했습니다. 잠시 후 다시 시도해 주세요.',500);
} finally { flock($lock,LOCK_UN);fclose($lock); }
