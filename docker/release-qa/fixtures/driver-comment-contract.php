<?php
require '/fleetbase/api/vendor/autoload.php';
$app=require '/fleetbase/api/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Fleetbase\FleetOps\Models\{Driver,Order};use Fleetbase\Models\{Comment,User};use Illuminate\Support\Facades\DB;
if(getenv('CONTRUST_RELEASE_QA')!=='synthetic-only'||DB::connection()->getDatabaseName()!=='contrust_release_qa'||config('mail.default')!=='log')throw new RuntimeException('Unsafe comment QA environment');
$state=json_decode(file_get_contents(storage_path('app/release-qa/credentials.json')),true,512,JSON_THROW_ON_ERROR);$checks=[];$tokens=[];$tokenIds=[];$created=[];
function commCheck($name,$ok,$code=null){global $checks;$checks[]=['name'=>$name,'passed'=>(bool)$ok,'http'=>$code];}
function commCall($method,$path,$body=null,$actor=null){global $tokens;$h=['Accept: application/json','Content-Type: application/json','Connection: close'];if($actor!==null)$h[]='Authorization: Bearer '.$tokens[$actor];$o=['method'=>$method,'ignore_errors'=>true,'timeout'=>15,'header'=>implode("\r\n",$h)];if($body!==null)$o['content']=json_encode($body);$raw=@file_get_contents('http://127.0.0.1:8000'.$path,false,stream_context_create(['http'=>$o]));if($raw===false)throw new RuntimeException('Synthetic comment transport failed');preg_match('/\s(\d{3})\s/',$http_response_header[0]??'',$m);return[(int)($m[1]??0),json_decode($raw,true),$raw];}
try{
 foreach(['driver_a','driver_a_other','driver_b'] as $actor){$u=User::findOrFail($state['users'][$actor]['uuid']);$d=Driver::where('user_uuid',$u->uuid)->firstOrFail();$t=$u->createToken($d->uuid);$tokens[$actor]=$t->plainTextToken;$tokenIds[]=$t->accessToken->id;}
 $driver=Driver::where('public_id',$state['users']['driver_a']['driver_public_id'])->firstOrFail();
 $order=Order::where('company_uuid',$driver->company_uuid)->where('driver_assigned_uuid',$driver->uuid)->where('status','completed')->latest()->firstOrFail();
 $subject=['id'=>$order->public_id,'type'=>'order'];
 [$c,$data]=commCall('POST','/v1/comments',['content'=>'DEMO persisted root','subject'=>$subject],'driver_a');$id=$data['id']??null;if(is_string($id))$created[]=$id;
 commCheck('assigned driver creates a confirmed server comment on completed order',$c===201&&is_string($id),$c);
 if(!$id)throw new RuntimeException('Synthetic root comment missing');
 commCheck('new comment exists in real database',Comment::where('public_id',$id)->where('subject_uuid',$order->uuid)->where('content','DEMO persisted root')->exists());
 commCheck('author derives from authenticated driver',($data['author']['id']??null)===$state['users']['driver_a']['public_id']);
 commCheck('driver serializer omits author contact and tenant metadata',!array_intersect(['company_uuid','subject_uuid'],array_keys($data))&&!array_intersect(['email','phone'],array_keys($data['author']??[])));
 [$c,,$raw]=commCall('GET','/v1/orders/'.$order->public_id.'/comments',null,'driver_a');commCheck('fresh assigned-order read retains saved comment',$c===200&&str_contains($raw,$id),$c);
 [$c]=commCall('GET','/v1/comments/'.$id,null,'driver_a');commCheck('assigned driver may find scoped comment',$c===200,$c);
 foreach(['driver_a_other','driver_b'] as $actor){
  [$c]=commCall('GET','/v1/comments/'.$id,null,$actor);commCheck($actor.' cannot read assigned driver comment',$c===403,$c);
  [$c]=commCall('POST','/v1/comments',['content'=>'not permitted','subject'=>$subject],$actor);commCheck($actor.' cannot comment on foreign work',$c===403,$c);
  [$c]=commCall('POST','/v1/comments',['content'=>'not permitted','parent'=>$id],$actor);commCheck($actor.' cannot reply through known parent ID',$c===403,$c);
  [$c]=commCall('PUT','/v1/comments/'.$id,['content'=>'not permitted'],$actor);commCheck($actor.' cannot edit foreign comment',$c===403,$c);
  [$c]=commCall('DELETE','/v1/comments/'.$id,null,$actor);commCheck($actor.' cannot delete foreign comment',$c===403,$c);
 }
 [$c]=commCall('GET','/v1/comments',null,'driver_a');commCheck('generic comment query cannot widen order scope',$c===403,$c);
 [$c]=commCall('POST','/v1/comments',['content'=>'anonymous','subject'=>$subject]);commCheck('anonymous comment creation denied',$c===401,$c);
 [$c]=commCall('POST','/v1/comments',['content'=>'spoofed','subject'=>$subject,'author_uuid'=>$state['users']['driver_b']['uuid']],'driver_a');commCheck('client cannot spoof author metadata',$c===403,$c);
 [$c]=commCall('POST','/v1/comments',['content'=>'bad type','subject'=>['id'=>$order->public_id,'type'=>'user']],'driver_a');commCheck('non-order polymorphic subject rejected',$c===422,$c);
 foreach(['','x',str_repeat('a',4001),['array']] as $n=>$content){[$c]=commCall('POST','/v1/comments',['content'=>$content,'subject'=>$subject],'driver_a');commCheck('invalid content case '.$n.' rejected',$c===422,$c);}
 [$c,$data]=commCall('POST','/v1/comments',['content'=>'<b>Safe plain text</b>','parent'=>$id],'driver_a');$reply=$data['id']??null;if($reply)$created[]=$reply;
 commCheck('assigned-order reply persists and HTML is plain text',$c===201&&($data['content']??null)==='Safe plain text',$c);
 [$c]=commCall('PUT','/v1/comments/'.$id,['content'=>'DEMO updated root'],'driver_a');commCheck('author can update own comment',$c===200,$c);
 [$c]=commCall('DELETE','/v1/comments/'.$id,null,'driver_a');commCheck('parent with replies is protected from destructive deletion',$c===422,$c);
 [$c,,$raw]=commCall('GET','/v1/orders/'.$order->public_id.'/comments',null,'driver_a');commCheck('reload includes updated root and reply',$c===200&&str_contains($raw,'DEMO updated root')&&str_contains($raw,$reply??'NEVER'),$c);
 $original=$order->driver_assigned_uuid;
 try{$order->forceFill(['driver_assigned_uuid'=>null])->saveQuietly();[$c]=commCall('GET','/v1/comments/'.$id,null,'driver_a');commCheck('reassignment immediately revokes comment access',$c===403,$c);}
 finally{$order->forceFill(['driver_assigned_uuid'=>$original])->saveQuietly();}
 [$c]=commCall('DELETE','/v1/comments/'.$reply,null,'driver_a');commCheck('author may delete own leaf reply',$c===200,$c);
 [$c]=commCall('DELETE','/v1/comments/'.$id,null,'driver_a');commCheck('author may delete own empty root',$c===200,$c);
}finally{
 foreach($created as $id)DB::table('comments')->where('public_id',$id)->delete();
 if($tokenIds)DB::table('personal_access_tokens')->whereIn('id',$tokenIds)->delete();
}
$failed=count(array_filter($checks,fn($c)=>!$c['passed']));echo json_encode(['environment'=>'isolated-synthetic-mysql','checks'=>$checks,'passed'=>count($checks)-$failed,'failed'=>$failed,'credentials_or_comment_content_printed'=>false],JSON_PRETTY_PRINT),PHP_EOL;exit($failed?1:0);
