import { spawn } from 'node:child_process';
import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
const profile = await mkdtemp(join(tmpdir(), 'lfamilia-responsive-'));
const chrome = spawn(process.argv[2], ['--headless=new', '--no-sandbox', '--disable-dev-shm-usage', '--remote-debugging-port=9227', '--user-data-dir='+profile], {stdio:'ignore'});
const pause = ms => new Promise(resolve => setTimeout(resolve, ms));
let ws;
try {
    let target;
    for (let i=0;i<40;i++) {
        try { target=await (await fetch('http://127.0.0.1:9227/json/new?about:blank',{method:'PUT'})).json(); break; } catch { await pause(250); }
    }
    if (!target) throw new Error('Chrome CDP unavailable');
    ws = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise((resolve,reject)=>{ws.onopen=resolve;ws.onerror=reject;});
    let id=0;
    const waiting=new Map();
    ws.onmessage=event=>{const message=JSON.parse(event.data);if(message.id){const p=waiting.get(message.id);waiting.delete(message.id);message.error?p.reject(Error(message.error.message)):p.resolve(message.result);}};
    const send=(method,params={})=>new Promise((resolve,reject)=>{const next=++id;waiting.set(next,{resolve,reject});ws.send(JSON.stringify({id:next,method,params}));});
    await send('Page.enable');
    const evaluate=async expression=>{
        const response=await send('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});
        if(response.exceptionDetails)throw Error(response.exceptionDetails.text);
        return response.result.value;
    };
    const navigate=async path=>{
        await send('Page.navigate',{url:'http://127.0.0.1:8000'+path});
        for(let i=0;i<50;i++){await pause(100);if(await evaluate("document.readyState === 'complete' && Boolean(document.querySelector('main'))"))break;}
        await pause(250);
    };
    const publicPages=['/','/catalog/browser-checkout-game','/login','/register','/forgot-password','/reset-password/browser-invalid-token?email=browser%40example.test','/orders/check','/faq','/contact','/privacy','/terms','/refund','/news','/promo','/leaderboard','/tools/win-rate','/tools/zodiac','/tools/magic-wheel'];
    const inspect=async(path,width)=>{
        await navigate(path);
        const info=await evaluate("({width:innerWidth,scroll:document.documentElement.scrollWidth,main:!!document.querySelector('main'),text:document.body.innerText.slice(0,120),broken:[...document.images].filter(i=>i.getBoundingClientRect().width>0&&i.complete&&!i.naturalWidth).map(i=>i.getAttribute('src'))})");
        if(!info.main||info.scroll>info.width+2||info.broken.length)throw Error(JSON.stringify({path,width,...info}));
        console.log('PASS responsive '+width+' '+path);
    };
    for(const width of [390,1440]){
        await send('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width<640});
        for(const path of publicPages)await inspect(path,width);
    }
    await navigate('/login');
    const login=await evaluate(`(async()=>{const token=document.querySelector('meta[name="csrf-token"]').content;const response=await fetch('/login',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':token},body:JSON.stringify({email:'browser-account@example.test',password:'Browser-test-password-123'})});return response.status;})()`);
    if(login!==200&&login!==204)throw Error('Fixture login failed: '+login);
    const accountPages=['/account','/account/wallet','/account/orders','/account/tickets','/account/profile','/account/membership','/account/game-accounts','/account/codes','/account/notifications'];
    for(const width of [390,1440]){
        await send('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width<640});
        for(const path of accountPages)await inspect(path,width);
    }
    await navigate('/admin/login');
    const adminLogin=await evaluate(`(async()=>{const token=document.querySelector('meta[name="csrf-token"]').content;const response=await fetch('/admin/login',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':token},body:JSON.stringify({email:'browser-admin@example.test',password:'Browser-admin-test-password-123'})});return response.status;})()`);
    if(adminLogin!==200&&adminLogin!==204)throw Error('Admin fixture login failed: '+adminLogin);
    const adminPages=['/admin/panel','/admin/orders','/admin/catalog','/admin/content','/admin/digiflazz','/admin/providers','/admin/payments','/admin/customers','/admin/vouchers','/admin/support','/admin/reports','/admin/settings','/admin/integrations'];
    for(const width of [390,1440]){
        await send('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width<640});
        for(const path of adminPages)await inspect(path,width);
    }
} finally {
    ws?.close(); chrome.kill(); await pause(300); await rm(profile,{recursive:true,force:true});
}
