<?php
require '/fleetbase/api/vendor/autoload.php';$app=require '/fleetbase/api/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Fleetbase\Models\{ChatChannel,ChatMessage,ChatParticipant,ChatReceipt,CompanyUser,User,Role};
use Illuminate\Support\Facades\{DB,Schema};use Illuminate\Support\Str;
if(getenv('CONTRUST_RELEASE_QA')!=='synthetic-only'||DB::connection()->getDatabaseName()!=='contrust_release_qa'||config('mail.default')!=='log'||config('broadcasting.default')!=='log')throw new RuntimeException('Unsafe internal chat QA');
$s=json_decode(file_get_contents(storage_path('app/release-qa/credentials.json')),true);$tokens=[];$tokenIds=[];$rooms=[];$peer=null;$peerMember=null;$checks=[];$run=bin2hex(random_bytes(6));
function webCheck($name,$passed,$http=null){global$checks;$checks[]=['name'=>$name,'passed'=>(bool)$passed,'http'=>$http];}
function webCall($method,$path,$body=null,$actor='owner_a'){global$tokens;$o=['method'=>$method,'timeout'=>20,'ignore_errors'=>true,'header'=>"Accept: application/json\r\nContent-Type: application/json\r\nConnection: close\r\nAuthorization: Bearer ".$tokens[$actor]];if($body!==null)$o['content']=json_encode($body);$raw=@file_get_contents('http://127.0.0.1:8000/int/v1/'.$path,false,stream_context_create(['http'=>$o]));preg_match('/\s(\d{3})\s/',$http_response_header[0]??'',$m);return[(int)($m[1]??0),json_decode($raw?:'{}',true),$raw?:''];}
try{
 foreach(['owner_a','owner_b','dispatcher_a']as$n){$u=User::findOrFail($s['users'][$n]['uuid']);$t=$u->createToken($u->uuid);$tokens[$n]=$t->plainTextToken;$tokenIds[]=$t->accessToken->id;}
 $owner=User::findOrFail($s['users']['owner_a']['uuid']);$peer=$owner->replicate(['id','uuid']);
 $peer->forceFill(['uuid'=>(string)Str::uuid(),'public_id'=>'user_webqa_'.$run,'username'=>'webqa_'.$run,'slug'=>'webqa-'.$run,'email'=>'webqa-'.$run.'@contrust.example.test','phone'=>null,'name'=>'Synthetic room nonparticipant'])->saveQuietly();
 $peerMember=new CompanyUser();$peerMember->forceFill(['uuid'=>(string)Str::uuid(),'company_uuid'=>$owner->company_uuid,'user_uuid'=>$peer->uuid,'status'=>'active'])->saveQuietly();$peerMember->assignSingleRole(Role::where('name','Administrator')->where('guard_name','sanctum')->firstOrFail());
 $t=$peer->createToken($peer->uuid);$tokens['peer']=$t->plainTextToken;$tokenIds[]=$t->accessToken->id;
 app(Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
 [$c]=webCall('POST','chat-channels',['chatChannel'=>['name'=>'Synthetic web room '.$run,'participants'=>[$s['users']['dispatcher_a']['public_id']]]]);
 $room=ChatChannel::where('company_uuid',$owner->company_uuid)->where('name','Synthetic web room '.$run)->first();if($room)$rooms[]=$room->uuid;
 webCheck('normal Ember wrapper creates a room with its creator and invited member',in_array($c,[200,201],true)&&$room&&$room->participants()->count()===2,$c);if(!$room)throw new RuntimeException('Synthetic web room missing');
 $sender=$room->participants()->where('user_uuid',$owner->uuid)->firstOrFail();$recipient=$room->participants()->where('user_uuid',$s['users']['dispatcher_a']['uuid'])->firstOrFail();
 [$c,$data]=webCall('POST','chat-messages',['chatMessage'=>['chat_channel_uuid'=>$room->uuid,'sender_uuid'=>$sender->uuid,'content'=>'<b>Synthetic private web message</b>','attachment_files'=>[],'sender'=>null,'attachments'=>[],'receipts'=>[],'created_at'=>null]]);
 $message=ChatMessage::where('chat_channel_uuid',$room->uuid)->first();
 webCheck('normal Ember message wrapper persists sanitized content',in_array($c,[200,201],true)&&$message&&$message->content==='Synthetic private web message',$c);if(!$message)throw new RuntimeException('Synthetic message missing');
 foreach(['channel'=>'chat-channels/'.$room->uuid,'message'=>'chat-messages/'.$message->uuid,'participant'=>'chat-participants/'.$sender->uuid]as$kind=>$path){
  [$c,,$raw]=webCall('GET',$path,null,'peer');webCheck('same organization admin nonparticipant cannot read '.$kind,$c===403&&!str_contains($raw,'Synthetic private web message'),$c);
  [$c]=webCall('GET',$path,null,'owner_b');webCheck('foreign organization cannot read '.$kind,$c===403,$c);
  [$c]=webCall('GET',$path);webCheck('current participant can read '.$kind,$c===200,$c);
 }
 foreach(['chat-channels?name=Synthetic%20web%20room%20'.$run,'chat-messages?chat_channel_uuid='.$room->uuid,'chat-participants?chat_channel_uuid='.$room->uuid]as$i=>$path){
  [$c,,$raw]=webCall('GET',$path,null,'peer');webCheck('mandatory SQL participant scope hides list '.$i,$c===200&&!str_contains($raw,$room->uuid)&&!str_contains($raw,'Synthetic private web message'),$c);
 }
 [$c]=webCall('POST','chat-messages',['chatMessage'=>['chat_channel_uuid'=>$room->uuid,'sender_uuid'=>$sender->uuid,'content'=>'not permitted']],'peer');webCheck('nonparticipant cannot send via internal endpoint',$c===403,$c);
 [$c]=webCall('POST','chat-messages',['chatMessage'=>['chat_channel_uuid'=>$room->uuid,'sender_uuid'=>$sender->uuid,'content'=>'spoofed sender']],'dispatcher_a');webCheck('participant cannot spoof another sender',$c===403,$c);
 [$c]=webCall('POST','chat-messages',['chatMessage'=>['company_uuid'=>$s['companies']['b']['uuid'],'chat_channel_uuid'=>$room->uuid,'content'=>'spoofed company']]);webCheck('message ownership override rejected',$c===403,$c);
 [$c]=webCall('POST','chat-messages',['chatMessage'=>['chat_channel_uuid'=>$room->uuid,'content'=>['invalid']]]);webCheck('array message content returns controlled422',$c===422,$c);
 [$c]=webCall('POST','chat-messages',['chatMessage'=>['chat_channel_uuid'=>$room->uuid,'content'=>'pending private upload','attachment_files'=>['foreign-file']]]);webCheck('generic attachment ID cannot bypass private file gateway',$c===422,$c);
 webCheck('rejected requests leave one original message',ChatMessage::where('chat_channel_uuid',$room->uuid)->count()===1&&$message->fresh()->content==='Synthetic private web message');
 [$c]=webCall('PATCH','chat-messages/'.$message->uuid,['chatMessage'=>['content'=>'not permitted']],'dispatcher_a');webCheck('participant cannot edit another author',$c===403,$c);
 [$c]=webCall('DELETE','chat-messages/'.$message->uuid,null,'dispatcher_a');webCheck('participant cannot delete another author',$c===403,$c);
 webCheck('rejected edit and delete leave original message intact',ChatMessage::where('uuid',$message->uuid)->where('content','Synthetic private web message')->exists());
 [$c]=webCall('PATCH','chat-messages/'.$message->uuid,['chatMessage'=>['chat_channel_uuid'=>$room->uuid,'sender_uuid'=>$sender->uuid,'content'=>'Synthetic updated text']]);webCheck('author retains normal message edit',$c===200,$c);
 [$c]=webCall('PATCH','chat-channels/'.$room->uuid,['chatChannel'=>['name'=>'not permitted','meta'=>[]]],'dispatcher_a');webCheck('participant cannot rename creator room',$c===403,$c);
 [$c]=webCall('PATCH','chat-channels/'.$room->uuid,['chatChannel'=>['name'=>'Synthetic renamed '.$run,'meta'=>[]]]);webCheck('creator retains room rename',$c===200,$c);
 [$c]=webCall('POST','chat-participants',['chatParticipant'=>['chat_channel_uuid'=>$room->uuid,'user_uuid'=>$peer->uuid]],'dispatcher_a');webCheck('noncreator cannot invite another participant',$c===403,$c);
 [$c]=webCall('POST','chat-participants',['chatParticipant'=>['chat_channel_uuid'=>$room->uuid,'user_uuid'=>$s['users']['owner_b']['uuid']]]);webCheck('creator cannot invite foreign organization user',$c===403,$c);
 [$c]=webCall('POST','chat-participants',['chatParticipant'=>['chat_channel_uuid'=>$room->uuid,'user_uuid'=>$peer->uuid]]);webCheck('creator may invite active own organization member',in_array($c,[200,201],true),$c);
 $peerParticipant=$room->participants()->where('user_uuid',$peer->uuid)->firstOrFail();
 [$c]=webCall('GET','chat-channels/'.$room->uuid,null,'peer');webCheck('invitation grants room access',$c===200,$c);
 [$c]=webCall('POST','chat-participants',['chatParticipant'=>['chat_channel_uuid'=>$room->uuid,'user_uuid'=>$peer->uuid]]);webCheck('duplicate invitation is idempotent',$c===200&&$room->participants()->where('user_uuid',$peer->uuid)->count()===1,$c);
 [$c]=webCall('POST','chat-receipts',['chatReceipt'=>['chat_message_uuid'=>$message->uuid,'participant_uuid'=>$sender->uuid]],'dispatcher_a');webCheck('receipt cannot impersonate another participant',$c===403,$c);
 [$c]=webCall('POST','chat-receipts',['chatReceipt'=>['chat_message_uuid'=>$message->uuid,'participant_uuid'=>$recipient->uuid,'read_at'=>now()->toISOString()]],'dispatcher_a');webCheck('own read receipt persists',in_array($c,[200,201],true),$c);
 $receipt=ChatReceipt::where('chat_message_uuid',$message->uuid)->where('participant_uuid',$recipient->uuid)->firstOrFail();
 [$c]=webCall('GET','chat-receipts/'.$receipt->uuid);webCheck('room participant can read receipt without public_id column',$c===200,$c);
 [$c]=webCall('POST','chat-receipts',['chatReceipt'=>['chat_message_uuid'=>$message->uuid,'participant_uuid'=>$recipient->uuid]],'dispatcher_a');webCheck('duplicate read receipt stays one record',$c===200&&ChatReceipt::where('chat_message_uuid',$message->uuid)->where('participant_uuid',$recipient->uuid)->count()===1,$c);
 [$c]=webCall('GET','chat-channels/'.$room->uuid.'?contain=createdBy');webCheck('unapproved relation expansion is rejected',$c===422,$c);
 [$c]=webCall('GET','chat-channels?limit=-1');webCheck('unbounded list request is rejected',$c===422,$c);
 [$c]=webCall('GET','chat-channels/available-participants?channel='.$room->uuid,null,'owner_b');webCheck('directory query cannot expose foreign room membership',$c===403,$c);
 $peerMember->forceFill(['status'=>'inactive'])->saveQuietly();
 try{[$c]=webCall('GET','chat-channels/'.$room->uuid,null,'peer');webCheck('inactive organization member loses room access immediately',$c===403,$c);}finally{$peerMember->forceFill(['status'=>'active'])->saveQuietly();}
 [$c]=webCall('DELETE','chat-participants/'.$peerParticipant->uuid);webCheck('creator may remove invited participant',$c===200,$c);
 [$c]=webCall('GET','chat-channels/'.$room->uuid,null,'peer');webCheck('removal revokes direct room access immediately',$c===403,$c);
 [$c,,$raw]=webCall('GET','chat-messages?chat_channel_uuid='.$room->uuid,null,'peer');webCheck('removal revokes cached list access immediately',$c===200&&!str_contains($raw,'Synthetic updated text'),$c);
 [$c]=webCall('DELETE','chat-messages/'.$message->uuid);webCheck('author may delete own message',$c===204,$c);
 $deleted=DB::table('chat_messages')->where('uuid',$message->uuid)->first(['deleted_at']);webCheck('successful message deletion changes database visibility',!$deleted||$deleted->deleted_at!==null);
 [$c]=webCall('GET','chat-messages/'.$message->uuid);webCheck('deleted message cannot be opened by previous author',$c===403,$c);
 [$c]=webCall('DELETE','chat-channels/'.$room->uuid);webCheck('creator may delete own room',$c===204,$c);
 $deleted=DB::table('chat_channels')->where('uuid',$room->uuid)->first(['deleted_at']);webCheck('successful room deletion changes database visibility',!$deleted||$deleted->deleted_at!==null);
}finally{
 foreach($rooms as$id){$messageIds=DB::table('chat_messages')->where('chat_channel_uuid',$id)->pluck('uuid');if($messageIds->isNotEmpty())DB::table('chat_receipts')->whereIn('chat_message_uuid',$messageIds)->delete();foreach(['chat_attachments','chat_messages','chat_logs','chat_participants']as$table)DB::table($table)->where('chat_channel_uuid',$id)->delete();DB::table('chat_channels')->where('uuid',$id)->delete();}
 if($tokenIds)DB::table('personal_access_tokens')->whereIn('id',$tokenIds)->delete();if($peerMember){DB::table('model_has_roles')->where('model_uuid',$peerMember->uuid)->delete();DB::table('company_users')->where('uuid',$peerMember->uuid)->delete();}if($peer)DB::table('users')->where('uuid',$peer->uuid)->delete();
 app(Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
}
$failed=count(array_filter($checks,fn($c)=>!$c['passed']));echo json_encode(['environment'=>'isolated-synthetic-mysql','checks'=>$checks,'passed'=>count($checks)-$failed,'failed'=>$failed,'production_data_used'=>false,'notifications_sent'=>false,'credentials_printed'=>false],JSON_PRETTY_PRINT),PHP_EOL;exit($failed?1:0);
