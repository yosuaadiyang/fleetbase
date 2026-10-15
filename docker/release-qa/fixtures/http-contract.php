<?php
require '/fleetbase/api/vendor/autoload.php';
$app=require '/fleetbase/api/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
if(getenv('CONTRUST_RELEASE_QA')!=='synthetic-only'||DB::connection()->getDatabaseName()!=='contrust_release_qa'||config('mail.default')!=='log')throw new RuntimeException('Unsafe HTTP contract environment');
$state=json_decode(file_get_contents(storage_path('app/release-qa/credentials.json')),true,512,JSON_THROW_ON_ERROR);
$checks=[];$tokens=[];
function callQa($method,$path,$body=null,$actor=null){
 global $tokens;
 $headers=['Accept: application/json','Content-Type: application/json','Connection: close'];
 if($actor!==null)$headers[]='Authorization: Bearer '.$tokens[$actor];
 $options=['method'=>$method,'header'=>implode("\r\n",$headers),'ignore_errors'=>true,'timeout'=>15];
 if($body!==null)$options['content']=json_encode($body,JSON_THROW_ON_ERROR);
 $raw=@file_get_contents('http://127.0.0.1:8000'.$path,false,stream_context_create(['http'=>$options]));
 if($raw===false)throw new RuntimeException('QA request failed: '.$method.' '.$path);
 preg_match('/\s(\d{3})\s/',$http_response_header[0]??'',$m);
 return [(int)($m[1]??0),json_decode($raw,true),$raw];
}
function checkQa($name,$ok,$status=null){global $checks;$checks[]=['name'=>$name,'passed'=>(bool)$ok,'http'=>$status];}
function resourceQa($data,$name){return is_array($data[$name]??null)?$data[$name]:$data;}
function publicIdQa($data){return $data['public_id']??$data['uuid']??$data['id']??null;}
foreach(['owner_a','owner_b','viewer_a','dispatcher_a'] as $actor){
 [$status,$data]=callQa('POST','/int/v1/auth/login',array_intersect_key($state['users'][$actor],array_flip(['identity','password'])));
 checkQa($actor.' authenticates through real login',$status===200&&is_string($data['token']??null),$status);
 if($status!==200||!is_string($data['token']??null)){echo json_encode(['checks'=>$checks,'failed'=>true]),PHP_EOL;exit(1);}
 $tokens[$actor]=$data['token'];
}
[$status]=callQa('GET','/int/v1/places');checkQa('anonymous private places rejected',$status===401,$status);
$places=[];
foreach(['owner_a','owner_b'] as $actor){
 $body=['place'=>['name'=>'Release QA PRIVATE '.$actor,'street1'=>'Public landmark test only','city'=>'Jakarta Pusat','country'=>'ID','location'=>['type'=>'Point','coordinates'=>[106.8272,-6.1754]]]];
 [$status,$data]=callQa('POST','/int/v1/places',$body,$actor);$place=resourceQa($data,'place');
 checkQa($actor.' can create own place',in_array($status,[200,201])&&is_string(publicIdQa($place)),$status);
 if(!in_array($status,[200,201])||!publicIdQa($place)){echo json_encode(['checks'=>$checks,'response_keys'=>array_keys($data??[]),'failed'=>true]),PHP_EOL;exit(1);}
 $places[$actor]=$place;
}
$a=publicIdQa($places['owner_a']);$b=publicIdQa($places['owner_b']);
foreach([['owner_a',$a,'owner_a'],['owner_b',$b,'owner_b'],['viewer_a',$a,'owner_a']] as [$actor,$id,$marker]){
 [$status,$data,$raw]=callQa('GET','/int/v1/places/'.$id,null,$actor);
 checkQa($actor.' can read permitted place',$status===200&&str_contains(strtolower($raw),strtolower('Release QA PRIVATE '.$marker)),$status);
}
foreach([['owner_a',$b,'owner_b'],['owner_b',$a,'owner_a']] as [$actor,$id,$marker]){
 [$status,$data,$raw]=callQa('GET','/int/v1/places/'.$id,null,$actor);
 checkQa($actor.' cannot read other organization place',in_array($status,[403,404])&&!str_contains(strtolower($raw),strtolower('Release QA PRIVATE '.$marker)),$status);
 [$status,$data,$raw]=callQa('GET','/int/v1/places?company_uuid='.$state['users'][$marker]['company_uuid'],null,$actor);
 checkQa($actor.' cannot override list company scope',!str_contains(strtolower($raw),strtolower('Release QA PRIVATE '.$marker))&&$status!==500,$status);
}
foreach([['POST','/int/v1/places',['place'=>['name'=>'Viewer forbidden create','location'=>['type'=>'Point','coordinates'=>[106.82,-6.17]]]]],['PATCH','/int/v1/places/'.$a,['place'=>['name'=>'Viewer forbidden edit']]],['DELETE','/int/v1/places/'.$a,null]] as [$method,$path,$body]){
 [$status]=callQa($method,$path,$body,'viewer_a');checkQa('viewer cannot '.$method.' place',$status===403,$status);
}
[$status]=callQa('PATCH','/int/v1/places/'.$b,['place'=>['name'=>'Cross tenant forbidden edit']],'owner_a');checkQa('owner cannot update other organization place',in_array($status,[400,403,404]),$status);
[$status,$data,$raw]=callQa('GET','/int/v1/places/'.$b,null,'owner_b');checkQa('rejected writes leave other organization record unchanged',$status===200&&str_contains(strtolower($raw),'release qa private owner_b'),$status);
[$status,$data]=callQa('POST','/int/v1/places',['place'=>['name'=>'Release QA attempted company override','company_uuid'=>$state['users']['owner_b']['company_uuid'],'location'=>['type'=>'Point','coordinates'=>[106.83,-6.18]]]],'owner_a');
$injected=resourceQa($data,'place');$id=publicIdQa($injected);
$injectionSafe=in_array($status,[403,422]);
if(in_array($status,[200,201])&&$id){$row=Fleetbase\FleetOps\Models\Place::withoutGlobalScopes()->where('public_id',$id)->orWhere('uuid',$id)->first();$injectionSafe=$row&&$row->company_uuid===$state['users']['owner_a']['company_uuid'];}
checkQa('create cannot inject other organization ownership',$injectionSafe,$status);
[$status]=callQa('POST','/int/v1/auth/login',array_intersect_key($state['users']['driver_a'],array_flip(['identity','password'])));checkQa('driver cannot acquire console session',$status===403,$status);
$qaOwner=Fleetbase\Models\User::findOrFail($state['users']['owner_a']['uuid']);
$tokens['expired_fixture']=$qaOwner->createToken('release-qa-expired',['*'],now()->subMinute())->plainTextToken;
[$status]=callQa('GET','/v1/places',null,'expired_fixture');checkQa('expired user token rejected by public API',$status===401,$status);
[$status]=callQa('POST','/v1/places',['name'=>'Viewer public API forbidden create','street1'=>'Release QA synthetic landmark','city'=>'Jakarta','country'=>'ID','location'=>['type'=>'Point','coordinates'=>[106.82,-6.17]]],'viewer_a');checkQa('viewer cannot bypass write permission via public API',$status===403,$status);
[$status,$data]=callQa('POST','/v1/places',['name'=>'Release QA owner API positive control','street1'=>'Synthetic landmark','city'=>'Jakarta','country'=>'ID','location'=>['type'=>'Point','coordinates'=>[106.82,-6.17]]],'owner_a');
checkQa('owner retains permitted public API creation',in_array($status,[200,201])&&publicIdQa(resourceQa($data,'place')),$status);
[$status]=callQa('GET','/v1/places',null,'viewer_a');checkQa('viewer retains permitted public API read',$status===200,$status);
[$status]=callQa('PUT','/v1/places/'.$a,['name'=>'Forbidden API edit'],'viewer_a');checkQa('viewer public API edit rejected',$status===403,$status);
[$status]=callQa('DELETE','/v1/places/'.$a,null,'viewer_a');checkQa('viewer public API delete rejected',$status===403,$status);
foreach(['driver_a'=>'owner_a','driver_a_other'=>'owner_a','driver_b'=>'owner_b','staff_driver_a'=>'owner_a'] as $actor=>$bootstrap){
 $tenant=$actor==='driver_b'?'b':'a';
 $tokens['bootstrap_'.$tenant]=$state['bootstrap'][$tenant];
 [$status,$data]=callQa('POST','/v1/drivers/login',array_intersect_key($state['users'][$actor],array_flip(['identity','password'])),'bootstrap_'.$tenant);
 $driver=resourceQa($data,'driver');
 checkQa($actor.' authenticates through driver API',$status===200&&is_string($driver['token']??null),$status);
 if($status===200&&is_string($driver['token']??null))$tokens[$actor]=$driver['token'];
}
if(isset($tokens['driver_a'])){
 $ownDriver=$state['users']['driver_a']['driver_public_id'];
 $otherDriver=$state['users']['driver_a_other']['driver_public_id'];
 [$status]=callQa('GET','/v1/drivers/'.$ownDriver,null,'driver_a');checkQa('driver can read own profile',$status===200,$status);
 [$status]=callQa('GET','/v1/drivers/'.$otherDriver,null,'driver_a');checkQa('driver cannot read other driver profile',in_array($status,[403,404]),$status);
 [$status]=callQa('POST','/v1/places',['name'=>'Driver forbidden place create','street1'=>'Synthetic landmark','city'=>'Jakarta','country'=>'ID','location'=>['type'=>'Point','coordinates'=>[106.82,-6.17]]],'driver_a');checkQa('driver cannot create organization places',in_array($status,[403,404]),$status);
 [$status]=callQa('POST','/v1/drivers/'.$otherDriver.'/toggle-online',['online'=>true],'driver_a');checkQa('driver cannot toggle another driver online',$status===403,$status);
 [$status]=callQa('POST','/v1/drivers/'.$ownDriver.'/toggle-online',['online'=>true],'driver_a');checkQa('driver can toggle own profile online',$status===200,$status);
 [$status]=callQa('POST','/v1/drivers/'.$ownDriver.'/track',['latitude'=>-6.1755,'longitude'=>106.8273],'driver_a');checkQa('driver can report own GPS position',$status===200,$status);
 [$status]=callQa('POST','/v1/drivers/'.$otherDriver.'/track',['latitude'=>-6.1755,'longitude'=>106.8273],'driver_a');checkQa('driver cannot report another driver GPS',$status===403,$status);
 [$status]=callQa('POST','/v1/drivers/'.$ownDriver.'/track',['latitude'=>91,'longitude'=>106],'driver_a');checkQa('invalid latitude rejected before location mutation',$status===422,$status);
 [$status]=callQa('PUT','/v1/drivers/'.$ownDriver,['company_uuid'=>$state['users']['owner_b']['company_uuid']],'driver_a');checkQa('driver cannot rewrite own organization',$status===403,$status);
 $qaDriverUser=Fleetbase\Models\User::findOrFail($state['users']['driver_a']['uuid']);
 $tokens['invalid_driver_session']=$qaDriverUser->createToken('release-qa-not-a-driver-profile')->plainTextToken;
 [$status]=callQa('GET','/v1/drivers/'.$ownDriver,null,'invalid_driver_session');checkQa('driver token must bind a valid owned profile',$status===403,$status);
 $qaDriverUser->forceFill(['status'=>'inactive'])->saveQuietly();
 try {[$status]=callQa('GET','/v1/drivers/'.$ownDriver,null,'driver_a');checkQa('deactivated driver token cannot keep accessing API',$status===403,$status);}
 finally {$qaDriverUser->forceFill(['status'=>'active'])->saveQuietly();}
}
if(isset($tokens['staff_driver_a'])){
 [$status]=callQa('GET','/v1/drivers/'.$state['users']['staff_driver_a']['driver_public_id'],null,'staff_driver_a');checkQa('staff-linked driver retains own profile access',$status===200,$status);
 [$status]=callQa('GET','/v1/drivers/'.$state['users']['driver_a']['driver_public_id'],null,'staff_driver_a');checkQa('staff-linked driver token cannot read another profile',$status===403,$status);
 $staffDriverUser=Fleetbase\Models\User::findOrFail($state['users']['staff_driver_a']['uuid']);
 $tokens['stale_staff_driver']=$staffDriverUser->createToken(Illuminate\Support\Str::uuid()->toString())->plainTextToken;
 [$status]=callQa('GET','/v1/drivers/'.$state['users']['driver_a']['driver_public_id'],null,'stale_staff_driver');checkQa('missing staff driver profile cannot fall back to staff IAM',$status===403,$status);
}
[$status]=callQa('GET','/v1/places',null,'bootstrap_a');checkQa('embedded Navigator bootstrap key cannot read organization places',$status===403,$status);
[$status]=callQa('POST','/v1/places',['name'=>'Bootstrap forbidden create'],'bootstrap_a');checkQa('embedded Navigator bootstrap key cannot create organization places',$status===403,$status);
$qaApiKey=Fleetbase\Models\ApiCredential::create(['company_uuid'=>$state['users']['owner_a']['company_uuid'],'user_uuid'=>$state['users']['owner_a']['uuid'],'name'=>'Release QA key lifecycle','test_mode'=>false]);
$tokens['key_lifecycle']=$qaApiKey->fresh()->key;
[$status]=callQa('GET','/v1/places',null,'key_lifecycle');checkQa('ordinary organization API credential retains permitted reads',$status===200,$status);
$qaApiKey->forceFill(['expires_at'=>now()->subMinute()])->save();
[$status]=callQa('GET','/v1/places',null,'key_lifecycle');checkQa('expired organization API credential rejected',$status===401,$status);
$qaApiKey->forceFill(['expires_at'=>null])->save();$qaApiKey->delete();
[$status]=callQa('GET','/v1/places',null,'key_lifecycle');checkQa('deleted organization API credential revoked',$status===401,$status);
$orders=[];
foreach(['driver_a'=>'owner_a','driver_a_other'=>'owner_a','driver_b'=>'owner_b'] as $actor=>$owner){
 $tenant=$owner==='owner_a'?'a':'b';$placeId=publicIdQa($places[$owner]);$operator=$owner==='owner_a'?'dispatcher_a':$owner;
 [$status,$data]=callQa('POST','/v1/orders',['internal_id'=>'Release-QA-'.$actor,'type'=>'transport','order_config'=>$state['order_configs'][$tenant],'driver'=>$state['users'][$actor]['driver_public_id'],'pickup'=>$placeId,'dropoff'=>$placeId,'dispatch'=>false,'pod_required'=>$actor==='driver_a','pod_method'=>'photo'],$operator);
 $order=resourceQa($data,'order');$id=publicIdQa($order);
 checkQa('dispatcher creates order for '.$actor,in_array($status,[200,201])&&is_string($id),$status);
 if(in_array($status,[200,201])&&$id)$orders[$actor]=$id;
}
if(isset($tokens['driver_a'],$orders['driver_a'],$orders['driver_a_other'],$orders['driver_b'])){
 [$status,$data,$raw]=callQa('GET','/v1/orders/'.$orders['driver_a'],null,'driver_a');checkQa('driver can read own assigned order',$status===200&&str_contains($raw,$orders['driver_a']),$status);
 foreach(['driver_a_other','driver_b'] as $actor){
  [$status,$data,$raw]=callQa('GET','/v1/orders/'.$orders[$actor],null,'driver_a');checkQa('driver cannot read order assigned to '.$actor,$status===403&&!str_contains($raw,$orders[$actor]),$status);
  [$status]=callQa('POST','/v1/orders/'.$orders[$actor].'/complete',null,'driver_a');checkQa('driver cannot complete order assigned to '.$actor,$status===403,$status);
 }
 foreach(['?limit=100&sort=-created_at','?limit=100&sort=-created_at&driver='.$state['users']['driver_a_other']['driver_public_id'],'?limit=100&sort=-created_at&company_uuid='.$state['users']['owner_b']['company_uuid']] as $suffix){
  [$status,$data,$raw]=callQa('GET','/v1/orders'.$suffix,null,'driver_a');checkQa('driver order list scope enforced '.$suffix,$status===200&&!str_contains($raw,$orders['driver_a_other'])&&!str_contains($raw,$orders['driver_b']),$status);
 }
 [$status,$data,$raw]=callQa('GET','/v1/orders?limit=100&sort=-created_at',null,'owner_a');checkQa('staff order list retains all own organization assignments',$status===200&&str_contains($raw,$orders['driver_a'])&&str_contains($raw,$orders['driver_a_other']),$status);
 [$status,$data,$raw]=callQa('GET','/v1/orders?limit=100&sort=-created_at',null,'driver_a');checkQa('driver list stays scoped after staff primes identical URL',$status===200&&str_contains($raw,$orders['driver_a'])&&!str_contains($raw,$orders['driver_a_other'])&&!str_contains($raw,$orders['driver_b']),$status);
 [$status,$data,$raw]=callQa('GET','/v1/orders?limit=100&sort=-created_at',null,'driver_a_other');checkQa('second driver cannot reuse first driver list cache',$status===200&&str_contains($raw,$orders['driver_a_other'])&&!str_contains($raw,$orders['driver_a']),$status);
 $orderPath='/v1/orders/'.$orders['driver_a'];
 [$status]=callQa('POST',$orderPath.'/start',['skip_dispatch'=>true],'driver_a');checkQa('driver cannot skip dispatch',$status===403,$status);
 [$status]=callQa('POST',$orderPath.'/update-activity',['activity'=>['code'=>'completed','complete'=>true]],'driver_a');checkQa('driver cannot jump from created to completed',$status===422,$status);
 [$status]=callQa('POST',$orderPath.'/dispatch',null,'dispatcher_a');checkQa('dispatcher can dispatch assigned order',$status===200,$status);
 [$status,$data]=callQa('POST',$orderPath.'/start',null,'driver_a');checkQa('assigned driver can start dispatched order',$status===200&&resourceQa($data,'order')['status']==='started',$status);
 [$status,$data]=callQa('POST',$orderPath.'/update-activity',['activity'=>['code'=>'enroute','complete'=>true,'events'=>[]]],'driver_a');checkQa('server flow overrides client completion metadata',$status===200&&resourceQa($data,'order')['status']==='enroute',$status);
 [$status]=callQa('POST',$orderPath.'/update-activity',['activity'=>['code'=>'completed']],'driver_a');checkQa('POD required before order completion',$status===422,$status);
 [$status]=callQa('POST',$orderPath.'/complete',null,'driver_a');checkQa('direct complete endpoint cannot bypass mandatory POD',$status===422,$status);
 [$status]=callQa('POST',$orderPath.'/capture-photo',['photos'=>[base64_encode('not an image')]],'driver_a');checkQa('base64 non-image POD rejected',$status===422,$status);
 [$status]=callQa('POST',$orderPath.'/capture-signature',['signature'=>['invalid']],'driver_a');checkQa('non-string signature rejected with validation response',$status===422,$status);
 $png='iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6KpQAAAAASUVORK5CYII=';
 $otherOrder=Fleetbase\FleetOps\Models\Order::where('public_id',$orders['driver_a_other'])->firstOrFail();
 $foreignEntity=Illuminate\Database\Eloquent\Model::withoutEvents(function()use($otherOrder){
  $entity=new Fleetbase\FleetOps\Models\Entity();$entity->forceFill(['uuid'=>Illuminate\Support\Str::uuid()->toString(),'public_id'=>'entity_releaseqa_'.bin2hex(random_bytes(6)),'company_uuid'=>$otherOrder->company_uuid,'payload_uuid'=>$otherOrder->payload_uuid,'name'=>'Synthetic entity in another order'])->save();return $entity;
 });
 $beforeProofs=Fleetbase\FleetOps\Models\Proof::query()->count();
 [$status]=callQa('POST',$orderPath.'/capture-photo/'.$foreignEntity->public_id,['photos'=>[$png]],'driver_a');checkQa('POD subject cannot reference another order entity',in_array($status,[400,403,404,422])&&Fleetbase\FleetOps\Models\Proof::query()->count()===$beforeProofs,$status);
 [$status,$otherData]=callQa('POST','/v1/orders/'.$orders['driver_a_other'].'/capture-photo',['photos'=>[$png]],'driver_a_other');
 $otherProofId=publicIdQa(resourceQa($otherData,'proof'));
 checkQa('other driver can persist own order proof',in_array($status,[200,201])&&is_string($otherProofId),$status);
 if($otherProofId){[$status]=callQa('POST',$orderPath.'/update-activity',['activity'=>['code'=>'completed'],'proof'=>$otherProofId],'driver_a');checkQa('proof from another order cannot satisfy delivery completion',$status===422,$status);}
 [$status,$data]=callQa('POST',$orderPath.'/capture-photo',['photos'=>[$png],'remarks'=>'Synthetic QA image only'],'driver_a');
 $proof=resourceQa($data,'proof');$proofId=publicIdQa($proof);checkQa('driver can persist valid photo POD',in_array($status,[200,201])&&is_string($proofId),$status);
 if($proofId){
  [$status,$data,$raw]=callQa('GET',$orderPath.'/proofs',null,'driver_a');checkQa('stored POD can be read back for assigned order',$status===200&&str_contains($raw,$proofId),$status);
  [$status,$data]=callQa('POST',$orderPath.'/update-activity',['activity'=>['code'=>'completed'],'proof'=>$proofId],'driver_a');checkQa('driver completes delivery with persisted POD',$status===200&&resourceQa($data,'order')['status']==='completed',$status);
  [$status,$data]=callQa('GET',$orderPath,null,'owner_a');checkQa('completed delivery survives fresh dispatcher read',$status===200&&resourceQa($data,'order')['status']==='completed',$status);
 }
}
$failures=array_values(array_filter($checks,fn($v)=>!$v['passed']));
$report=['environment'=>'isolated-synthetic-mysql','checks'=>$checks,'passed'=>count($checks)-count($failures),'failed'=>count($failures),'credentials_printed'=>false,'production_data_used'=>false];
file_put_contents(storage_path('app/release-qa/http-results.json'),json_encode($report,JSON_PRETTY_PRINT));
echo json_encode($report,JSON_PRETTY_PRINT),PHP_EOL;exit($failures?1:0);
