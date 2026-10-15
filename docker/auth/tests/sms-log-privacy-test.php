<?php
require '/fleetbase/api/vendor/autoload.php';
$container = new Illuminate\Container\Container();
Illuminate\Container\Container::setInstance($container);
Illuminate\Support\Facades\Facade::setFacadeApplication($container);
$container->instance('config', new Illuminate\Config\Repository(['sms' => ['default_provider' => 'twilio', 'routing_rules' => []]]));
$logger = new class extends Psr\Log\AbstractLogger {
    public array $records = [];
    public function log($level, $message, array $context = []): void {
        $this->records[] = ['level'=>$level,'message'=>$message,'context'=>$context];
    }
};
Illuminate\Support\Facades\Log::swap($logger);
$phone = '+628119999999';
$message = 'Your private OTP is 123456; secret-body-must-not-be-logged';
$service = new Fleetbase\Services\SmsService();
$failed = false;
try { $service->send($phone, $message, [], 'not-a-real-provider'); }
catch (InvalidArgumentException $exception) { $failed = true; }
$records = json_encode($logger->records);
if (!$failed || !$logger->records || str_contains($records, $phone) || str_contains($records, '123456') || str_contains($records, 'secret-body')) {
    throw new RuntimeException('SMS logging exposed recipient/message or did not exercise failure path');
}
echo "PASS real SMS service failure path logs no message, OTP or recipient; no network or SMS sent\n";
