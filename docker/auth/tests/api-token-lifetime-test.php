<?php
// Real Sanctum models, SQLite memory only; no application bootstrap or HTTP.
require '/fleetbase/api/vendor/autoload.php';
require __DIR__.'/fixtures/container.php';
require __DIR__.'/fixtures/login-bootstrap.php';
Illuminate\Container\Container::setInstance(new FleetbaseTestContainer());
app()->instance('session', new Illuminate\Session\Store('qa', new Illuminate\Session\ArraySessionHandler(60)));
$capsule=auth_controller_login_bootstrap_database();
auth_controller_login_insert_user($capsule);
$user=Fleetbase\Models\User::find('11111111-1111-4111-8111-111111111111');
$middleware=new Fleetbase\Http\Middleware\AuthenticateOnceWithBasicAuth();
$method=new ReflectionMethod($middleware,'getSanctumToken');
$count=0;
function checkApiToken($ok,$name){global $count;if(!$ok)throw new RuntimeException($name);$count++;echo 'PASS API token lifetime: '.$name.PHP_EOL;}
config(['sanctum.expiration'=>null]);
$fresh=$user->createToken('qa-fresh')->plainTextToken;
checkApiToken($method->invoke($middleware,$fresh) instanceof Laravel\Sanctum\PersonalAccessToken,'fresh token retained');
$expired=$user->createToken('qa-expired',['*'],now()->subMinute())->plainTextToken;
checkApiToken($method->invoke($middleware,$expired)===null,'explicit expiration rejected');
checkApiToken($method->invoke($middleware,'nonexistent-fixture')===null,'unknown token rejected');
config(['sanctum.expiration'=>1]);
checkApiToken($method->invoke($middleware,$fresh) instanceof Laravel\Sanctum\PersonalAccessToken,'fresh token within global lifetime');
Laravel\Sanctum\PersonalAccessToken::findToken($fresh)->forceFill(['created_at'=>now()->subMinutes(2)])->save();
checkApiToken($method->invoke($middleware,$fresh)===null,'global expiration rejected');
config(['sanctum.expiration'=>0]);
checkApiToken($method->invoke($middleware,$fresh) instanceof Laravel\Sanctum\PersonalAccessToken,'zero global expiration follows Sanctum semantics');
config(['sanctum.expiration'=>'invalid-configuration']);
checkApiToken($method->invoke($middleware,$fresh)===null,'invalid lifetime configuration fails closed');
config(['sanctum.expiration'=>null]);
Laravel\Sanctum\PersonalAccessToken::findToken($fresh)->delete();
checkApiToken($method->invoke($middleware,$fresh)===null,'revoked token rejected');
echo $count.' API token lifetime checks passed; SQLite memory only.'.PHP_EOL;
