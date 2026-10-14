<?php
// Actual vendor controller/models; in-memory SQLite, without app bootstrap.
require '/fleetbase/api/vendor/autoload.php';
require __DIR__.'/fixtures/container.php';
require __DIR__.'/fixtures/login-bootstrap.php';
Illuminate\Container\Container::setInstance(new FleetbaseTestContainer());
Illuminate\Container\Container::getInstance()->instance('session', new Illuminate\Session\Store('qa', new Illuminate\Session\ArraySessionHandler(60)));
$capsule = auth_controller_login_bootstrap_database();
app()->instance(Illuminate\Contracts\Routing\ResponseFactory::class, new AcceptCompanyInviteTestResponseFactory());
config(['sanctum.expiration'=>null]);
auth_controller_login_insert_user($capsule);
auth_controller_login_insert_user($capsule,['uuid'=>'22222222-2222-4222-8222-222222222222','email'=>'other@example.test','phone'=>'+15555550124']);
$user=Fleetbase\Models\User::find('11111111-1111-4111-8111-111111111111');
$controller=new Fleetbase\Http\Controllers\Internal\v1\AuthController();
function checkLogin($controller,$input,$status,$name,$expectedToken=null) {
 $response=$controller->login(auth_controller_login_request($input));$payload=$response->getData(true);
 if ($response->getStatusCode()!==$status || ($status!==200 && isset($payload['token'])) || ($expectedToken!==null && ($payload['token']??null)!==$expectedToken)) throw new RuntimeException('Login contract failed: '.$name);
 echo 'PASS token login: '.$name.PHP_EOL;return $payload;
}
$first=checkLogin($controller,['identity'=>'auth@example.test','password'=>'correct-password'],200,'password first step');$token=$first['token'];
$count=$capsule->getConnection('mysql')->table('personal_access_tokens')->count();
checkLogin($controller,['identity'=>'auth@example.test','authToken'=>$token],200,'token-only session second step',$token);
if ($capsule->getConnection('mysql')->table('personal_access_tokens')->count()!==$count) throw new RuntimeException('Token reuse issued extra token');
checkLogin($controller,['identity'=>'auth@example.test','authToken'=>'nonexistent-fixture'],401,'invalid token without password');
checkLogin($controller,['identity'=>'other@example.test','authToken'=>$token],401,'wrong owner existing account');
checkLogin($controller,['identity'=>'unknown@example.test','authToken'=>$token],401,'wrong owner unknown account');
$expired=$user->createToken('qa-expired',['*'],now()->subMinute())->plainTextToken;
checkLogin($controller,['identity'=>'auth@example.test','authToken'=>$expired],401,'explicitly expired token');
config(['sanctum.expiration'=>1]);
Laravel\Sanctum\PersonalAccessToken::findToken($token)->forceFill(['created_at'=>now()->subMinutes(2)])->save();
checkLogin($controller,['identity'=>'auth@example.test','authToken'=>$token],401,'global token lifetime exceeded');
config(['sanctum.expiration'=>null]);
checkLogin($controller,['identity'=>'auth@example.test','authToken'=>'nonexistent-fixture','password'=>'wrong-password'],401,'invalid token wrong password');
checkLogin($controller,['identity'=>'auth@example.test','authToken'=>'nonexistent-fixture','password'=>'correct-password'],200,'invalid token valid password fallback');
$capsule->getConnection('mysql')->table('users')->where('uuid',$user->uuid)->update(['type'=>'customer']);
$fresh=$user->createToken('qa-role')->plainTextToken;
checkLogin($controller,['identity'=>'auth@example.test','authToken'=>$fresh],403,'customer blocked from console');
echo '10 real-controller login checks passed; SQLite memory only, no network or email.'.PHP_EOL;
