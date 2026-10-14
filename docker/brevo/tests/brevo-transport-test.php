<?php
// The official bridge with real Symfony MIME/HTTP classes and a mocked provider.
require '/fleetbase/api/vendor/autoload.php';
require '/fleetbase/api/app/Mail/BrevoApiTransport.php';
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\Bridge\Brevo\Transport\BrevoApiTransport;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Address;

function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
$requests=[];
$client=new MockHttpClient(function ($method,$url,$options) use (&$requests) {
    $requests[]=[$method,$url,$options];
    return new MockResponse('{"messageId":"qa-mocked-message"}',['http_code'=>201]);
});
$transport=new BrevoApiTransport('fixture-key-only',$client);
$email=(new Email())->from(new Address('sender@example.test','Contrust'))
    ->to('recipient@example.test')->cc('cc@example.test')->bcc('bcc@example.test')
    ->replyTo('reply@example.test')->subject('QA reset fixture')
    ->text('Test message')->html('<p>Test message</p>')->attach('fixture content','fixture.txt');
$sent=$transport->send($email);
[$method,$url,$options]=$requests[0];
$payload=json_decode($options['body'],true,512,JSON_THROW_ON_ERROR);
check($method==='POST' && $url==='https://api.brevo.com/v3/smtp/email','Unexpected API destination');
check($sent->getMessageId()==='qa-mocked-message','Provider message id lost');
check($payload['sender']['email']==='sender@example.test' && $payload['sender']['name']==='Contrust','Sender lost');
check(count($payload['to'])===1 && $payload['to'][0]['email']==='recipient@example.test','Recipient lost');
check($payload['cc'][0]['email']==='cc@example.test' && $payload['bcc'][0]['email']==='bcc@example.test','Cc/bcc lost');
check($payload['replyTo']['email']==='reply@example.test','Reply-to lost');
check($payload['htmlContent']==='<p>Test message</p>' && $payload['textContent']==='Test message','Body lost');
check(base64_decode($payload['attachment'][0]['content'])==='fixture content','Attachment lost');
check(strpos((string)$transport,'fixture-key-only')===false,'Transport string exposes key');
echo 'PASS: MIME envelope/body/attachment, HTTPS endpoint, message id and secret-free transport string'.PHP_EOL;
foreach ([401,403,429,500] as $status) {
    $client=new MockHttpClient(new MockResponse('{"message":"mock rejection"}',['http_code'=>$status]));
    $failed=false;
    try { (new BrevoApiTransport('fixture-key-only',$client))->send($email); }
    catch (Symfony\Component\Mailer\Exception\TransportExceptionInterface $e) { $failed=true; }
    check($failed,'Provider HTTP '.$status.' must not report delivery success');
    echo 'PASS: provider HTTP '.$status.' is a transport failure'.PHP_EOL;
}
echo 'Brevo transport tests passed; all responses mocked, no emails or database access.'.PHP_EOL;
