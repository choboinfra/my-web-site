<?php
declare(strict_types=1);
const FS_ROOT = '/var/lib/site-live/file_shares';
const FS_MAX_FILE = 1073741824;
const FS_MAX_TOTAL = 5368709120;
const FS_CHUNK_MAX = 16777216;
const FS_TTL = 86400;
const FS_UPLOAD_TTL = 86400;
function fs_paths(): array {return ['root'=>FS_ROOT,'files'=>FS_ROOT.'/files','chunks'=>FS_ROOT.'/chunks','db'=>FS_ROOT.'/shares.json','lock'=>FS_ROOT.'/shares.lock'];}
function fs_json(array $v,int $status=200): void {http_response_code($status);header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');echo json_encode($v,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);exit;}
function fs_member(string $id): ?array {require_once '/var/www/html/lib/members_lib.php';return site_member_by_id($id);}
function fs_allowed(string $id): bool {$m=fs_member($id);return $m!==null&&!empty($m['file_share_access'])&&empty($m['suspended']);}
function fs_generation(string $id): int {$m=fs_member($id);return (int)($m['file_share_generation']??0);}
function fs_token(): string {return bin2hex(random_bytes(24));}
function fs_valid_id(string $id): bool {return (bool)preg_match('/\A[a-f0-9]{48}\z/D',$id);}
function fs_cleanup_db(array &$db,bool &$changed): void {
 $now=time();$p=fs_paths();
 foreach($db['shares'] as $id=>$s){if(!fs_valid_id((string)$id))continue;if((int)($s['expires']??0)>$now)continue;$file=$p['files'].'/'.$id;if(!is_file($file)||@unlink($file)){unset($db['shares'][$id]);$changed=true;}}
 foreach($db['uploads'] as $id=>$u){if(!fs_valid_id((string)$id))continue;if((int)($u['created']??0)+FS_UPLOAD_TTL>$now)continue;$file=$p['chunks'].'/'.$id;if(!is_file($file)||@unlink($file)){unset($db['uploads'][$id]);$changed=true;}}
}
function fs_used(array $db): int {$n=0;foreach($db['shares'] as $s)$n+=(int)($s['size']??0);foreach($db['uploads'] as $u)$n+=(int)($u['size']??0);return $n;}
function fs_locked(callable $fn) {
 $p=fs_paths();$lock=@fopen($p['lock'],'c');if(!$lock)throw new RuntimeException('Storage lock unavailable');
 try {if(!flock($lock,LOCK_EX))throw new RuntimeException('Storage lock failed');
  $raw=is_file($p['db'])?@file_get_contents($p['db']):'';
  if($raw===false)throw new RuntimeException('Metadata read failed');
  if($raw==='')$db=['shares'=>[],'uploads'=>[]];else{$db=json_decode($raw,true);if(!is_array($db)||!isset($db['shares'],$db['uploads'])||!is_array($db['shares'])||!is_array($db['uploads']))throw new RuntimeException('Metadata invalid');}
  $changed=false;$result=$fn($db,$changed);
  if($changed){$tmp=$p['root'].'/.shares.'.fs_token();$json=json_encode($db,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);if(@file_put_contents($tmp,$json)===false){@unlink($tmp);throw new RuntimeException('Metadata write failed');}if(!@rename($tmp,$p['db'])){@unlink($tmp);throw new RuntimeException('Metadata rename failed');}}
  return $result;
 }finally{flock($lock,LOCK_UN);fclose($lock);}
}
