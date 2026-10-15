<?php
namespace WebSocket {
    class Client {
        public static bool $deny = false;
        private array $last=[];
        public function __construct($uri,$options) {}
        public function send($packet) { $this->last=['cid'=>$packet->cid]; }
        public function receive() {return json_encode(['rid'=>$this->last['cid'],'error'=>self::$deny?['name'=>'ForbiddenError']:null]);}
        public function close() {}
    }
}
namespace {
    require '/fleetbase/api/vendor/autoload.php';
    $container=new Illuminate\Container\Container();
    Illuminate\Container\Container::setInstance($container);
    $container->instance('config',new Illuminate\Config\Repository(['app'=>['key'=>'synthetic-test-key'],'broadcasting'=>['connections'=>['socketcluster'=>['options'=>['host'=>'socket','port'=>8000,'secure'=>false,'path'=>'/socketcluster/']]]]]));
    $service=new Fleetbase\Support\SocketCluster\SocketClusterService();
    $options=$service->getOptions([]);
    if (($options['headers']['X-Contrust-Socket-Publisher']??null)!==hash_hmac('sha256','contrust.socket.publisher.v1','synthetic-test-key')) throw new RuntimeException('Trusted publisher missing capability');
    foreach([['host'=>'untrusted.example'],['port'=>9000],['secure'=>true]] as $override){
        if(isset($service->getOptions($override)['headers']['X-Contrust-Socket-Publisher'])) throw new RuntimeException('Publisher capability forwarded to untrusted endpoint');
    }
    if(!$service->send('order.synthetic',['qa'=>true]))throw new RuntimeException('Successful publisher ack rejected');
    WebSocket\Client::$deny=true;
    if($service->send('order.synthetic',['qa'=>true]))throw new RuntimeException('Denied ack reported as successful');
    echo "PASS socket publisher: trusted target only, positive ack required, denial propagated; no network\n";
}
