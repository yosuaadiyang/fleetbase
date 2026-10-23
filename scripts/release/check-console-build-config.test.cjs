const { test } = require('node:test');
const assert = require('node:assert/strict');
const { mkdtempSync, mkdirSync, writeFileSync, rmSync } = require('node:fs');
const { tmpdir } = require('node:os');
const { join } = require('node:path');
const { spawnSync } = require('node:child_process');
const source = 'https://github.com/yosuaadiyang/fleetbase/tree/' + 'a'.repeat(40);
const config = () => ({modulePrefix:'@fleetbase/console',environment:'production',API:{host:'https://api.contrust.website',namespace:'int/v1'},osrm:{host:'https://api.contrust.website/routing'},socket:{hostname:'api.contrust.website',secure:true,port:'443',path:'/socketcluster/'},APP:{disableRuntimeConfig:true,autoboot:true,sourceCodeUrl:source}});
function check(change, prefix = '') {
 const root=mkdtempSync(join(tmpdir(),'contrust-console-config-'));
 try {
  mkdirSync(join(root,'assets/@fleetbase'),{recursive:true});const c=config();change(c);
  writeFileSync(join(root,'assets/@fleetbase/console-abc123.js'),prefix+'define("@fleetbase/console/config/environment",[],function(){var e={default:'+JSON.stringify(c)+'};return e;});');
  return spawnSync(process.execPath,[join(__dirname,'check-console-build-config.cjs'),root],{env:{...process.env,SOURCE_CODE_URL:source},encoding:'utf8'});
 } finally {rmSync(root,{recursive:true,force:true});}
}
test('checks a production artifact without executing its code, including getenv string port',()=>{const r=check(()=>{},'throw new Error("Never execute shipped code");');assert.equal(r.status,0,r.stderr);assert.equal(JSON.parse(r.stdout).production_config_verified,true);});
test('accepts equivalent numeric secure socket port',()=>assert.equal(check(c=>{c.socket.port=443}).status,0));
for (const [name,change] of [
 ['relative API returning the HTML shell',c=>{c.API.host=null}],
 ['HTTP API',c=>{c.API.host='http://api.contrust.website'}],
 ['wrong namespace',c=>{c.API.namespace='v1'}],
 ['demo routing',c=>{c.osrm.host='https://router.project-osrm.org'}],
 ['insecure socket',c=>{c.socket.secure=false}],
 ['invalid socket port',c=>{c.socket.port='443junk'}],
 ['missing socket host',c=>{delete c.socket.hostname}],
 ['runtime override enabled',c=>{c.APP.disableRuntimeConfig=false}],
 ['wrong source commit',c=>{c.APP.sourceCodeUrl=source.replace(/a{40}$/,'b'.repeat(40))}],
 ['development artifact',c=>{c.environment='development'}],
]) test('rejects '+name,()=>assert.notEqual(check(change).status,0));
