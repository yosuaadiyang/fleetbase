import io,json,pathlib,tempfile,unittest,urllib.error
from unittest.mock import patch
import alerts,monitor
class Response(io.BytesIO):
    status=201
    def __enter__(self):return self
    def __exit__(self,*args):self.close()
class MonitorTest(unittest.TestCase):
    def state(self,failures=0):return {'status':'degraded' if failures else 'ok','consecutive_failures':failures,'checked_at':'synthetic','checks':[{'name':'synthetic-api','passed':not failures}]}
    def test_absent_config_never_sends(self):
        r=self.state(2);alerts.apply({},r,None,lambda *args:self.fail('Unexpected notification'));self.assertFalse(r['notifications_configured'])
    def test_first_failure_does_not_send(self):
        r=self.state(1);alerts.apply({},r,({},'synthetic'),lambda *args:self.fail('Early notification'))
    def test_incident_is_sent_once_then_recovery(self):
        calls=[]
        def send(kind,*args):calls.append(kind);return {'accepted':True,'http':201}
        first=self.state(2);alerts.apply({},first,({},'synthetic'),send)
        again=self.state(3);alerts.apply(first,again,({},'synthetic'),send)
        recovered=self.state();alerts.apply(again,recovered,({},'synthetic'),send)
        self.assertEqual(calls,['incident','recovery']);self.assertFalse(recovered['incident_notified'])
    def test_failed_notification_is_not_marked_delivered(self):
        r=self.state(2);alerts.apply({},r,({},'synthetic'),lambda *args:{'accepted':False,'http':403});self.assertFalse(r['incident_notified'])
    def test_transport_contains_no_credentials_in_result(self):
        def fake(request,timeout):
            self.assertEqual(request.full_url,'https://api.brevo.com/v3/smtp/email');self.assertEqual(timeout,8)
            body=json.loads(request.data);self.assertEqual(body['to'][0]['email'],'qa@example.test');return Response(b'{}')
        r=alerts.send('incident',self.state(2),({'recipient':'qa@example.test','sender':'sender@example.test'},'synthetic-private-key'),fake)
        self.assertTrue(r['accepted']);self.assertNotIn('synthetic-private-key',json.dumps(r))
    def test_transport_rejects_provider_error_without_body(self):
        def fake(*args,**kwargs):raise urllib.error.HTTPError('https://api.brevo.com',403,'forbidden',{},None)
        self.assertEqual(alerts.send('incident',self.state(2),({'recipient':'qa@example.test','sender':'sender@example.test'},'private'),fake)['http'],403)
    def test_redirects_cannot_forward_the_key(self):
        self.assertIsNone(alerts.NoRedirect().redirect_request(None,None,302,'moved',{},'https://other.invalid'))
    def test_private_key_permissions(self):
        with tempfile.TemporaryDirectory() as d:
            p=pathlib.Path(d)/'key';p.write_text('synthetic');p.chmod(0o644)
            with self.assertRaises(PermissionError):alerts.private_text(p)
            p.chmod(0o600);self.assertEqual(alerts.private_text(p),'synthetic')
    def test_health_responses(self):
        for body,ok in [(b'{"status":"ok"}',True),(b'{"status":"bad"}',False),(b'broken',False)]:
            def fake(*args,**kwargs):r=Response(body);r.status=200;return r
            self.assertEqual(monitor.check_endpoint('api','https://example.test','health',fake)['passed'],ok)
    def test_oversized_response_rejected(self):
        def fake(*args,**kwargs):r=Response(b'x'*65537);r.status=200;return r
        self.assertFalse(monitor.check_endpoint('api','https://example.test','health',fake)['passed'])
if __name__=='__main__':unittest.main()
