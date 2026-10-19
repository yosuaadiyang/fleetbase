<?php
require '/fleetbase/api/vendor/autoload.php';
$app=require '/fleetbase/api/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\{DB,Crypt};
use Illuminate\Http\Request;
if(getenv('CONTRUST_RELEASE_QA')!=='synthetic-only'||DB::connection()->getDatabaseName()!=='contrust_release_qa'||config('mail.default')!=='log')throw new RuntimeException('Unsafe diagnostic QA');
$checks=[];
foreach(['config','status','clear','compile'] as $action){
 foreach(['normal'=>[],'cf_loopback'=>['CF-Connecting-IP: 127.0.0.1'],'forwarded_loopback'=>['X-Forwarded-For: 127.0.0.1']] as $name=>$extra){
  $options=['http'=>['method'=>'GET','timeout'=>15,'ignore_errors'=>true,'header'=>implode("\r\n",['Accept: application/json','Connection: close',...$extra])]];
  $raw=@file_get_contents('http://127.0.0.1:8000/opcache-api/'.$action,false,stream_context_create($options));preg_match('/\s(\d{3})\s/',$http_response_header[0]??'',$m);$code=(int)($m[1]??0);
  $checks[]=['name'=>$action.' rejects unauthenticated '.$name,'passed'=>$raw!==false&&$code===403,'http'=>$code];
 }
}
$middleware=app(Appstract\Opcache\Http\Middleware\Request::class);
$checks[]=['name'=>'router resolves hardened middleware','passed'=>$middleware instanceof App\Http\Middleware\AuthenticatedOpcacheRequest];
foreach(['invalid'=>'not-an-encrypted-token','array'=>['invalid'],'authenticated_wrong_payload'=>Crypt::encrypt('not-opcache'),'authenticated_array'=>Crypt::encrypt(['opcache'])] as $name=>$key){
 try{$middleware->handle(Request::create('/opcache-api/config','GET',['key'=>$key]),fn()=>response()->json(['unexpected'=>true]));$denied=false;}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){$denied=$e->getStatusCode()===403;}
 $checks[]=['name'=>'invalid diagnostic credential '.$name.' rejected','passed'=>$denied];
}
$valid=$middleware->handle(Request::create('/opcache-api/config','GET',['key'=>Crypt::encrypt('opcache')]),fn()=>response()->json(['authenticated'=>true]));
$checks[]=['name'=>'existing authenticated CLI token remains compatible without executing a cache operation','passed'=>$valid->status()===200];
$checks[]=['name'=>'CLI diagnostics default to loopback instead of public edge','passed'=>config('opcache.url')==='http://127.0.0.1:8000'];
$failed=count(array_filter($checks,fn($c)=>!$c['passed']));echo json_encode(['environment'=>'isolated-synthetic-mysql','checks'=>$checks,'passed'=>count($checks)-$failed,'failed'=>$failed,'production_changed'=>false,'credential_values_printed'=>false,'authenticated_cache_mutations_executed'=>false],JSON_PRETTY_PRINT),"\n";exit($failed?1:0);
