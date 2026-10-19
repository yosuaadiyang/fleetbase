<?php
require '/fleetbase/api/vendor/autoload.php';$app=require '/fleetbase/api/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Fleetbase\Models\User;
if(getenv('CONTRUST_RELEASE_QA')!=='synthetic-only'||DB::connection()->getDatabaseName()!=='contrust_release_qa'||config('mail.default')!=='log')throw new RuntimeException('Unsafe metrics QA');
$s=json_decode(file_get_contents(storage_path('app/release-qa/credentials.json')),true,512,JSON_THROW_ON_ERROR);$checks=[];$tokens=[];$ids=[];
function metricCall($token,$suffix=''){
 $headers=['Accept: application/json','Connection: close'];if($token)$headers[]='Authorization: Bearer '.$token;
 $raw=@file_get_contents('http://127.0.0.1:8000/int/v1/fleet-ops/metrics/drivers_online?period=30d&compare=false'.$suffix,false,stream_context_create(['http'=>['ignore_errors'=>true,'timeout'=>15,'header'=>implode("\r\n",$headers)]]));preg_match('/\s(\d{3})\s/',$http_response_header[0]??'',$m);return [(int)($m[1]??0),json_decode($raw,true)];
}
$companies=[$s['companies']['a']['uuid'],$s['companies']['b']['uuid']];$before=DB::table('drivers')->whereIn('company_uuid',$companies)->get(['uuid','online','current_job_uuid']);
try{
 foreach(['owner_a','owner_b'] as $actor){$u=User::findOrFail($s['users'][$actor]['uuid']);$t=$u->createToken('qa-dashboard-metrics');$tokens[$actor]=$t->plainTextToken;$ids[]=$t->accessToken->id;}
 DB::table('drivers')->whereIn('company_uuid',$companies)->update(['online'=>false,'current_job_uuid'=>null]);
 $a=$s['users']['driver_a']['driver_uuid']??DB::table('drivers')->where('public_id',$s['users']['driver_a']['driver_public_id'])->value('uuid');
 $b=DB::table('drivers')->where('public_id',$s['users']['driver_b']['driver_public_id'])->value('uuid');
 DB::table('drivers')->where('uuid',$a)->update(['online'=>true]);[$c,$v]=metricCall($tokens['owner_a']);
 $checks[]=['name'=>'online KPI includes available driver without active job','passed'=>$c===200&&($v['value']??null)===1,'http'=>$c];
 $checks[]=['name'=>'online KPI uses expected scalar contract','passed'=>($v['slug']??null)==='drivers_online'&&($v['format']??null)==='count'];
 DB::table('drivers')->where('uuid',$a)->update(['online'=>false]);[$c,$v]=metricCall($tokens['owner_a']);$checks[]=['name'=>'online KPI reflects status changes without stale model cache','passed'=>$c===200&&($v['value']??null)===0,'http'=>$c];
 DB::table('drivers')->where('uuid',$b)->update(['online'=>true]);[$c,$v]=metricCall($tokens['owner_b']);$checks[]=['name'=>'other company sees its own online driver','passed'=>$c===200&&($v['value']??null)===1,'http'=>$c];
 [$c,$v]=metricCall($tokens['owner_a']);$checks[]=['name'=>'other company online driver excluded','passed'=>$c===200&&($v['value']??null)===0,'http'=>$c];
 [$c,$v]=metricCall($tokens['owner_a'],'&company_uuid='.$companies[1]);$checks[]=['name'=>'query cannot override metrics company scope','passed'=>$c===200&&($v['value']??null)===0,'http'=>$c];
 [$c]=metricCall(null);$checks[]=['name'=>'anonymous metrics denied','passed'=>$c===401,'http'=>$c];
 [$c]=metricCall($s['bootstrap']['a']);$checks[]=['name'=>'embedded Navigator credential cannot read staff metrics','passed'=>$c===403,'http'=>$c];
}finally{foreach($before as $row)DB::table('drivers')->where('uuid',$row->uuid)->update(['online'=>$row->online,'current_job_uuid'=>$row->current_job_uuid]);if($ids)DB::table('personal_access_tokens')->whereIn('id',$ids)->delete();}
$failed=count(array_filter($checks,fn($c)=>!$c['passed']));echo json_encode(['environment'=>'isolated-synthetic-mysql','checks'=>$checks,'passed'=>count($checks)-$failed,'failed'=>$failed,'production_changed'=>false,'credentials_or_record_contents_printed'=>false],JSON_PRETTY_PRINT),"\n";exit($failed?1:0);
