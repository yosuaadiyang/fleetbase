<?php
require '/fleetbase/api/vendor/autoload.php';
$app=require '/fleetbase/api/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Fleetbase\FleetOps\Models\{Driver,Issue,FuelReport};
use Fleetbase\Models\User;
use Illuminate\Support\Facades\DB;
if(getenv('CONTRUST_RELEASE_QA')!=='synthetic-only'||DB::connection()->getDatabaseName()!=='contrust_release_qa'||config('mail.default')!=='log'||config('broadcasting.default')!=='log')throw new RuntimeException('Unsafe report QA');
$s=json_decode(file_get_contents(storage_path('app/release-qa/credentials.json')),true,512,JSON_THROW_ON_ERROR);
$checks=[];$tokens=[];$tokenIds=[];$created=[];
function rc($name,$ok,$http=null){global $checks;$checks[]=['name'=>$name,'passed'=>(bool)$ok,'http'=>$http];}
function callReport($method,$path,$body=null,$actor='driver_a'){
 global $tokens;$headers=['Accept: application/json','Content-Type: application/json','Connection: close'];if($actor!==null)$headers[]='Authorization: Bearer '.$tokens[$actor];
 $o=['method'=>$method,'ignore_errors'=>true,'timeout'=>20,'header'=>implode("\r\n",$headers)];if($body!==null)$o['content']=json_encode($body);
 $raw=@file_get_contents('http://127.0.0.1:8000/v1/'.$path,false,stream_context_create(['http'=>$o]));
 if($raw===false)throw new RuntimeException('Synthetic report transport');preg_match('/\s(\d{3})\s/',$http_response_header[0]??'',$m);
 return [(int)($m[1]??0),json_decode($raw,true),$http_response_header];
}
try{
 foreach(['driver_a','driver_a_other','driver_b','staff_driver_a'] as $a){$u=User::findOrFail($s['users'][$a]['uuid']);$d=Driver::where('user_uuid',$u->uuid)->firstOrFail();$t=$u->createToken($d->uuid);$tokens[$a]=$t->plainTextToken;$tokenIds[]=$t->accessToken->id;}
 $tokens['bootstrap']=$s['bootstrap']['a'];
 foreach(['issues'=>Issue::class,'fuel-reports'=>FuelReport::class] as $path=>$model){
  $body=$path==='issues'?['type'=>'vehicle','category'=>'Mechanical Problems','priority'=>'low','status'=>'pending','report'=>'Synthetic report']:['odometer'=>'0','volume'=>'5.5','amount'=>1000000,'currency'=>'IDR','metric_unit'=>'L','status'=>'draft'];
  $body['location']=['type'=>'Point','coordinates'=>[106.8272,-6.1754]];
  foreach([null,'bootstrap'] as $actor){[$c]=callReport('POST',$path,$body,$actor);rc($path.' denied actor '.($actor??'anonymous'),$c===($actor===null?401:403),$c);}
  [$c,$saved,$headers]=callReport('POST',$path,$body);$id=$saved['id']??null;rc($path.' confirmed create with ID',$c===201&&is_string($id),$c);if(!$id)throw new RuntimeException('Synthetic report not saved');$created[]=[$model,$id];
  $r=$model::withoutCache()->where('public_id',$id)->firstOrFail();$d=Driver::where('user_uuid',$s['users']['driver_a']['uuid'])->firstOrFail();
  rc($path.' ownership derived on server',$r->driver_uuid===$d->uuid&&$r->company_uuid===$d->company_uuid&&$r->reported_by_uuid===$d->user_uuid);
  rc($path.' sensitive fields omitted',!array_intersect(['company_uuid','driver_uuid','reported_by_uuid','meta','provider','reporter'],array_keys($saved)));
  rc($path.' no shared caching',str_contains(strtolower(implode(' ',$headers)),'no-store'));
  [$c,$v]=callReport('GET',$path.'/'.$id);rc($path.' fresh read persists',$c===200&&($v['id']??null)===$id,$c);
  [$c,$list]=callReport('GET',$path.'?driver='.$d->public_id.'&sort=-created_at');rc($path.' scoped mobile list includes new record',$c===200&&in_array($id,array_column($list,'id'),true),$c);
  foreach(['driver_a_other','driver_b','staff_driver_a'] as $actor){
   foreach(['GET','PUT','DELETE'] as $method){[$c]=callReport($method,$path.'/'.$id,$method==='PUT'?['status'=>'draft']:null,$actor);rc($path.' '.$actor.' denied '.$method.' other report',$c===403,$c);}
   [$c,$list]=callReport('GET',$path,null,$actor);rc($path.' '.$actor.' list excludes other report',$c===200&&!in_array($id,array_column($list,'id'),true),$c);
  }
  foreach(['company_uuid'=>$s['companies']['b']['uuid'],'reported_by_uuid'=>$s['users']['driver_b']['uuid'],'driver'=>$s['users']['driver_b']['driver_public_id'],'vehicle_uuid'=>'foreign','meta'=>['provider'=>'fake']] as $field=>$value){[$c]=callReport('POST',$path,array_replace($body,[$field=>$value]));rc($path.' spoof '.$field.' denied',$c===403,$c);}
  [$c]=callReport('GET',$path.'?company_uuid='.$s['companies']['b']['uuid']);rc($path.' forged filter denied',$c===403,$c);
  foreach([['type'=>'Point','coordinates'=>[200,0]],['type'=>'Point','coordinates'=>[0,100]],['type'=>'Point','coordinates'=>['bad',0]],'POINT(0 0)',null] as $n=>$loc){[$c]=callReport('POST',$path,array_replace($body,['location'=>$loc]));rc($path.' invalid GPS '.$n.' rejected',$c===422,$c);}
  if($path==='issues'){
   foreach(['report'=>'   ','type'=>'not_type','priority'=>'root','report_array'=>[]] as $field=>$value){$b=$body;$b[$field==='report_array'?'report':$field]=$value;[$c]=callReport('POST',$path,$b);rc($path.' invalid '.$field.' rejected',$c===422,$c);}
   [$c]=callReport('PUT',$path.'/'.$id,['report'=>'Updated synthetic report','status'=>'in_progress']);rc($path.' own issue edit confirmed',$c===200,$c);
  }else{
   foreach(['volume'=>0,'odometer'=>-1,'amount'=>-1,'currency'=>'BADCODE','metric_unit'=>'kg','status'=>'approved'] as $field=>$value){[$c]=callReport('POST',$path,array_replace($body,[$field=>$value]));rc($path.' invalid '.$field.' rejected',$c===422,$c);}
   [$c]=callReport('PUT',$path.'/'.$id,['status'=>'submitted']);rc($path.' draft expense can submit',$c===200,$c);
   foreach(['PUT','DELETE'] as $method){[$c]=callReport($method,$path.'/'.$id,$method==='PUT'?['amount'=>100]:null);rc($path.' submitted report immutable for driver '.$method,$c===403,$c);}
   $r->refresh()->forceFill(['status'=>'approved'])->saveQuietly();
   foreach(['PUT','DELETE'] as $method){[$c]=callReport($method,$path.'/'.$id,$method==='PUT'?['amount'=>100]:null);rc($path.' staff-approved report immutable for driver '.$method,$c===403,$c);}
   $r->refresh()->forceFill(['status'=>'draft'])->saveQuietly();
  }
  $keyed=$body;$keyed['submission_id']='b8eb358f-e86d-41db-b873-4ce28c544ea6';
  $before=$model::withoutCache()->where('driver_uuid',$d->uuid)->count();
  [$c,$original]=callReport('POST',$path,$keyed);$keyedId=$original['id']??null;
  rc($path.' keyed create confirmed',$c===201&&is_string($keyedId),$c);if(!$keyedId)throw new RuntimeException('Keyed synthetic report not saved');$created[]=[$model,$keyedId];
  [$c,$replay,$replayHeaders]=callReport('POST',$path,$keyed);
  rc($path.' lost response retry returns original ID',$c===201&&($replay['id']??null)===$keyedId,$c);
  rc($path.' replay header confirmed',str_contains(strtolower(implode(' ',$replayHeaders)),'idempotency-replayed: true'));
  rc($path.' retry does not insert duplicate',$model::withoutCache()->where('driver_uuid',$d->uuid)->count()===$before+1);
  rc($path.' replay hides submission metadata',!isset($replay['meta'])&&!isset($replay['submission_id']));
  [$c]=callReport('POST',$path,array_replace($keyed,$path==='issues'?['report'=>'Different synthetic body']:['volume'=>'9']));rc($path.' reused key with changed payload rejected',$c===409,$c);
  foreach([null,'not-a-uuid',[],str_repeat('x',256)] as $n=>$bad){[$c]=callReport('POST',$path,array_replace($keyed,['submission_id'=>$bad]));rc($path.' malformed key rejected '.$n,$c===422,$c);}
  [$c,$other]=callReport('POST',$path,$keyed,'driver_a_other');$otherId=$other['id']??null;if($otherId)$created[]=[$model,$otherId];rc($path.' same key different own-org driver is separate',$c===201&&$otherId&&$otherId!==$keyedId,$c);
  [$c,$otherTenant]=callReport('POST',$path,$keyed,'driver_b');$tenantId=$otherTenant['id']??null;if($tenantId)$created[]=[$model,$tenantId];rc($path.' same key foreign tenant cannot replay',$c===201&&$tenantId&&$tenantId!==$keyedId,$c);
  [$c]=callReport('PUT',$path.'/'.$keyedId,['submission_id'=>$keyed['submission_id']]);rc($path.' submission metadata cannot be rewritten',$c===403,$c);
  [$c]=callReport('DELETE',$path.'/'.$keyedId);rc($path.' keyed report delete succeeds',$c===200,$c);
  [$c]=callReport('POST',$path,$keyed);rc($path.' deleted operation never recreated',$c===410,$c);
  [$c]=callReport('DELETE',$path.'/'.$id);rc($path.' own editable report delete',$c===200,$c);
  [$c]=callReport('GET',$path.'/'.$id);rc($path.' deleted record unavailable',$c===403,$c);
 }
 $membership=Fleetbase\Models\CompanyUser::where('company_uuid',$s['companies']['a']['uuid'])->where('user_uuid',$s['users']['driver_a']['uuid'])->firstOrFail();
 $oldStatus=$membership->status;
 try{$membership->forceFill(['status'=>'inactive'])->saveQuietly();foreach(['issues','fuel-reports'] as $path){[$c]=callReport('GET',$path);rc($path.' revoked organization membership denied',$c===403,$c);}}
 finally{$membership->forceFill(['status'=>$oldStatus])->saveQuietly();}
}finally{foreach($created as [$model,$id])$model::withTrashed()->where('public_id',$id)->forceDelete();if($tokenIds)DB::table('personal_access_tokens')->whereIn('id',$tokenIds)->delete();}
$failed=count(array_filter($checks,fn($c)=>!$c['passed']));echo json_encode(['environment'=>'isolated-synthetic-mysql','checks'=>$checks,'passed'=>count($checks)-$failed,'failed'=>$failed,'external_notifications_sent'=>false,'credentials_or_report_content_printed'=>false],JSON_PRETTY_PRINT),PHP_EOL;exit($failed?1:0);
