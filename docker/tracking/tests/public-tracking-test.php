<?php
require '/fleetbase/api/vendor/autoload.php';
use App\Http\Middleware\PublicOrderTracking;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Route;
function checkTracking($condition, $name) { if (!$condition) throw new RuntimeException($name); echo 'PASS public tracking: '.$name.PHP_EOL; }
$private='private-fixture-canary';
$place=['uuid'=>'place-1','name'=>'Public landmark','address'=>'Public landmark address','location'=>['type'=>'Point','coordinates'=>[106.8272,-6.1754],'secret'=>$private],'phone'=>$private,'security_access_code'=>$private,'meta'=>['secret'=>$private],'company_uuid'=>$private];
$driver=['uuid'=>'driver-1','name'=>'Demo driver','online'=>true,'position'=>['type'=>'Point','coordinates'=>[106.83,-6.17]],'phone'=>$private,'email'=>$private,'password'=>$private,'user'=>['secret'=>$private]];
$fixture=['uuid'=>'order-1','public_id'=>'order_fixture','tracking'=>'TRACK-FIXTURE','status'=>'created','created_at'=>'2026-10-15 01:00:00',
 'internal_id'=>$private,'company_uuid'=>$private,'customer_uuid'=>$private,'notes'=>$private,'comments'=>[['secret'=>$private]],'files'=>[['secret'=>$private]],'meta'=>['secret'=>$private],'transaction_amount'=>$private,'purchase_rate'=>['secret'=>$private],'future_secret'=>$private,
 'driver_assigned'=>$driver,'payload'=>['uuid'=>'payload-1','company_uuid'=>$private,'meta'=>['secret'=>$private],'pickup'=>$place,'dropoff'=>$place,'waypoints'=>[$place],'entities'=>[['uuid'=>'entity-1','name'=>'Demo package','destination_uuid'=>'place-1','price'=>$private,'cost'=>$private,'meta'=>['secret'=>$private]]]],
 'tracking_number'=>['uuid'=>'tracking-1','tracking_number'=>'TRACK-FIXTURE','company_uuid'=>$private],
 'tracking_statuses'=>[['uuid'=>'event-1','status'=>'created','details'=>'Order created','created_at'=>'2026-10-15 01:00:00','meta'=>['secret'=>$private],'created_by_uuid'=>$private]],
 'tracker_data'=>['eta'=>['active_stop_seconds'=>null,'completion_at'=>null,'secret'=>$private],'progress'=>['percentage'=>0,'completed_stops'=>0,'secret'=>$private],'active_stop'=>['address'=>'Public landmark address','meta'=>['secret'=>$private]],'next_stop'=>['address'=>'Second public landmark'],'driver'=>['location'=>['type'=>'Point','coordinates'=>[106.83,-6.17]],'phone'=>$private],'debug'=>$private]];
function trackingRequest($action='lookup', $uri='/int/v1/fleet-ops/lookup') {
 $request=Request::create($uri,'GET',['tracking'=>'TRACK-FIXTURE']);$router=new Illuminate\Routing\Router(new Illuminate\Events\Dispatcher(new Illuminate\Container\Container));$route=$router->get($uri,'Fleetbase\\FleetOps\\Http\\Controllers\\Internal\\v1\\OrderController@'.$action);$request->setRouteResolver(fn()=>$route);return $request;
}
$mw=new PublicOrderTracking;
$response=$mw->handle(trackingRequest(),fn()=>new JsonResponse($fixture,200,['ETag'=>'private-etag','Content-Length'=>'999']));$out=$response->getData(true);
checkTracking(!str_contains($response->getContent(),$private),'recursive private fields omitted, including future fields');
checkTracking($out['uuid']==='order-1' && $out['tracking']==='TRACK-FIXTURE' && $out['status']==='created','tracking identity/status remain compatible');
checkTracking($out['payload']['pickup']['location']['coordinates']===[106.8272,-6.1754] && count($out['payload']['waypoints'])===1,'route geometry and waypoints preserved');
checkTracking($out['driver_assigned']['position']['coordinates']===[106.83,-6.17] && $out['tracker_data']['driver']['location']['coordinates']===[106.83,-6.17],'public driver map geometry preserved');
checkTracking($out['payload']['entities'][0]['destination_uuid']==='place-1' && $out['tracking_statuses'][0]['details']==='Order created','multi-stop item grouping and public activity preserved');
checkTracking(array_key_exists('active_stop_seconds',$out['tracker_data']['eta']) && $out['tracker_data']['eta']['active_stop_seconds']===null,'unknown ETA stays null, not zero');
checkTracking(!$response->headers->has('ETag') && !$response->headers->has('Content-Length') && str_contains($response->headers->get('Cache-Control'),'no-store'),'stale response validators removed and caching disabled');
$internal=new JsonResponse($fixture);$same=$mw->handle(trackingRequest('findRecord'),fn()=>$internal);
checkTracking($same===$internal && $same->getData(true)['notes']===$private,'internal authenticated order contract untouched');
$err=new JsonResponse(['errors'=>['Not found']],404);
checkTracking($mw->handle(trackingRequest(),fn()=>$err)===$err,'error response contract preserved');
$custom=$mw->handle(trackingRequest('lookup','/custom/prefix/lookup'),fn()=>new JsonResponse($fixture));
checkTracking(!str_contains($custom->getContent(),$private),'route action matching works independently of URL prefix');
$bad=$mw->handle(trackingRequest(),fn()=>new Symfony\Component\HttpFoundation\Response('unexpected private text'));
checkTracking($bad->getStatusCode()===502 && !str_contains($bad->getContent(),'unexpected private text'),'unexpected success response type fails closed');
foreach ([[], ['bad']] as $badInput) {
 $req=trackingRequest();$req->query->set('tracking',$badInput);$called=false;
 $bad=$mw->handle($req,function()use(&$called){$called=true;throw new RuntimeException('Invalid input reached controller');});
 checkTracking(!$called && $bad->getStatusCode()===422,'malformed tracking input stopped before controller');
}
echo '13 public tracking boundary checks passed; fixture-only, no database, network or email.'.PHP_EOL;
