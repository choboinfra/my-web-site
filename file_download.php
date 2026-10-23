<?php
declare(strict_types=1);
require_once __DIR__.'/file_share_lib.php';
$id=(string)($_GET['t']??'');if(!fs_valid_id($id)){http_response_code(404);exit('파일을 찾을 수 없습니다.');}
try{$share=fs_locked(function(&$db,&$changed)use($id){fs_cleanup_db($db,$changed);$s=$db['shares'][$id]??null;if(!$s||!empty($s['blocked'])||(int)($s['expires']??0)<=time()||!fs_allowed((string)$s['owner'])||(int)($s['generation']??-1)!==fs_generation((string)$s['owner']))return null;return $s;});}catch(Throwable $e){http_response_code(503);exit('현재 서비스를 이용할 수 없습니다.');}
if(!$share){http_response_code(404);exit('공유 링크가 만료되었거나 사용할 수 없습니다.');}
$path=fs_paths()['files'].'/'.$id;$fp=@fopen($path,'rb');if(!$fp){http_response_code(404);exit('파일을 사용할 수 없습니다.');}
$stat=fstat($fp);if(!$stat||$stat['size']!==(int)$share['size']){fclose($fp);http_response_code(503);exit('파일을 사용할 수 없습니다.');}
// Counts successful file opens, not necessarily completed transfers.
try{fs_locked(function(&$db,&$changed)use($id){if(isset($db['shares'][$id])){$db['shares'][$id]['downloads']=(int)($db['shares'][$id]['downloads']??0)+1;$changed=true;}});}catch(Throwable $e){fclose($fp);http_response_code(503);exit('현재 서비스를 이용할 수 없습니다.');}
$name=str_replace(["\r","\n",'"','\\'],['_','_','_','_'],(string)$share['name']);
header('Content-Type: application/octet-stream');header('X-Content-Type-Options: nosniff');header('Content-Disposition: attachment; filename="download"; filename*=UTF-8\'\''.rawurlencode($name));header('Content-Length: '.$stat['size']);header('Cache-Control: private, no-store');
while(!feof($fp)){ $part=fread($fp,1024*1024);if($part===false||$part==='')break;echo $part;flush();if(connection_aborted())break;}fclose($fp);
