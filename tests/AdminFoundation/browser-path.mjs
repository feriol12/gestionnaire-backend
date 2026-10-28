import {spawn,spawnSync} from 'node:child_process';
import {writeFile} from 'node:fs/promises';
import path from 'node:path';
const php='C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe';
const phpArgs=['-n','-d','extension_dir=C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/ext',...['mbstring','openssl','fileinfo','pdo_sqlite'].flatMap(x=>['-d',`extension=${x}`]),'tests/AdminFoundation/browser-bridge.php'];
const chrome=spawn('C:/Program Files/Google/Chrome/Application/chrome.exe',[
 '--headless=new','--disable-gpu','--remote-debugging-pipe',`--user-data-dir=${path.resolve('storage/app/admin-harden-09/chrome-profile')}`,
 '--no-first-run','--disable-background-networking','--disable-component-update','--disable-sync','--no-default-browser-check',
 '--proxy-server=http://127.0.0.1:9','--proxy-bypass-list=<-loopback>','--host-resolver-rules=MAP * ~NOTFOUND','about:blank'
],{stdio:['ignore','ignore','ignore','pipe','pipe'],windowsHide:true});
let seq=0,buffer='';const pending=new Map();const results=[],traffic=[];
function send(method,params={},sessionId){return new Promise((resolve,reject)=>{const id=++seq; const timer=setTimeout(()=>{pending.delete(id);reject(new Error(`Timeout ${method}`));},15000);pending.set(id,{resolve,reject,timer});chrome.stdio[3].write(JSON.stringify({id,method,params,...(sessionId?{sessionId}:{})})+'\0');});}
chrome.stdio[4].on('data',chunk=>{buffer+=chunk.toString();let index;while((index=buffer.indexOf('\0'))>=0){const message=JSON.parse(buffer.slice(0,index));buffer=buffer.slice(index+1);if(message.id){const p=pending.get(message.id);if(p){clearTimeout(p.timer);pending.delete(message.id);message.error?p.reject(new Error(JSON.stringify(message.error))):p.resolve(message.result);}}else if(message.method==='Fetch.requestPaused'){fulfill(message).catch(e=>{console.error(e);process.exitCode=1;chrome.kill();});}}});
async function fulfill(m){
 const {request,requestId}=m.params;const host=new URL(request.url).hostname;
 if(!['client.foundation.test','admin.foundation.test'].includes(host)){traffic.push({url:request.url,blocked:true});await send('Fetch.failRequest',{requestId,errorReason:'BlockedByClient'},m.sessionId);return;}
 const pathname=new URL(request.url).pathname;
 if(['/client-ui','/api/admin/ui'].includes(pathname)) {await send('Fetch.fulfillRequest',{requestId,responseCode:200,responseHeaders:[{name:'Content-Type',value:'text/html'}],body:Buffer.from('<!doctype html><title>Local POC fixture</title><p>Fictional interface</p>').toString('base64')},m.sessionId);return;}
 const run=spawnSync(php,phpArgs,{cwd:process.cwd(),input:JSON.stringify(request),encoding:'utf8',windowsHide:true,timeout:10000});
 if(run.status!==0)throw new Error(`PHP bridge ${run.status} ${run.stderr} ${run.stdout}`);
 const response=JSON.parse(run.stdout);traffic.push({host,path:pathname,method:request.method,status:response.status,cookieNames:(request.headers.Cookie??request.headers.cookie??'').split(';').filter(Boolean).map(v=>v.trim().split('=')[0])});
 await send('Fetch.fulfillRequest',{requestId,responseCode:response.status,responseHeaders:response.headers,body:response.body},m.sessionId);
}
const check=(label,actual,expected)=>{const pass=JSON.stringify(actual)===JSON.stringify(expected);results.push({label,actual,expected,status:pass?'PASS':'FAIL'});console.log(`${pass?'PASS':'FAIL'} ${label} ${JSON.stringify(actual)}`);};
async function page(){const {browserContextId}=await send('Target.createBrowserContext');const {targetId}=await send('Target.createTarget',{url:'about:blank',browserContextId});const {sessionId}=await send('Target.attachToTarget',{targetId,flatten:true});await send('Fetch.enable',{patterns:[{urlPattern:'*'}]},sessionId);await send('Runtime.enable',{},sessionId);await send('Page.enable',{},sessionId);return {sessionId,browserContextId};}
async function evaluate(s,expression){const r=await send('Runtime.evaluate',{expression,awaitPromise:true,returnByValue:true},s);if(r.exceptionDetails)throw new Error(JSON.stringify(r.exceptionDetails));return r.result.value;}
async function navigate(s,url){await send('Page.navigate',{url},s);for(let i=0;i<40;i++){if(await evaluate(s,`location.href===${JSON.stringify(url)} && document.readyState==='complete'`))return;await new Promise(r=>setTimeout(r,50));}throw new Error('navigation timeout');}
try {
 const version=await send('Browser.getVersion');
 const p=await page();await navigate(p.sessionId,'https://client.foundation.test/client-ui');
 const call=async(uri,data,token)=>evaluate(p.sessionId,`(async()=>{const r=await fetch(${JSON.stringify(uri)},{method:${JSON.stringify(data?'POST':'GET')},headers:${JSON.stringify(data?{'Content-Type':'application/json','X-CSRF-TOKEN':token}: {})},body:${data?JSON.stringify(JSON.stringify(data)):'undefined'}});return {status:r.status,data:await r.json().catch(()=>({}))}})()`);
 let r=await call('/api/admin/fixture/csrf');check('initial CSRF',r.status,200);let csrf=r.data.csrf;
 const adminCookie=async()=> (await send('Network.getCookies',{urls:['https://client.foundation.test/api/admin/fixture/who']},p.sessionId)).cookies.find(c=>c.name==='fluxmoneva_admin_session');
 const before=await adminCookie();check('cookie path',before.path,'/api/admin');check('no __Host prefix',before.name.startsWith('__Host-'),false);
 check('host-only cookie domain',before.domain,'client.foundation.test');
 for(const uri of ['/api/admin','/api/admin/','/api/admin/fixture/who','/api/administer','/api/user','/admin/fixture/who']) {
   await call(uri);const record=traffic.filter(x=>x.path===uri).at(-1);
   check(`browser cookie transmission ${uri}`,record.cookieNames.includes('fluxmoneva_admin_session'),uri==='/api/admin'||uri.startsWith('/api/admin/'));
 }
 r=await call('/api/admin/fixture/login',{},csrf);check('fixture login transport',r.status,200);csrf=r.data.csrf;
 check('session rotates after fixture login',(await adminCookie()).value!==before.value,true);
 r=await call('/api/admin/mfa',{},csrf);check('future MFA path remains blocked',r.status,403);
 check('MFA endpoint receives scoped cookie',traffic.at(-1).cookieNames.includes('fluxmoneva_admin_session'),true);
 // Establish the real client session alongside the fixture admin session.
 check('client login coexistence',await evaluate(p.sessionId,`(async()=>{await fetch('/sanctum/csrf-cookie');const xsrf=decodeURIComponent(document.cookie.split('; ').find(v=>v.startsWith('XSRF-TOKEN=')).slice(11));return (await fetch('/api/login',{method:'POST',headers:{'Content-Type':'application/json','X-XSRF-TOKEN':xsrf},body:JSON.stringify({email:'browser@example.test',password:'fixture-password'})})).status})()`),200);
 r=await call('/api/admin/fixture/who');check('both cookies select admin',r.data.type,'App\\Models\\AdminIdentity');
 r=await call('/api/admin/fixture/logout',{},csrf);check('fixture logout',r.status,200);
 r=await call('/api/admin/fixture/who');check('admin invalidated',r.data.id,null);
 r=await call('/api/user');check('client survives admin logout',r.status,200);
 await writeFile('storage/app/admin-harden-09/evidence/browser-path.json',JSON.stringify({version,transport:'CDP interception, PHP stdin only; no network to fixture domains',results,traffic},null,2));
 if(results.some(r=>r.status==='FAIL'))process.exitCode=1;
}finally{await send('Browser.close').catch(()=>{});chrome.kill();}