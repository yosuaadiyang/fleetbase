'use strict';
const fs = require('fs');
const http = require('http');
const WebSocket = require('ws');
const sc = require('socketcluster-server');
const {createAuthorizer, installSecurity, cookieTicket, equalSecret} = require('/usr/src/security');
const state = JSON.parse(fs.readFileSync('/qa/app/release-qa/socket-transport.json'));
const checks = [];
function check(name, passed) { checks.push({name, passed: Boolean(passed)}); }
let revoke = false;
const api = createAuthorizer({secret: state.publisher_key, url:'http://application:8000/int/v1/contrust/socket-authorize'});
const httpServer = http.createServer();
const server = sc.attach(httpServer, {allowClientPublish:true, socketChannelLimit:10, maxPayload:65536});
installSecurity(server, {secret:state.publisher_key, authorize: (ticket, channel) => revoke ? Promise.resolve(false) : api(ticket, channel)});
let cid = 0;
const clients = [];
async function client(ticket, key) {
  const headers = {};
  if (ticket) headers.Cookie = 'contrust_socket='+ticket;
  if (key) headers['X-Contrust-Socket-Publisher'] = key;
  const ws = new WebSocket('ws://127.0.0.1:8123/socketcluster/', {headers});
  clients.push(ws);
  const responses = new Map(); const events = [];
  ws.on('message', data => {
    if (data.toString() === '') { ws.send(''); return; }
    try { const packet = JSON.parse(data); if (packet.rid && responses.has(packet.rid)) { responses.get(packet.rid)(packet); responses.delete(packet.rid); }
      else if (packet.event === '#publish') events.push(packet.data); } catch {}
  });
  await new Promise((resolve,reject)=>{ws.once('open',resolve);ws.once('error',reject);});
  function request(event,data) {
    const id = ++cid;
    return new Promise((resolve,reject)=>{
      const timer=setTimeout(()=>{responses.delete(id);reject(new Error('Socket contract timeout'));},4000);
      responses.set(id,p=>{clearTimeout(timer);resolve(p);});
      ws.send(JSON.stringify({event,data,cid:id}));
    });
  }
  await request('#handshake',{});
  return {ws,request,events};
}
const sleep = ms => new Promise(resolve=>setTimeout(resolve,ms));
(async()=>{
  await new Promise(resolve=>httpServer.listen(8123,'127.0.0.1',resolve));
  check('duplicate socket cookie rejected',cookieTicket('contrust_socket='+'a'.repeat(64)+'; contrust_socket='+'b'.repeat(64))===null);
  check('malformed cookie rejected',cookieTicket('contrust_socket=invalid')===null);
  check('publisher key length checked',!equalSecret('a','a'.repeat(64)));
  const anonymous = await client();
  check('anonymous subscription denied',Boolean((await anonymous.request('#subscribe',{channel:state.own_channel})).error));
  const owner=await client(state.tickets.owner_a);
  check('own organization order subscription allowed',!(await owner.request('#subscribe',{channel:state.own_channel})).error);
  check('foreign organization channel denied',Boolean((await owner.request('#subscribe',{channel:state.foreign_channel})).error));
  check('authenticated client cannot publish',Boolean((await owner.request('#publish',{channel:state.own_channel,data:{qa:true}})).error));
  const driver=await client(state.tickets.driver_a);
  check('assigned driver subscription allowed',!(await driver.request('#subscribe',{channel:state.own_channel})).error);
  check('driver foreign channel denied',Boolean((await driver.request('#subscribe',{channel:state.foreign_channel})).error));
  const revoked=await client(state.tickets.driver_a_other);
  check('revoked token subscription denied',Boolean((await revoked.request('#subscribe',{channel:state.own_channel})).error));
  const expired=await client(state.tickets.driver_b);
  check('expired token subscription denied',Boolean((await expired.request('#subscribe',{channel:state.own_channel})).error));
  const fakePublisher=await client(null,'a'.repeat(64));
  check('forged publisher denied',Boolean((await fakePublisher.request('#publish',{channel:state.own_channel,data:{qa:true}})).error));
  const publisher=await client(null,state.publisher_key);
  const sent=await publisher.request('#publish',{channel:state.own_channel,data:{synthetic_contract:'positive'}});
  for (let i=0;i<40 && (!owner.events.length || !driver.events.length);i++) await sleep(100);
  check('trusted publisher accepted',!sent.error);
  check('authorized dispatcher receives event',owner.events.some(e=>e.data.synthetic_contract==='positive'));
  check('assigned driver receives event',driver.events.some(e=>e.data.synthetic_contract==='positive'));
  revoke=true;
  await publisher.request('#publish',{channel:state.own_channel,data:{synthetic_contract:'blocked'}});
  await sleep(300);
  check('existing subscription rechecked before delivery',!owner.events.some(e=>e.data.synthetic_contract==='blocked'));
  check('invalidated connection closed',owner.ws.readyState!==WebSocket.OPEN);
  const failed=checks.filter(c=>!c.passed).length;
  process.stdout.write(JSON.stringify({environment:'isolated-synthetic-socket',checks,passed:checks.length-failed,failed,credentials_printed:false})+'\n');
  process.exitCode=failed?1:0;
})().catch(()=>{console.error('Socket transport contract failed; sensitive output suppressed');process.exitCode=1;}).finally(()=>{
  clients.forEach(ws=>ws.terminate()); httpServer.close(); setTimeout(()=>process.exit(process.exitCode||0),50);
});
