<?php
try {
 require '/fleetbase/api/vendor/autoload.php';
 $container=new Illuminate\Container\Container();
 Illuminate\Container\Container::setInstance($container);
 $container->instance('config',new Illuminate\Config\Repository(['fleetbase'=>['connection'=>['db'=>'mysql']]]));
 Illuminate\Support\Facades\Facade::setFacadeApplication($container);
 $db=new Illuminate\Database\Capsule\Manager($container);
 $db->addConnection(['driver'=>'sqlite','database'=>':memory:','prefix'=>''],'mysql');
 $db->setAsGlobal();$db->bootEloquent();
 // No cache/HTTP/event side effects; this fixture verifies real model mutation
 // and persistence using only an in-memory database, never application boot.
 Illuminate\Database\Eloquent\Model::unsetEventDispatcher();
 $db->getConnection('mysql')->getSchemaBuilder()->create('login_attempts',function($t){$t->string('uuid')->primary();$t->string('identity')->nullable();$t->string('password')->nullable();$t->timestamps();$t->softDeletes();});
 $checks=0;$verify=function($v)use(&$checks){if(!$v)throw new RuntimeException('PasswordPersistenceRegression');$checks++;};
 $a=new Fleetbase\Models\LoginAttempt(['identity'=>'synthetic@example.invalid','password'=>'synthetic-password-guess']);
 $a->forceFill(['uuid'=>'11111111-1111-4111-8111-111111111111'])->save();
 $verify($a->password===null);
 $verify($a->getRawOriginal('password')===null);
 $verify($db->getConnection('mysql')->table('login_attempts')->where('uuid',$a->uuid)->value('password')===null);
 $a->password='another-synthetic-guess';$a->save();
 $verify($db->getConnection('mysql')->table('login_attempts')->where('uuid',$a->uuid)->value('password')===null);
 $a->forceFill(['password'=>'force-filled-synthetic-guess'])->save();
 $verify($db->getConnection('mysql')->table('login_attempts')->where('uuid',$a->uuid)->value('password')===null);
 $b=new Fleetbase\Models\LoginAttempt();$b->forceFill(['uuid'=>'22222222-2222-4222-8222-222222222222','identity'=>'second@example.invalid','password'=>'create-synthetic-guess'])->save();
 $verify($db->getConnection('mysql')->table('login_attempts')->where('uuid',$b->uuid)->value('password')===null);
 $verify($a->identity==='synthetic@example.invalid');
 $verify(in_array('password',$a->getHidden(),true)&&in_array('identity',$a->getHidden(),true));
 $verify($db->getConnection('mysql')->table('login_attempts')->count()===2);
 echo json_encode(['passed'=>true,'checks'=>$checks,'database'=>'isolated_sqlite_memory','production_database_accessed'=>false,'notifications_sent'=>false]).PHP_EOL;
} catch(Throwable $e){echo json_encode(['passed'=>false,'failure_class'=>get_class($e),'failure_code'=>$e instanceof RuntimeException&&$e->getMessage()==='PasswordPersistenceRegression'?'PASSWORD_PERSISTED':'FIXTURE_SETUP_FAILED','details_withheld'=>true,'production_database_accessed'=>false]).PHP_EOL;exit(1);}
