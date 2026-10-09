import { spawn } from 'node:child_process';
import { mkdir, mkdtemp, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
const group = process.argv[3] || 'all';
const groups = {
    shell: ['/admin/panel'], product: ['/admin/catalog'], digiflazz: ['/admin/digiflazz'],
    orders: ['/admin/orders','/admin/fulfillment'], payments: ['/admin/payments'], customers: ['/admin/customers'],
    content: ['/admin/content','/admin/content/presentation'],
    operations: ['/admin/providers','/admin/vouchers','/admin/support','/admin/reports'],
    settings: ['/admin/access','/admin/settings','/admin/integrations','/admin/health','/admin/audit','/admin/nickname-tools','/admin/notifications'],
};
if (group !== 'all' && !groups[group]) throw Error('Unknown group: '+group);
const pages = group === 'all' ? Object.values(groups).flat() : groups[group];
// This suite uses isolated local/testing fixtures. Catalog imports stay local; provider and payment actions are never submitted.
const profile = await mkdtemp(join(tmpdir(),'lfamilia-admin-ui-'));
const chrome = spawn(process.argv[2],['--headless=new','--no-sandbox','--disable-dev-shm-usage','--remote-debugging-port=9228','--user-data-dir='+profile],{stdio:'ignore'});
const pause = ms => new Promise(resolve=>setTimeout(resolve,ms));
const errors=[], dialogs=[], mutations=[];
let ws;
try {
    let target;
    for(let i=0;i<40;i++){try{target=await(await fetch('http://127.0.0.1:9228/json/new?about:blank',{method:'PUT'})).json();break;}catch{await pause(250);}}
    if(!target)throw Error('Chrome CDP unavailable');
    ws=new WebSocket(target.webSocketDebuggerUrl);
    await new Promise((resolve,reject)=>{ws.onopen=resolve;ws.onerror=reject;});
    let id=0;const waiting=new Map();
    ws.onmessage=event=>{const m=JSON.parse(event.data);if(m.method==='Page.javascriptDialogOpening'){dialogs.push(m.params.message);void send('Page.handleJavaScriptDialog',{accept:false});}if(m.method==='Network.requestWillBeSent'&&m.params.request.method==='POST')mutations.push(m.params.request.url);if(m.method==='Runtime.exceptionThrown')errors.push(m.params.exceptionDetails.exception?.description||m.params.exceptionDetails.text);if(m.id){const p=waiting.get(m.id);waiting.delete(m.id);m.error?p.reject(Error(m.error.message)):p.resolve(m.result);}};
    const send=(method,params={})=>new Promise((resolve,reject)=>{const next=++id;waiting.set(next,{resolve,reject});ws.send(JSON.stringify({id:next,method,params}));});
    await send('Page.enable');await send('Runtime.enable');await send('Network.enable');
    const evaluate=async expression=>{const r=await send('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});if(r.exceptionDetails)throw Error(r.exceptionDetails.exception?.description||r.exceptionDetails.text);return r.result.value;};
    const navigate=async path=>{await send('Page.navigate',{url:'http://127.0.0.1:8000'+path});for(let i=0;i<60;i++){await pause(100);if(await evaluate(`location.pathname===${JSON.stringify(path.split('?')[0])}&&document.readyState==='complete'&&Boolean(document.querySelector('main'))`))break;}await pause(300);};
    const click=async label=>{const found=await evaluate(`(()=>{const b=[...document.querySelectorAll('.lf-admin-tabs button'),...document.querySelectorAll('button')].find(b=>b.getBoundingClientRect().height>0&&b.textContent.trim()===${JSON.stringify(label)});if(!b)return false;b.click();return true;})()`);if(!found)throw Error('Missing button: '+label);await pause(350);};
    const pointerClick = async expression => {
        await evaluate(`(()=>{const e=(${expression});if(!e)return;window.__pointerEvents=[];for(const t of ['pointerdown','pointerup','click','change'])document.addEventListener(t,event=>{window.__pointerEvents.push({type:t,tag:event.target.tagName,sku:event.target.closest('[data-sku]')?.dataset.sku,checked:event.target.checked,x:event.clientX,y:event.clientY});},{once:true,capture:true});e.scrollIntoView({block:'center',inline:'nearest',behavior:'instant'});})()`);
        await pause(200);
        const point = await evaluate(`(()=>{const e=(${expression});if(!e||e.disabled)return null;const r=e.getBoundingClientRect();const x=r.left+r.width/2,y=r.top+r.height/2;return e.contains(document.elementFromPoint(x,y))?{x,y}:null;})()`);
        if(!point)throw Error('Control disabled or covered: '+expression);
        await send('Input.dispatchMouseEvent',{type:'mouseMoved',...point});
        await send('Input.dispatchMouseEvent',{type:'mousePressed',...point,button:'left',buttons:1,clickCount:1});
        await send('Input.dispatchMouseEvent',{type:'mouseReleased',...point,button:'left',clickCount:1});
        await pause(350);
    };
    const inspect=async label=>{
        const info=await evaluate(`(()=>{const visible=e=>e.getBoundingClientRect().width>0&&e.getBoundingClientRect().height>0;return {width:innerWidth,scroll:document.documentElement.scrollWidth,admin:!!document.querySelector('.lf-admin'),heading:document.querySelector('h1')?.innerText,mobileTables:innerWidth<768?[...document.querySelectorAll('main table')].filter(visible).length:0,broken:[...document.images].filter(i=>visible(i)&&i.complete&&!i.naturalWidth).map(i=>i.getAttribute('src')),outside:[...document.querySelectorAll('main input,main select,main textarea,main button')].filter(e=>visible(e)&&!e.closest('table,.lf-admin-tabs')).filter(e=>{const r=e.getBoundingClientRect();return r.left< -2||r.right>innerWidth+2;}).map(e=>e.textContent||e.tagName)};})()`);
        if(!info.admin||!info.heading||info.scroll>info.width+2||info.mobileTables||info.broken.length||info.outside.length||errors.length)throw Error(JSON.stringify({label,...info,errors}));
        if(process.env.ADMIN_UI_SCREENSHOTS){await mkdir(process.env.ADMIN_UI_SCREENSHOTS,{recursive:true});const shot=await send('Page.captureScreenshot',{format:'png'});await writeFile(join(process.env.ADMIN_UI_SCREENSHOTS,label.replaceAll(/[^a-z0-9-]/gi,'_')+'.png'),Buffer.from(shot.data,'base64'));}
        console.log('PASS admin UI '+label);
    };
    const escape = async () => { await send('Input.dispatchKeyEvent',{type:'keyDown',key:'Escape',code:'Escape',windowsVirtualKeyCode:27}); await send('Input.dispatchKeyEvent',{type:'keyUp',key:'Escape',code:'Escape',windowsVirtualKeyCode:27}); await pause(400); };
    const inspectDialog = async label => {
        await pause(600);
        const dialog = await evaluate(`(()=>{const d=document.querySelector('[role=dialog]');if(!d)return null;const r=d.getBoundingClientRect();return {left:r.left,right:r.right,height:r.height,width:r.width,viewport:innerHeight,screen:innerWidth,locked:getComputedStyle(document.body).overflow,focus:d.contains(document.activeElement),scroll:d.scrollHeight>d.clientHeight};})()`);
        if(!dialog||dialog.height>dialog.viewport+2||dialog.width>dialog.screen+2||dialog.left< -2||dialog.right>dialog.screen+2||dialog.locked!=='hidden'||!dialog.focus)throw Error('Dialog regression '+label+': '+JSON.stringify(dialog));
        if(label.endsWith('-fulfillment-detail')){
            const before=mutations.length, confirmations=dialogs.length;
            const completed=await evaluate(`(()=>{const b=[...document.querySelectorAll('[role=dialog] button')].find(b=>b.textContent.trim()==='Tandai berhasil');if(!b)return false;b.click();return true;})()`);
            if(completed){await pause(100);if(dialogs.length!==confirmations+1||mutations.length!==before)throw Error('Cancelled fulfillment confirmation sent a mutation');console.log('PASS cancelled fulfillment action '+label);}
        }
        console.log('PASS dialog '+label); await escape();
    };
    await navigate('/admin/login');
    const login=await evaluate(`(async()=>{const token=document.querySelector('meta[name="csrf-token"]').content;const r=await fetch('/admin/login',{method:'POST',headers:{'Content-Type':'application/json',Accept:'application/json','X-CSRF-TOKEN':token},body:JSON.stringify({email:'browser-admin@example.test',password:'Browser-admin-test-password-123'})});return r.status;})()`);
    if(![200,204].includes(login))throw Error('Admin fixture login failed: '+login);
    const sizes=group==='all'?[[360,800],[390,844],[412,915],[430,932],[1024,768],[1280,800],[1440,900],[1920,1080]]:[[390,844],[430,932],[1024,768],[1440,900]];
    let importSubmitted = false;
    for(const [width,height] of sizes){
        await send('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:width<768});
        for(const path of pages){
            await navigate(path);await inspect(width+'-'+path);
            if(path==='/admin/catalog'){
                const editor=await evaluate(`JSON.parse(document.getElementById('app').dataset.page).props.products.find(p=>p.slug==='browser-checkout-game').id`);
                await pointerClick(`[...document.querySelectorAll('button')].find(b=>b.getBoundingClientRect().height>0&&b.textContent.trim()==='Impor Digiflazz')`);
                await evaluate(`(()=>{const s=document.querySelector('[data-testid="catalog-import-target"] select');s.value=${editor};s.dispatchEvent(new Event('change',{bubbles:true}));})()`);
                await pointerClick(`[...document.querySelectorAll('button')].find(b=>b.getBoundingClientRect().height>0&&b.textContent.trim()==='Lanjut pilih SKU')`);
                if(!await evaluate(`Boolean(document.querySelector('[data-testid="digiflazz-import"]'))`))throw Error('Catalog import action did not open SKU picker');
                await navigate('/admin/catalog?edit='+editor);
                if(!await evaluate(`!document.querySelector('input[placeholder="Nama, alamat produk, merek, atau nominal"]')&&!document.querySelector('[role=dialog]')`))throw Error('Product list visible behind editor');
                for(const tab of ['Informasi','Nominal & Harga','Tampilan Produk','Data Pelanggan','Penanganan']){
                    await click(tab);await inspect(width+'-product-'+tab);
                    if(tab==='Nominal & Harga'){
                        await pointerClick(`[...document.querySelectorAll('button')].find(b=>b.getBoundingClientRect().height>0&&b.textContent.trim()==='Impor nominal Digiflazz')`);
                        if(!await evaluate(`document.querySelector('[data-testid="digiflazz-import"]')&&[...document.querySelectorAll('[data-testid="digiflazz-import"] button')].find(b=>/^Impor \\d+ nominal$/.test(b.textContent.trim()))?.disabled`))throw Error('Import picker missing or submit enabled without selection');
                        await pointerClick(`document.querySelector('[data-sku="${importSubmitted?'BROWSER-IMPORT-300':'BROWSER-IMPORT-200'}"] span')`);
                        if(!await evaluate(`Boolean([...document.querySelectorAll('[data-testid="digiflazz-import"] button')].find(b=>b.textContent.trim()==='Impor 1 nominal'&&!b.disabled))`))throw Error('Selecting supplier SKU did not enable import: '+JSON.stringify(await evaluate(`({events:window.__pointerEvents,ancestors:[...function*(e){while(e){yield e.tagName;e=e.parentElement;}}(document.querySelector('[data-testid="digiflazz-import"]'))],checked:document.querySelector('[data-sku="${importSubmitted?'BROWSER-IMPORT-300':'BROWSER-IMPORT-200'}"] input')?.checked,buttons:[...document.querySelectorAll('[data-testid="digiflazz-import"] button')].map(b=>({text:b.textContent.trim(),disabled:b.disabled}))})`)));
                        await evaluate(`(()=>{const input=document.querySelector('[data-testid="import-customer-template"]');input.value='{{user_id}}';input.dispatchEvent(new Event('input',{bubbles:true}));})()`);
                        await inspect(width+'-import-picker');
                        if(!importSubmitted){
                            await pointerClick(`[...document.querySelectorAll('[data-testid="digiflazz-import"] button')].find(b=>b.textContent.trim()==='Impor 1 nominal')`);
                            let done=false;for(let i=0;i<60;i++){await pause(100);done=await evaluate(`!document.querySelector('[data-testid="digiflazz-import"]')`);if(done)break;}
                            if(!done)throw Error('Local SKU import did not finish');
                            await navigate('/admin/catalog?edit='+editor);
                            const safe=await evaluate(`(()=>{const p=JSON.parse(document.getElementById('app').dataset.page).props.products.find(p=>p.slug==='browser-checkout-game');const pack=p.packages.find(p=>p.name==='Browser Import 200 Diamonds');return pack&&pack.is_active&&pack.nominal_value===200&&['BROWSER-IMPORT-200','BROWSER-IMPORT-200-B'].every(sku=>pack.mappings.some(m=>m.external_sku===sku&&m.is_active&&m.customer_no_template==='{{user_id}}'));})()`);
                            if(!safe)throw Error('Import did not publish package and source with destination');
                            const storefront=await evaluate(`(async()=>{const r=await fetch('/catalog/browser-checkout-game');if(!r.ok)return {status:r.status};const doc=new DOMParser().parseFromString(await r.text(),'text/html');const page=JSON.parse(doc.getElementById('app').dataset.page);const pack=page.props.packages.find(p=>p.name==='Browser Import 200 Diamonds');return {status:r.status,available:pack?.is_available,price:pack?.price_idr};})()`);
                            if(storefront.status!==200||storefront.available!==true||storefront.price!==22000)throw Error('Imported package is not available at the expected storefront price: '+JSON.stringify(storefront));
                            importSubmitted=true;await click('Nominal & Harga');
                            console.log('PASS real click, SKU selection, and isolated catalog import');
                        }else{await click('Impor nominal Digiflazz');}
                        await click('Edit nominal');await inspect(width+'-nominal-editor');await click('Tutup editor nominal');
                    }
                    if(tab==='Data Pelanggan'&&!await evaluate(`Boolean(document.querySelector('input[placeholder="Contoh: User ID"]'))`))throw Error('Customer fields missing from editor');
                }
                await click('Kembali ke produk');await inspect(width+'-product-return');
            }
            if(path==='/admin/digiflazz'){
                for(const health of ['healthy','warning','critical']){
                    await evaluate(`(()=>{const s=[...document.querySelectorAll('main select')].find(s=>[...s.options].some(o=>o.value==='warning'));s.value=${JSON.stringify(health)};s.dispatchEvent(new Event('change',{bubbles:true}));})()`);
                    await pointerClick(`[...document.querySelectorAll('main button')].find(b=>b.textContent.trim()==='Terapkan filter')`);
                    let filtered=false;
                    for(let i=0;i<50;i++){
                        await pause(100);
                        filtered=await evaluate(`(()=>{const rows=[...document.querySelectorAll('[data-digiflazz-health]')].filter(e=>e.getBoundingClientRect().height>0);return new URLSearchParams(location.search).get('health')===${JSON.stringify(health)}&&rows.length>0&&rows.every(row=>row.dataset.digiflazzHealth===${JSON.stringify(health)});})()`);
                        if(filtered)break;
                    }
                    if(!filtered)throw Error('Digiflazz health filter failed: '+health);
                    await inspect(width+'-digiflazz-filter-'+health);
                }
                await click('Hapus filter');
            }
            if(path==='/admin/orders'){
                await click('Catat pesanan manual'); await inspectDialog(width+'-manual-order');
                const order=await evaluate(`JSON.parse(document.getElementById('app').dataset.page).props.orders.data[0]?.id`);
                if(!order)throw Error('Order fixture missing'); await navigate('/admin/orders/'+order); await inspect(width+'-order-detail');
                const complete=await evaluate(`(()=>{const b=[...document.querySelectorAll('main button')].find(b=>b.textContent.trim()==='Selesaikan pesanan');if(!b)return false;b.click();return true;})()`);
                if(complete){await pause(350);if(!await evaluate(`document.querySelector('[role=dialog] button[type=submit]')?.disabled`))throw Error('Risk action must require confirmation');await inspectDialog(width+'-order-confirmation');}
            }
            if(path==='/admin/fulfillment'){
                const opened=await evaluate(`(()=>{const b=[...document.querySelectorAll('main button')].find(b=>b.getBoundingClientRect().height>0&&['Tangani','Lihat'].includes(b.textContent.trim()));if(!b)return false;b.click();return true;})()`);
                if(!opened)throw Error('Manual fulfillment fixture missing');
                await inspectDialog(width+'-fulfillment-detail');
            }
            if(path==='/admin/access'||path==='/admin/vouchers'){
                await click(path==='/admin/access'?'Tambah Admin':'Tambah voucher');await inspect(width+'-'+path+'-create-editor');await click('Tutup');
            }
            if(path==='/admin/support'){
                const ticket=await evaluate(`JSON.parse(document.getElementById('app').dataset.page).props.tickets.data[0]?.id`);
                if(!ticket)throw Error('Support fixture missing'); await navigate('/admin/support?ticket='+ticket);await inspect(width+'-support-conversation');
            }
            if(path==='/admin/customers'){
                const customer=await evaluate(`JSON.parse(document.getElementById('app').dataset.page).props.customers.data[0]?.id`);
                if(!customer)throw Error('Customer fixture missing');
                await navigate('/admin/customers/'+customer);await inspect(width+'-customer-profile');
            }
            if(path!=='/admin/catalog'){
                const tabs=await evaluate(`[...document.querySelectorAll('main .lf-admin-tabs button')].filter(b=>b.getBoundingClientRect().height>0).map(b=>b.textContent.trim())`);
                for(const tab of tabs){await click(tab);await inspect(width+'-'+path+'-'+tab);}
                if(path==='/admin/integrations'){
                    await click('Midtrans Snap');
                    const production=await evaluate(`(()=>{const select=[...document.querySelectorAll('main select')].find(s=>s.getBoundingClientRect().height>0&&[...s.options].some(o=>o.value==='production'));if(!select)return false;select.value=[...select.options].find(o=>o.value==='production').value;select.dispatchEvent(new Event('change',{bubbles:true}));return true;})()`);
                    if(!production)throw Error('Production environment selector missing');await pause(100);
                    if(!await evaluate(`document.body.textContent.includes('Mode Production aktif.')&&[...document.querySelectorAll('input[type=password]')].every(i=>i.value==='')`))throw Error('Production warning/blank-secret regression');
                    await inspect(width+'-integration-production-warning');
                }
                if(path==='/admin/nickname-tools'){
                    await click('Kode Game');await click('Tambah Kode Game');await inspect(width+'-game-code-editor');
                    const switchState=await evaluate(`(()=>{const s=[...document.querySelectorAll('main [role=switch]')].find(s=>s.textContent===''&&!s.disabled);if(!s)return null;s.click();return true;})()`);
                    if(!switchState)throw Error('Boolean switch editor missing');await pause(100);await inspect(width+'-game-code-switch');
                }
            }
            const record=await evaluate(`(()=>{const r=[...document.querySelectorAll('main .lf-admin-record')].find(r=>r.getBoundingClientRect().height>0);if(!r)return false;r.scrollIntoView({block:'center'});const d=r.querySelector('details');if(d)d.open=true;return true;})()`);
            if(record){await pause(100);await inspect(width+'-'+path+'-record-detail');}
            if(path==='/admin/content'&&width===390){
                await click('Logo & Gambar');
                const previousIcon=await evaluate(`document.querySelector('link[rel=icon]')?.getAttribute('href')`);
                const file=join(profile,'browser-favicon.png');await writeFile(file,Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAEAAAABACAIAAAAlC+aJAAAAY0lEQVR4nO3PQQ3AIADAQMAKwhCNoYngcVnSU9DOfe74s6UDXjWgNaA1oDWgNaA1oDWgNaA1oDWgNaA1oDWgNaA1oDWgNaA1oDWgNaA1oDWgNaA1oDWgNaA1oDWgNaA1oDWgNaA1oDWgNaA1oDWgNaA1oDWgfYaaAdUdZaagAAAAAElFTkSuQmCC','base64'));
                const input=await send('Runtime.evaluate',{expression:`[...document.querySelectorAll('article')].find(a=>a.textContent.includes('Favicon')).querySelector('input[type=file]')`});
                await send('DOM.setFileInputFiles',{objectId:input.result.objectId,files:[file]});await pause(100);
                await evaluate(`[...document.querySelectorAll('article')].find(a=>a.textContent.includes('Favicon')).querySelector('form').requestSubmit()`);
                let icon;
                for(let i=0;i<60;i++){await pause(100);icon=await evaluate(`document.querySelector('link[rel=icon]')?.getAttribute('href')`);if(icon!==previousIcon&&icon?.includes('browser-favicon'))break;}
                if(icon===previousIcon||!icon?.includes('browser-favicon'))throw Error('Favicon upload did not update Admin');
                await navigate('/');if(await evaluate(`document.querySelector('link[rel=icon]')?.getAttribute('href')`)!==icon)throw Error('Favicon differs between Admin and storefront');
                await navigate(path);console.log('PASS favicon upload/Admin/storefront');
            }
            if(path==='/admin/panel'){
                await send('Input.dispatchKeyEvent',{type:'keyDown',key:'k',code:'KeyK',modifiers:2,windowsVirtualKeyCode:75});
                if(!await evaluate(`document.activeElement===document.querySelector('[aria-label="Pencarian Admin"]')`))throw Error('Global search keyboard shortcut failed');
                await evaluate(`(()=>{const i=document.querySelector('[aria-label="Pencarian Admin"]');i.value='Browser';i.dispatchEvent(new Event('input',{bubbles:true}));i.closest('form').requestSubmit();})()`);
                let found=false;for(let i=0;i<40;i++){await pause(100);found=await evaluate(`Boolean(document.querySelector('.lf-admin-search-results a'))`);if(found)break;}
                if(!found)throw Error('Global search fixture result missing');
                await evaluate(`document.querySelector('[aria-label="Menu akun Admin"]').click()`);await pause(350);
                if(!await evaluate(`!document.querySelector('.lf-admin-search-results')&&document.querySelector('.lf-admin-popover')?.contains(document.activeElement)`))throw Error('Search/account menu overlap or focus regression');
                await escape();console.log('PASS search/keyboard/account menu '+width);
            }
            if(path==='/admin/panel'&&width<768){
                await evaluate(`document.querySelector('[aria-label="Buka menu Admin"]').click()`);await pause(600);
                const d=await evaluate(`(()=>{const d=document.querySelector('.lf-admin-mobile-navigation');return {height:d?.getBoundingClientRect().height,locked:getComputedStyle(document.body).overflow,focus:!!d?.contains(document.activeElement)};})()`);
                if(Math.abs(d.height-height)>2||d.locked!=='hidden'||!d.focus)throw Error('Drawer regression: '+JSON.stringify(d));
                await send('Input.dispatchKeyEvent',{type:'keyDown',key:'Escape',code:'Escape',windowsVirtualKeyCode:27});await send('Input.dispatchKeyEvent',{type:'keyUp',key:'Escape',code:'Escape',windowsVirtualKeyCode:27});await pause(400);
                if(await evaluate(`Boolean(document.querySelector('.lf-admin-mobile-navigation'))`))throw Error('Drawer did not close');
            }
        }
    }
}finally{ws?.close();chrome.kill();await pause(300);await rm(profile,{recursive:true,force:true});}
