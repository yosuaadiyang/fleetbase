<?php
// Idempotent synthetic fixtures. Refuse every database except the dedicated QA DB.
require '/fleetbase/api/vendor/autoload.php';
$app=require '/fleetbase/api/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Fleetbase\Models\{Company,User,Role,Permission};
use Illuminate\Support\Facades\{DB,Hash};
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
if (getenv('CONTRUST_RELEASE_QA')!=='synthetic-only' || DB::connection()->getDatabaseName()!=='contrust_release_qa' || config('mail.default')!=='log') {
 throw new RuntimeException('Refusing to seed outside isolated synthetic QA');
}
$folder=storage_path('app/release-qa');
if (!is_dir($folder)) mkdir($folder,0700,true);
$path=$folder.'/credentials.json';
$state=is_file($path)?json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR):['users'=>[]];
$company=[];$users=[];
$adminRole=Role::where('name','Administrator')->where('guard_name','sanctum')->firstOrFail();
$viewer=Role::firstOrCreate(['name'=>'Release QA Viewer','guard_name'=>'sanctum']);
$viewer->syncPermissions(Permission::whereIn('name',['fleet-ops list order','fleet-ops view order','fleet-ops list place','fleet-ops view place','fleet-ops list driver','fleet-ops view driver'])->get());
$dispatcher=Role::firstOrCreate(['name'=>'Release QA Dispatcher','guard_name'=>'sanctum']);
$dispatcher->syncPermissions(Permission::where('service','fleet-ops')->where(function($q){$q->where('name','like','% order')->orWhere('name','like','% place')->orWhere('name','like','% driver')->orWhere('name','fleet-ops see extension');})->get());
Model::withoutEvents(function()use(&$state,&$company,&$users,$adminRole,$viewer,$dispatcher){
 foreach(['a','b'] as $tenant){
  $c=Company::firstOrNew(['public_id'=>'company_releaseqa_'.$tenant]);
  $c->forceFill(['uuid'=>$c->uuid?:Str::uuid()->toString(),'name'=>'Release QA Organization '.strtoupper($tenant),'slug'=>'release-qa-'.$tenant,'country'=>'ID','currency'=>'IDR','timezone'=>'Asia/Jakarta','status'=>'active','type'=>'organization','onboarding_completed_at'=>now()])->save();
  $company[$tenant]=$c;
 }
 foreach(['owner_a'=>'a','owner_b'=>'b','viewer_a'=>'a','dispatcher_a'=>'a','driver_a'=>'a','driver_a_other'=>'a','driver_b'=>'b','staff_driver_a'=>'a'] as $actor=>$tenant){
  $email=str_replace('_','-',$actor).'@release-qa.example.test';
  $secret=$state['users'][$actor]['password']??bin2hex(random_bytes(24));
  $u=User::firstOrNew(['email'=>$email]);
  $u->forceFill(['uuid'=>$u->uuid?:Str::uuid()->toString(),'public_id'=>'user_releaseqa_'.$actor,'name'=>'Release QA '.str_replace('_',' ',$actor),'username'=>'releaseqa_'.$actor,'slug'=>'releaseqa-'.$actor,'email'=>$email,'phone'=>(str_starts_with($actor,'driver')||$actor==='staff_driver_a')?'+120255501'.str_pad((string)count($users),2,'0',STR_PAD_LEFT):null,'password'=>$secret,'company_uuid'=>$company[$tenant]->uuid,'type'=>str_starts_with($actor,'driver')?'driver':'user','status'=>'active','email_verified_at'=>now(),'timezone'=>'Asia/Jakarta','country'=>'ID'])->save();
  $role=str_starts_with($actor,'owner')?$adminRole:($actor==='viewer_a'?$viewer:(in_array($actor,['dispatcher_a','staff_driver_a'],true)?$dispatcher:null));
  $membership=Fleetbase\Models\CompanyUser::firstOrNew(['company_uuid'=>$company[$tenant]->uuid,'user_uuid'=>$u->uuid]);
  // UUID traits use model events, disabled only while creating synthetic rows.
  $membership->forceFill(['uuid'=>$membership->uuid?:Str::uuid()->toString(),'status'=>'active'])->save();
  if($role)$membership->assignSingleRole($role);
  if(str_starts_with($actor,'owner')){$company[$tenant]->forceFill(['owner_uuid'=>$u->uuid])->save();}
  $driver=null;
  if(str_starts_with($actor,'driver')||$actor==='staff_driver_a'){
   $driver=Fleetbase\FleetOps\Models\Driver::firstOrNew(['public_id'=>'driver_releaseqa_'.$actor]);
   $driver->forceFill(['uuid'=>$driver->uuid?:Str::uuid()->toString(),'user_uuid'=>$u->uuid,'company_uuid'=>$company[$tenant]->uuid,'status'=>'available','online'=>false,'location'=>new Fleetbase\LaravelMysqlSpatial\Types\Point(-6.1754,106.8272)])->save();
  }
  $state['users'][$actor]=['identity'=>$email,'phone'=>$u->phone,'password'=>$secret,'uuid'=>$u->uuid,'public_id'=>$u->public_id,'company_uuid'=>$company[$tenant]->uuid,'driver_public_id'=>$driver?->public_id];
  $users[$actor]=$u;
 }
});
$state['companies']=array_map(fn($c)=>['uuid'=>$c->uuid,'public_id'=>$c->public_id],$company);
foreach($company as $tenant=>$qaCompany){
 session(['company'=>$qaCompany->uuid,'user'=>$users['owner_'.$tenant]->uuid]);
 $orderConfig=Fleetbase\FleetOps\Models\OrderConfig::defaultOrCreate($qaCompany);
 if(!$orderConfig)throw new RuntimeException('Synthetic transport config missing');
 $state['order_configs'][$tenant]=$orderConfig->public_id;
 $credential=Fleetbase\Models\ApiCredential::firstOrCreate(
  ['company_uuid'=>$qaCompany->uuid,'name'=>'NavigationAppLinker'],
  ['user_uuid'=>$users['owner_'.$tenant]->uuid,'test_mode'=>false]
 );
 $state['bootstrap'][$tenant]=$credential->fresh()->key;
}
umask(0077);file_put_contents($path,json_encode($state,JSON_THROW_ON_ERROR));chmod($path,0600);
app(Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
echo json_encode(['synthetic_seed'=>'ready','organizations'=>count($company),'actors'=>count($users),'viewer_permissions'=>$viewer->permissions()->count(),'dispatcher_permissions'=>$dispatcher->permissions()->count(),'credentials_printed'=>false]),PHP_EOL;
