<?php
declare(strict_types=1);
require_once __DIR__.'/lib/members_lib.php';
require_once __DIR__.'/file_share_lib.php';
if(($_SERVER['REQUEST_METHOD']??'')!=='POST')fs_json(['error'=>'허용되지 않는 요청 방식입니다.'],405);
$m=site_current_member();if(!$m||empty($m['file_share_access']))fs_json(['error'=>'로그인 및 관리자 업로드 승인이 필요합니다.'],403);
if(!site_valid_csrf())fs_json(['error'=>'보안 토큰이 유효하지 않습니다.'],403);
$owner=(string)$m['id'];$action=(string)($_POST['action']??'');
try {
 if($action==='start'){
  $size=filter_var($_POST['size']??null,FILTER_VALIDATE_INT);$name=trim((string)($_POST['name']??''));
  if($size===false||$size<1||$size>FS_MAX_FILE||$name==='')fs_json(['error'=>'파일 이름 또는 크기가 올바르지 않습니다.'],400);
  $name=mb_substr(basename(str_replace('\\','/',$name)),0,180);if($name==='')fs_json(['error'=>'파일 이름이 올바르지 않습니다.'],400);
  $out=fs_locked(function(&$db,&$changed)use($owner,$size,$name){fs_cleanup_db($db,$changed);if(!fs_allowed($owner))throw new RuntimeException('업로드 권한이 회수되었습니다.');if(fs_used($db)+$size>FS_MAX_TOTAL)throw new RuntimeException('전체 저장 공간 5GB를 초과했습니다.');$id=fs_token();$f=@fopen(fs_paths()['chunks'].'/'.$id,'x');if(!$f)throw new RuntimeException('업로드 임시 파일을 만들 수 없습니다.');fclose($f);$db['uploads'][$id]=['owner'=>$owner,'generation'=>fs_generation($owner),'size'=>$size,'received'=>0,'name'=>$name,'created'=>time()];$changed=true;return ['upload_id'=>$id,'chunk_size'=>FS_CHUNK_MAX];});fs_json($out);
 }
 if($action==='status'||$action==='chunk'||$action==='finish'){
  $id=(string)($_POST['upload_id']??'');if(!fs_valid_id($id))fs_json(['error'=>'업로드 ID가 올바르지 않습니다.'],400);
  if($action==='status'){$out=fs_locked(function(&$db,&$changed)use($id,$owner){fs_cleanup_db($db,$changed);$u=$db['uploads'][$id]??null;if(!$u||$u['owner']!==$owner||!fs_allowed($owner)||(int)($u['generation']??-1)!==fs_generation($owner))throw new RuntimeException('업로드 정보를 찾을 수 없거나 권한이 없습니다.');return ['received'=>(int)$u['received'],'size'=>(int)$u['size'],'name'=>(string)$u['name']];});fs_json($out);}
  if($action==='chunk'){
   $offset=filter_var($_POST['offset']??null,FILTER_VALIDATE_INT);$f=$_FILES['chunk']??null;
   if($offset===false||$offset<0||!is_array($f)||($f['error']??1)!==UPLOAD_ERR_OK||!is_uploaded_file($f['tmp_name']))fs_json(['error'=>'전송 조각이 올바르지 않습니다.'],400);
   $len=(int)$f['size'];if($len<1||$len>FS_CHUNK_MAX)fs_json(['error'=>'전송 조각 크기가 올바르지 않습니다.'],400);
   $out=fs_locked(function(&$db,&$changed)use($id,$offset,$owner,$f,$len){fs_cleanup_db($db,$changed);$u=$db['uploads'][$id]??null;if(!$u||$u['owner']!==$owner||!fs_allowed($owner)||(int)($u['generation']??-1)!==fs_generation($owner))throw new RuntimeException('업로드 정보를 찾을 수 없거나 권한이 없습니다.');
    // Idempotent retry: client can check status after an ambiguous network failure.
    if((int)$u['received']!==$offset||$offset+$len>(int)$u['size'])throw new RuntimeException('전송 위치가 일치하지 않습니다.');
    $src=@fopen($f['tmp_name'],'rb');$dst=@fopen(fs_paths()['chunks'].'/'.$id,'c+b');if(!$src||!$dst){if($src)fclose($src);if($dst)fclose($dst);throw new RuntimeException('전송 조각 파일을 열 수 없습니다.');}
    try{if(fseek($dst,$offset)!==0)throw new RuntimeException('파일 위치 이동에 실패했습니다.');$copied=stream_copy_to_stream($src,$dst,$len);if($copied!==$len||!fflush($dst))throw new RuntimeException('전송 조각 저장에 실패했습니다.');}finally{fclose($src);fclose($dst);}
    $db['uploads'][$id]['received']+=$len;$changed=true;return ['received'=>(int)$db['uploads'][$id]['received']];});fs_json($out);
  }
  $out=fs_locked(function(&$db,&$changed)use($id,$owner){fs_cleanup_db($db,$changed);$u=$db['uploads'][$id]??null;
   if(!$u){$s=$db['shares'][$id]??null;if($s&&$s['owner']===$owner&&fs_allowed($owner)&&(int)($s['generation']??-1)===fs_generation($owner)&&empty($s['blocked'])&&(int)$s['expires']>time())return ['url'=>'/file_download.php?t='.$id,'expires'=>$s['expires']];throw new RuntimeException('업로드 정보를 찾을 수 없거나 권한이 없습니다.');}
   if($u['owner']!==$owner||!fs_allowed($owner)||(int)($u['generation']??-1)!==fs_generation($owner)||(int)$u['received']!==(int)$u['size'])throw new RuntimeException('업로드가 완료되지 않았거나 권한이 없습니다.');
   $src=fs_paths()['chunks'].'/'.$id;$dst=fs_paths()['files'].'/'.$id;if(!is_file($src)||filesize($src)!==(int)$u['size']||file_exists($dst)||!@rename($src,$dst))throw new RuntimeException('업로드 완료 처리에 실패했습니다.');
   $now=time();$db['shares'][$id]=['owner'=>$owner,'generation'=>(int)$u['generation'],'name'=>$u['name'],'size'=>$u['size'],'created'=>$now,'expires'=>$now+FS_TTL,'downloads'=>0,'blocked'=>false];unset($db['uploads'][$id]);$changed=true;return ['url'=>'/file_download.php?t='.$id,'expires'=>$now+FS_TTL];});fs_json($out);
 }
 fs_json(['error'=>'알 수 없는 작업입니다.'],400);
}catch(Throwable $e){fs_json(['error'=>$e->getMessage()],400);}
