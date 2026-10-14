"""Probe an isolated routing proxy with synthetic road coordinates."""
import concurrent.futures,json,sys,time,urllib.request,urllib.error
endpoint=(sys.argv[1] if len(sys.argv)>1 else 'http://127.0.0.1:15003').rstrip('/')
url=endpoint+'/route/v1/driving/106.8272,-6.1754;106.8400,-6.1850?overview=false'
def request(method='GET',path=url):
    req=urllib.request.Request(path,method=method,headers={'Origin':'https://contrust.website'})
    try:
        with urllib.request.urlopen(req,timeout=5) as response:return response.status,dict(response.headers),response.read()
    except urllib.error.HTTPError as response:return response.code,dict(response.headers),response.read()
for attempt in range(30):
    try:
        status,headers,body=request();break
    except OSError:
        if attempt==29:raise
        time.sleep(.2)
assert status==200 and json.loads(body)['code']=='Ok'
assert headers['Access-Control-Allow-Origin']=='https://contrust.website'
assert headers['Cache-Control']=='no-store'
assert request('OPTIONS')[0]==204
assert request('POST')[0]==403
assert request(path=endpoint+'/admin')[0]==404
with concurrent.futures.ThreadPoolExecutor(max_workers=8) as executor:
    statuses=list(executor.map(lambda _:request()[0],range(45)))
assert 429 in statuses and all(s in (200,429) for s in statuses),statuses
print(json.dumps({'status':'passed','route':200,'options':204,'post':403,'unknown_path':404,'rate_limited_responses':statuses.count(429),'synthetic_queries':True}))
