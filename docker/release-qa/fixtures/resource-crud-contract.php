<?php
require '/fleetbase/api/vendor/autoload.php';
$app=require '/fleetbase/api/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Fleetbase\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;
if(getenv('CONTRUST_RELEASE_QA')!=='synthetic-only'||DB::connection()->getDatabaseName()!=='contrust_release_qa'||config('mail.default')!=='log'||config('broadcasting.default')!=='log')throw new RuntimeException('Unsafe resource CRUD QA');
$s=json_decode(file_get_contents(storage_path('app/release-qa/credentials.json')),true,512,JSON_THROW_ON_ERROR);
$checks=[];$tokens=[];$tokenIds=[];$prefix='AUDIT-CRUD-'.bin2hex(random_bytes(5));
$resources=['vehicle'=>Fleetbase\FleetOps\Models\Vehicle::class,'trailer'=>Fleetbase\FleetOps\Models\Trailer::class,'fleet'=>Fleetbase\FleetOps\Models\Fleet::class,'vendor'=>Fleetbase\FleetOps\Models\Vendor::class,'contact'=>Fleetbase\FleetOps\Models\Contact::class];
$driverBefore=DB::table('drivers')->whereIn('public_id',[$s['users']['driver_a_other']['driver_public_id'],$s['users']['driver_b']['driver_public_id']])->get(['uuid','vehicle_uuid','deleted_at']);

function crudCheck($name,$ok,$http=null){global $checks;$checks[]=['name'=>$name,'passed'=>(bool)$ok,'http'=>$http];}
function crudCall($method,$path,$body=null,$actor='owner_a'){
 global $tokens;$h=['Accept: application/json','Content-Type: application/json','Connection: close'];if($actor!==null)$h[]='Authorization: Bearer '.$tokens[$actor];
 $o=['method'=>$method,'ignore_errors'=>true,'timeout'=>15,'header'=>implode("\r\n",$h)];if($body!==null)$o['content']=json_encode($body);
 $raw=@file_get_contents('http://127.0.0.1:8000/int/v1/'.$path,false,stream_context_create(['http'=>$o]));preg_match('/\s(\d{3})\s/',$http_response_header[0]??'',$m);
 return [(int)($m[1]??0),json_decode($raw?:'null',true)];
}
try{
 foreach(['owner_a','owner_b','viewer_a','driver_a'] as $actor){$u=User::findOrFail($s['users'][$actor]['uuid']);$t=$u->createToken('qa-resource-crud');$tokens[$actor]=$t->plainTextToken;$tokenIds[]=$t->accessToken->id;}
 foreach($resources as $singular=>$model){
  $plural=$singular.'s';$body=['name'=>$prefix.' '.$singular,'status'=>$singular==='vehicle'||$singular==='trailer'?'available':'active'];if($singular==='contact')$body['type']='contact';if($singular==='vehicle')$body+=['make'=>'Toyota','model'=>'Avanza','year'=>2024,'location'=>['type'=>'Point','coordinates'=>[106.8272,-6.1754]]];
  foreach([null,'viewer_a','driver_a'] as $actor){[$c]=crudCall('POST',$plural,[$singular=>$body],$actor);crudCheck($singular.' create denied '.($actor??'anonymous'),in_array($c,[401,403],true),$c);}
  [$c,$v]=crudCall('POST',$plural,[$singular=>$body]);$r=$model::withoutCache()->where('name',$body['name'])->first();
  crudCheck($singular.' owner create persists',in_array($c,[200,201],true)&&$r!==null,$c);
  if(!$r)throw new RuntimeException('Synthetic resource create did not persist');
  $id=$r->uuid;$path=$plural.'/'.$id;
  crudCheck($singular.' company assigned from authenticated actor',$r->company_uuid===$s['companies']['a']['uuid']);
  crudCheck($singular.' create returns internal resource ID',($v[$singular]['uuid']??null)===$id);
  [$c,$v]=crudCall('GET',$path);crudCheck($singular.' owner read',$c===200&&($v[$singular]['name']??null)===$body['name'],$c);
  [$c,$v]=crudCall('GET',$plural.'?limit=100');crudCheck($singular.' own list includes record',$c===200&&in_array($id,array_column($v[$plural]??[],'uuid'),true),$c);
  [$c,$v]=crudCall('GET',$plural.'?company_uuid='.$s['companies']['a']['uuid'],null,'owner_b');crudCheck($singular.' forged company filter cannot read foreign record',$c===200&&!in_array($id,array_column($v[$plural]??[],'uuid'),true),$c);
  foreach(['GET','PUT','DELETE'] as $method){[$c,$v]=crudCall($method,$path,$method==='PUT'?[$singular=>['name'=>$prefix.' illegal']]:null,'owner_b');crudCheck($singular.' foreign organization denied '.$method,in_array($c,[400,403,404],true)&&!isset($v[$singular]['uuid']),$c);}
  $fresh=DB::table((new $model)->getTable())->where('uuid',$id)->whereNull('deleted_at')->first();crudCheck($singular.' foreign mutations leave original intact',$fresh&&$fresh->name===$body['name']&&$fresh->company_uuid===$s['companies']['a']['uuid']);
  foreach(['PUT','DELETE'] as $method){[$c]=crudCall($method,$path,$method==='PUT'?[$singular=>['name'=>$prefix.' forbidden']]:null,'viewer_a');crudCheck($singular.' read-only role denied '.$method,$c===403,$c);}
  [$c]=crudCall('PUT',$path,[$singular=>['name'=>$body['name'].' edited']]);[$read,$v]=crudCall('GET',$path);crudCheck($singular.' owner update persists',$c===200&&$read===200&&($v[$singular]['name']??null)===$body['name'].' edited',$c);
  if($singular==='vehicle'){
   crudCheck('vehicle defaults offline when online omitted',$r->online===false);
   $foreignDriver=DB::table('drivers')->where('public_id',$s['users']['driver_b']['driver_public_id'])->value('uuid');
   $ownDriver=DB::table('drivers')->where('public_id',$s['users']['driver_a_other']['driver_public_id'])->value('uuid');
   $foreignBody=$body;$foreignBody['name']=$prefix.' foreign-assignment';$foreignBody['driver_uuid']=$foreignDriver;
   [$c]=crudCall('POST',$plural,[$singular=>$foreignBody]);$foreignVehicle=DB::table('vehicles')->where('name',$foreignBody['name'])->first();
   crudCheck('vehicle create with foreign driver cannot prevent valid vehicle save',in_array($c,[200,201],true)&&$foreignVehicle!==null,$c);
   crudCheck('vehicle create observer cannot assign foreign organization driver',$foreignVehicle&&DB::table('drivers')->where('uuid',$foreignDriver)->value('vehicle_uuid')!==$foreignVehicle->uuid);
   [$c]=crudCall('PUT',$path,[$singular=>['driver'=>['uuid'=>$foreignDriver]]]);
   crudCheck('vehicle edit with foreign assignment returns a handled response',$c===200,$c);
   crudCheck('vehicle update observer cannot assign foreign organization driver',DB::table('drivers')->where('uuid',$foreignDriver)->value('vehicle_uuid')!==$id);
   [$c]=crudCall('PUT',$path,[$singular=>['driver'=>['uuid'=>$ownDriver]]]);
   crudCheck('vehicle own organization driver assignment works',$c===200&&DB::table('drivers')->where('uuid',$ownDriver)->value('vehicle_uuid')===$id,$c);
  }
  [$c]=crudCall('PUT',$path,[$singular=>['company_uuid'=>$s['companies']['b']['uuid']]]);$fresh=DB::table((new $model)->getTable())->where('uuid',$id)->whereNull('deleted_at')->first();crudCheck($singular.' ownership cannot be reassigned via update',$fresh&&$fresh->company_uuid===$s['companies']['a']['uuid'],$c);
  [$c]=crudCall('POST',$plural,[$singular=>['name'=>$prefix.' spoof '.$singular,'company_uuid'=>$s['companies']['b']['uuid']]]);$spoof=$model::withoutCache()->where('name',$prefix.' spoof '.$singular)->first();crudCheck($singular.' create cannot inject foreign ownership',!$spoof||$spoof->company_uuid===$s['companies']['a']['uuid'],$c);
  foreach([[],['nested'=>'bad'],null,str_repeat('x',256),1,true] as $case=>$bad){[$c]=crudCall('POST',$plural,[$singular=>['name'=>$bad]]);crudCheck($singular.' malformed name returns client error '.$case,in_array($c,[400,422],true),$c);}
  [$c]=crudCall('DELETE',$path);crudCheck($singular.' owner delete succeeds',$c===200&&!DB::table((new $model)->getTable())->where('uuid',$id)->whereNull('deleted_at')->exists(),$c);
  if($singular==='vehicle'){
   $driverRow=DB::table('drivers')->where('uuid',$ownDriver)->first(['deleted_at','vehicle_uuid']);
   crudCheck('deleting vehicle preserves assigned driver profile',$driverRow&&$driverRow->deleted_at===null);
   crudCheck('deleting vehicle clears obsolete driver vehicle link',$driverRow&&$driverRow->vehicle_uuid===null);
  }
  [$c,$v]=crudCall('GET',$path);crudCheck($singular.' deleted record cannot be read',in_array($c,[400,403,404],true)&&!isset($v[$singular]['uuid']),$c);
 }
}finally{
 foreach($driverBefore as $d)DB::table('drivers')->where('uuid',$d->uuid)->update(['vehicle_uuid'=>$d->vehicle_uuid,'deleted_at'=>$d->deleted_at]);
 Model::withoutEvents(function()use($resources,$prefix){foreach($resources as $model)$model::withTrashed()->where('name','like',$prefix.'%')->forceDelete();});
 if($tokenIds)DB::table('personal_access_tokens')->whereIn('id',$tokenIds)->delete();
}
$failed=count(array_filter($checks,fn($c)=>!$c['passed']));echo json_encode(['environment'=>'isolated-synthetic-mysql','checks'=>$checks,'passed'=>count($checks)-$failed,'failed'=>$failed,'production_changed'=>false,'external_notifications_sent'=>false,'credentials_or_record_contents_printed'=>false],JSON_PRETTY_PRINT),"\n";exit($failed?1:0);
