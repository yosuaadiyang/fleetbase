<?php
// Run against Laravel's real FormRequest lifecycle, without booting the app/database.
require getenv('CONTRUST_QA_AUTOLOAD') ?: '/fleetbase/api/vendor/autoload.php';
if (!class_exists(Illuminate\Foundation\Http\FormRequest::class)) {
    require getenv('CONTRUST_QA_FORM_REQUEST');
}
if (!function_exists('response')) {
    function response() {
        return Illuminate\Container\Container::getInstance()->make(Illuminate\Contracts\Routing\ResponseFactory::class);
    }
}
if (getenv('CONTRUST_QA_REQUEST_DIR')) {
    require getenv('CONTRUST_QA_CORE_REQUEST');
    foreach (['LoginRequest', 'SignUpRequest', 'TwoFaValidationRequest'] as $class) {
        require getenv('CONTRUST_QA_REQUEST_DIR') . '/' . $class . '.php';
    }
}
$container = new Illuminate\Container\Container();
Illuminate\Container\Container::setInstance($container);
$translator = new Illuminate\Translation\Translator(new Illuminate\Translation\ArrayLoader(), 'en');
$factory = new Illuminate\Validation\Factory($translator, $container);
$factory->setPresenceVerifier(new class implements Illuminate\Validation\PresenceVerifierInterface {
    public function getCount($collection, $column, $value, $excludeId = null, $idColumn = null, $extra = []) { return 1; }
    public function getMultiCount($collection, $column, array $values, array $extra = []) { return count($values); }
});
$container->instance(Illuminate\Contracts\Validation\Factory::class, $factory);
$container->instance('Illuminate\Contracts\Routing\ResponseFactory', new Illuminate\Routing\ResponseFactory(
    new class implements Illuminate\Contracts\View\Factory {
        public function exists($view) { return false; }
        public function file($path, $data = [], $mergeData = []) { throw new LogicException(); }
        public function make($view, $data = [], $mergeData = []) { throw new LogicException(); }
        public function share($key, $value = null) { return null; }
        public function composer($views, $callback) { return []; }
        public function creator($views, $callback) { return []; }
        public function addNamespace($namespace, $hints) { return $this; }
        public function replaceNamespace($namespace, $hints) { return $this; }
    }, new Illuminate\Routing\Redirector(new Illuminate\Routing\UrlGenerator(new Illuminate\Routing\RouteCollection(), Illuminate\Http\Request::create('/')))
));

$cases = [
    ['LoginRequest', [], false],
    ['SignUpRequest', [], false],
    ['TwoFaValidationRequest', [], false],
    ['LoginRequest', ['identity'=>['qa@example.test'], 'password'=>'fake-test'], false],
    ['LoginRequest', ['identity'=>'qa@example.test', 'password'=>['fake-test']], false],
    ['LoginRequest', ['identity'=>123, 'password'=>'fake-test'], false],
    ['LoginRequest', ['identity'=>'qa@example.test', 'password'=>123], false],
    ['SignUpRequest', ['user'=>['name'=>['array']]], false],
    ['SignUpRequest', ['company'=>['name'=>['array']]], false],
    ['TwoFaValidationRequest', ['token'=>['array'], 'identity'=>'qa@example.test'], false],
    ['LoginRequest', ['identity'=>'qa@example.test', 'password'=>'fake-test'], true],
    ['LoginRequest', ['identity'=>'+6281234567890', 'password'=>'fake-test'], true],
];
$failures=0;
$invalidFields=['identity','user.name','token','identity','password','identity','password','user.name','company.name','token'];
foreach ($cases as $index=>[$class,$input,$expectedValid]) {
    $class='Fleetbase\\Http\\Requests\\'.$class;
    $request=$class::create('/int/v1/auth/login','POST',$input);
    $request->setContainer($container);
    $continued=false; $status=null; $errorShape=false;
    try {
        $request->validateResolved();
        $continued=true; // Laravel reaches the controller only if this lifecycle returns.
    } catch (Illuminate\Http\Exceptions\HttpResponseException $exception) {
        $response=$exception->getResponse();
        $status=$response->getStatusCode();
        $errorShape=is_array($response->getData(true)['errors'] ?? null);
    }
    $pass=$expectedValid ? $continued : (!$continued && $status===422 && $errorShape);
    if (!$expectedValid) {
        $validatorProperty=new ReflectionProperty(Illuminate\Foundation\Http\FormRequest::class,'validator');
        $validatorProperty->setAccessible(true);
        $pass=$pass && $validatorProperty->getValue($request)->errors()->has($invalidFields[$index]);
    }
    if (!$pass) $failures++;
    echo ($pass?'PASS':'FAIL').' auth validation case '.($index+1).PHP_EOL;
}
if ($failures) throw new RuntimeException($failures.' validation lifecycle checks failed');
echo count($cases).' real Laravel validation checks passed; no DB or email access.'.PHP_EOL;
