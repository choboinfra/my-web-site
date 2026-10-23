<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/file_share_lib.php';
try{$out=fs_locked(function(&$db,&$changed){$a=count($db['shares']);$b=count($db['uploads']);fs_cleanup_db($db,$changed);return ['expired_shares'=>$a-count($db['shares']),'expired_uploads'=>$b-count($db['uploads'])];});echo json_encode($out,JSON_THROW_ON_ERROR).PHP_EOL;}catch(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}
