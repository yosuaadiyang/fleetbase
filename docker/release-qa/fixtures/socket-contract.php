<?php
require '/fleetbase/api/vendor/autoload.php';
$app = require '/fleetbase/api/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Support\{SocketAccessTickets,SocketChannelAccess};
use Fleetbase\Models\{User,CompanyUser};
use Fleetbase\FleetOps\Models\{Driver,Order};
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
if (getenv('CONTRUST_RELEASE_QA') !== 'synthetic-only' || DB::connection()->getDatabaseName() !== 'contrust_release_qa' || config('mail.default') !== 'log') throw new RuntimeException('Unsafe socket QA');
$state=json_decode(file_get_contents(storage_path('app/release-qa/credentials.json')),true,512,JSON_THROW_ON_ERROR);
$checks=[];$actors=[];$tickets=[];$created=[];
function ck($name,$passed){global $checks;$checks[]=['name'=>$name,'passed'=>(bool)$passed];}
foreach(['owner_a','owner_b','viewer_a','dispatcher_a','driver_a','driver_a_other','driver_b','staff_driver_a'] as $name){
 $user=User::findOrFail($state['users'][$name]['uuid']);
 $driver=Driver::where('user_uuid',$user->uuid)->first();
 $token=$user->createToken(str_starts_with($name,'driver')||$name==='staff_driver_a'?$driver->uuid:$user->uuid)->accessToken;
 $created[]=$token->id;$actors[$name]=SocketAccessTickets::actor($token);$tickets[$name]=SocketAccessTickets::issue($token);
 ck($name.' active actor resolves',is_array(SocketAccessTickets::resolve($tickets[$name])));
}
$ca='company.'.$state['companies']['a']['uuid'];$cb='company.'.$state['companies']['b']['uuid'];
$da=Driver::where('public_id',$state['users']['driver_a']['driver_public_id'])->firstOrFail();
$db=Driver::where('public_id',$state['users']['driver_b']['driver_public_id'])->firstOrFail();
$order=Order::where('company_uuid',$da->company_uuid)->where('driver_assigned_uuid',$da->uuid)->firstOrFail();
$own='order.'.$order->public_id;$ownDriver='driver.'.$da->public_id;
foreach(['owner_a','viewer_a','dispatcher_a','driver_a','staff_driver_a'] as $name){
 ck($name.' cannot subscribe foreign company',!SocketChannelAccess::allows($actors[$name],$cb));
 ck($name.' cannot subscribe foreign driver',!SocketChannelAccess::allows($actors[$name],'driver.'.$db->public_id));
}
ck('administrator own broad company channel',SocketChannelAccess::allows($actors['owner_a'],$ca));
ck('viewer denied broad company channel',!SocketChannelAccess::allows($actors['viewer_a'],$ca));
ck('dispatcher denied broad company channel',!SocketChannelAccess::allows($actors['dispatcher_a'],$ca));
ck('driver denied broad company channel',!SocketChannelAccess::allows($actors['driver_a'],$ca));
ck('staff-linked driver stays driver scoped',!SocketChannelAccess::allows($actors['staff_driver_a'],$ca));
foreach(['owner_a','viewer_a','dispatcher_a'] as $name){
 ck($name.' own order subscription allowed',SocketChannelAccess::allows($actors[$name],$own));
 ck($name.' own company orders subscription allowed',SocketChannelAccess::allows($actors[$name],$ca.'.orders'));
 ck($name.' own driver read subscription allowed',SocketChannelAccess::allows($actors[$name],$ownDriver));
}
ck('driver own profile allowed',SocketChannelAccess::allows($actors['driver_a'],$ownDriver));
ck('driver own assigned order allowed',SocketChannelAccess::allows($actors['driver_a'],$own));
ck('other driver denied assigned order',!SocketChannelAccess::allows($actors['driver_a_other'],$own));
ck('driver denied company orders',!SocketChannelAccess::allows($actors['driver_a'],$ca.'.orders'));
foreach(['api.secret','company.*',$own.'.orders','user.'.$state['users']['owner_b']['uuid']] as $channel) ck('unknown or foreign channel rejected '.$channel,!SocketChannelAccess::allows($actors['owner_a'],$channel));
$original=$order->driver_assigned_uuid;
try {$order->forceFill(['driver_assigned_uuid'=>$db->uuid])->saveQuietly();ck('reassigned order denied existing driver ticket',!SocketChannelAccess::allows(SocketAccessTickets::resolve($tickets['driver_a']),$own));}finally{$order->forceFill(['driver_assigned_uuid'=>$original])->saveQuietly();}
$t=$actors['driver_a_other']['token'];$t->delete();ck('revoked token invalidates cached ticket',SocketAccessTickets::resolve($tickets['driver_a_other'])===null);
$t=$actors['driver_b']['token'];$t->expires_at=now()->subMinute();$t->save();ck('expired token invalidates ticket',SocketAccessTickets::resolve($tickets['driver_b'])===null);
$u=$actors['viewer_a']['user'];$old=$u->status;
try{$u->forceFill(['status'=>'inactive'])->saveQuietly();ck('inactive user invalidates ticket',SocketAccessTickets::resolve($tickets['viewer_a'])===null);}finally{$u->forceFill(['status'=>$old])->saveQuietly();}
$m=$actors['dispatcher_a']['member'];$old=$m->status;
try{$m->forceFill(['status'=>'inactive'])->saveQuietly();ck('removed membership invalidates ticket',SocketAccessTickets::resolve($tickets['dispatcher_a'])===null);}finally{$m->forceFill(['status'=>$old])->saveQuietly();}
$user=$actors['staff_driver_a']['user'];$stale=$user->createToken(Illuminate\Support\Str::uuid()->toString())->accessToken;$created[]=$stale->id;
ck('missing staff driver cannot fall back to staff permissions',SocketAccessTickets::issue($stale)===null);
$plain=User::findOrFail($state['users']['owner_a']['uuid'])->createToken($state['users']['owner_a']['uuid']);$created[]=$plain->accessToken->id;
$context=stream_context_create(['http'=>['header'=>'Authorization: Bearer '.$plain->plainTextToken."\r\nAccept: application/json",'ignore_errors'=>true]]);
$raw=file_get_contents('http://127.0.0.1:8000/int/v1/contrust/socket-session',false,$context);
$cookie=implode("\n",array_filter($http_response_header,fn($h)=>stripos($h,'Set-Cookie: contrust_socket=')===0));
ck('authenticated HTTP issues opaque socket cookie',preg_match('/contrust_socket=[a-f0-9]{64};/',$cookie)===1);
ck('socket cookie secure httponly strict root',stripos($cookie,'secure')!==false&&stripos($cookie,'httponly')!==false&&stripos($cookie,'samesite=strict')!==false&&stripos($cookie,'path=/')!==false);
$raw=file_get_contents('http://127.0.0.1:8000/int/v1/contrust/socket-session',false,stream_context_create(['http'=>['ignore_errors'=>true]]));
ck('unauthenticated session rejected',str_contains($http_response_header[0],'401'));
$secret=hash_hmac('sha256','contrust.socket.publisher.v1',config('app.key'));
foreach([[null,403],[$secret,200]] as [$key,$expected]){
 $ctx=stream_context_create(['http'=>['method'=>'POST','ignore_errors'=>true,'header'=>"Content-Type: application/json\r\n".($key?'X-Contrust-Socket-Publisher: '.$key:''),'content'=>json_encode(['ticket'=>$tickets['owner_a'],'channel'=>$ca])]]);
 $raw=file_get_contents('http://127.0.0.1:8000/int/v1/contrust/socket-authorize',false,$ctx);
 ck($key?'private authorization permits legitimate own channel':'public authorization callback requires publisher secret',str_contains($http_response_header[0],(string)$expected));
}
// Private synthetic tickets only, for the actual transport contract; never printed.
$fixture=['tickets'=>$tickets,'own_channel'=>$own,'foreign_channel'=>'driver.'.$db->public_id,'publisher_key'=>$secret];
file_put_contents(storage_path('app/release-qa/socket-transport.json'),json_encode($fixture));chmod(storage_path('app/release-qa/socket-transport.json'),0600);
$failed=count(array_filter($checks,fn($c)=>!$c['passed']));
echo json_encode(['environment'=>'isolated-synthetic-mysql','checks'=>$checks,'passed'=>count($checks)-$failed,'failed'=>$failed,'credentials_printed'=>false,'production_data_used'=>false],JSON_PRETTY_PRINT),PHP_EOL;exit($failed?1:0);
