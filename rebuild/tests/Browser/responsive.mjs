import { spawn } from 'node:child_process';
import { mkdtemp, mkdir, rm, writeFile } from 'node:fs/promises';
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

    // PR 8: footer artwork must keep a 3:1 element box at every breakpoint.
    // Insert a test asset only when the fixture has no configured footer image.
    // Pixel assertions use actual Chromium screenshots, not just CSS declarations.
    const pr8Svg = (kind) => {
        const square = kind === 'crop';
        const artwork = square
            ? '<rect width="1200" height="400" fill="#e02020"/><rect y="400" width="1200" height="400" fill="#2255d8"/><rect y="800" width="1200" height="400" fill="#20df20"/>'
            : '<rect width="1200" height="400" fill="#e02020"/><rect x="32" y="32" width="1136" height="336" fill="#2255d8"/>';
        return 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(
            '<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="' + (square ? 1200 : 400) + '">' + artwork + '</svg>'
        );
    };
    const pr8SameColor = (sample, expected) => sample.every((component, i) => Math.abs(component - expected[i]) < 18);
    for (const width of [390, 768, 1440]) {
        await send('Emulation.setDeviceMetricsOverride', {width, height:900, deviceScaleFactor:1, mobile:width<640});
        for (const kind of ['edge', 'crop']) {
            await navigate('/');
            const geometry = await evaluate('(async()=>{' +
                'const footer=document.querySelector(".lf-footer");if(!footer)throw Error("Missing customer footer");' +
                'let slot=footer.querySelector(".lf-footer-banner");' +
                'if(!slot){slot=document.createElement("div");slot.className="lf-footer-banner";' +
                'const picture=document.createElement("picture");picture.appendChild(document.createElement("img"));slot.appendChild(picture);footer.prepend(slot);}' +
                'slot.querySelectorAll("source").forEach(source=>source.remove());' +
                'const img=slot.querySelector("img");img.setAttribute("width","1200");img.setAttribute("height","400");' +
                'img.src=' + JSON.stringify(pr8Svg(kind)) + ';await img.decode();' +
                'document.querySelector(".lf-home-popup-close")?.click();' +
                'img.scrollIntoView({block:"center"});await new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)));' +
                'const r=img.getBoundingClientRect(), p=slot.getBoundingClientRect(), style=getComputedStyle(img);' +
                'return {rect:{x:r.x,y:r.y,width:r.width,height:r.height},containerWidth:document.documentElement.clientWidth,parentHeight:p.height,' +
                'maxHeight:style.maxHeight,minHeight:style.minHeight,fit:style.objectFit,position:style.objectPosition,' +
                'layoutPosition:style.position,transform:style.transform,inlineHeight:img.style.height};' +
                '})()');
            if (Math.abs(geometry.rect.width - geometry.containerWidth) > 1 ||
                Math.abs(geometry.rect.height - geometry.rect.width / 3) > 1 ||
                Math.abs(geometry.parentHeight - geometry.rect.width / 3) > 2 ||
                geometry.maxHeight !== 'none' || geometry.minHeight !== '0px' ||
                geometry.fit !== 'cover' || !['50% 50%', 'center'].includes(geometry.position) ||
                geometry.layoutPosition !== 'static' || geometry.transform !== 'none' ||
                geometry.inlineHeight !== '') {
                throw Error('PR8 footer ratio/style failed ' + JSON.stringify({width, kind, geometry}));
            }
            const shot = (await send('Page.captureScreenshot', {format:'png', captureBeyondViewport:false})).data;
            const pixels = await evaluate('(async()=>{' +
                'const image=new Image();image.src="data:image/png;base64,' + shot + '";await image.decode();' +
                'const canvas=document.createElement("canvas");canvas.width=image.naturalWidth;canvas.height=image.naturalHeight;' +
                'const ctx=canvas.getContext("2d");ctx.drawImage(image,0,0);' +
                'const r=' + JSON.stringify(geometry.rect) + ';' +
                'const pixel=(x,y)=>Array.from(ctx.getImageData(Math.round(x),Math.round(y),1,1).data).slice(0,3);' +
                'return {left:pixel(r.x+3,r.y+r.height/2),right:pixel(r.x+r.width-4,r.y+r.height/2),' +
                'top:pixel(r.x+r.width/2,r.y+3),bottom:pixel(r.x+r.width/2,r.y+r.height-4),' +
                'center:pixel(r.x+r.width/2,r.y+r.height/2)};' +
                '})()');
            const red = [224,32,32], blue = [34,85,216];
            const expected = kind === 'edge'
                ? {left:red,right:red,top:red,bottom:red,center:blue}
                : {left:blue,right:blue,top:blue,bottom:blue,center:blue};
            for (const [place, rgb] of Object.entries(expected)) {
                if (!pr8SameColor(pixels[place], rgb)) {
                    throw Error('PR8 cropped/misaligned footer artwork ' + JSON.stringify({width, kind, place, pixels, geometry}));
                }
            }
            console.log('PASS PR8 footer 3:1 no-crop and centered fallback ' + width + ' ' + kind);
        }
    }
    // PR 3 F7/F8: actual DOM hit targets, header interactions, and popular title.
    // "before" PNGs reconstruct the pre-PR CSS measurements; "after" is actual CSS.
    const pr3Folder = 'ergonomics-visual-results';
    await mkdir(pr3Folder,{recursive:true});
    const shotPr3=async label=>{
        const screenshot=await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});
        await writeFile(pr3Folder+'/'+label+'.png',Buffer.from(screenshot.data,'base64'));
    };
    const prePr3Css=[
        '.lf-customer .lf-header .lf-nav a{min-height:0!important;display:inline!important;padding-block:8px!important}',
        '@media(max-width:900px){',
        '.lf-customer .lf-header .lf-header-actions .lf-search-mobile,.lf-customer .lf-header .lf-header-actions .lf-menu{width:31px!important;height:31px!important;min-width:0!important;min-height:0!important;flex:0 0 31px!important;border:1px solid rgba(255,255,255,.09)!important;background:var(--lf-color-background)!important}',
        '.lf-customer .lf-header .lf-header-actions .lf-search-mobile::before,.lf-customer .lf-header .lf-header-actions .lf-menu::before{display:none!important}',
        '}',
        '@media(max-width:640px){',
        '.lf-customer .lf-home-popup-close{width:38px!important;height:38px!important;min-height:0!important;min-width:0!important;border:2px solid rgba(220,183,131,.72)!important}',
        '.lf-customer .lf-home-popup-close::before{display:none!important}',
        '.lf-customer .lf-popular-card strong{display:-webkit-box!important;font-size:13px!important}',
        '}'
    ].join('\n');
    const showBeforePr3=()=>evaluate('(()=>{const node=document.createElement("style");node.id="pr3-reconstructed-before";node.textContent='+JSON.stringify(prePr3Css)+';document.head.append(node)})()');
    const showAfterPr3=()=>evaluate('document.querySelector("#pr3-reconstructed-before")?.remove()');
    for(const width of [360,390,1024,1440]){
        await send('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width<640});
        await navigate('/');
        const metrics=await evaluate('(()=>{const box=s=>{const el=document.querySelector(s);if(!el||getComputedStyle(el).display==="none")return null;const r=el.getBoundingClientRect();return {width:r.width,height:r.height}};const links=[...document.querySelectorAll(".lf-nav a")].filter(el=>getComputedStyle(el).display!=="none").map(el=>({height:el.getBoundingClientRect().height,href:el.getAttribute("href")}));const title=[...document.querySelectorAll(".lf-popular-card strong")].find(el=>el.textContent.trim()==="Mobile Legends");const style=title&&getComputedStyle(title);const card=title?.closest(".lf-popular-card");const grid=document.querySelector(".lf-popular");const emptyPublisher=[...document.querySelectorAll(".lf-popular-card")].find(el=>el.getAttribute("href")?.includes("browser-popular-no-publisher"));return {popup:box(".lf-home-popup-close"),search:box(".lf-search-mobile"),menu:box(".lf-menu"),links,hasPopular:!!title,clipped:!title||title.scrollHeight>title.clientHeight+1,titleFont:style?.fontSize,titleWhiteSpace:style?.whiteSpace,titleOverflow:style?.textOverflow,titleClamp:style?.webkitLineClamp,titleLines:title&&style?Math.round(title.getBoundingClientRect().height/parseFloat(style.lineHeight)):0,publisher:card?.querySelector(".lf-popular-publisher")?.textContent.trim(),emptyPublisherExists:!!emptyPublisher,emptyPublisherSubline:!!emptyPublisher?.querySelector(".lf-popular-publisher"),emptyPublisherText:emptyPublisher?.innerText.trim(),categoryDisplayed:card?.innerText.includes("Game")||false,gridColumns:grid?getComputedStyle(grid).gridTemplateColumns.split(" ").length:0,overflow:document.documentElement.scrollWidth-innerWidth}})()');
        if(width<640){
            for(const key of ['popup','search','menu']){
                const size=metrics[key];
                if(!size||size.width<44||size.height<44)throw Error('PR3 F7 undersized '+key+': '+JSON.stringify({width,metrics}));
            }
            if(!metrics.hasPopular||metrics.clipped||metrics.titleWhiteSpace!=='normal'||metrics.titleOverflow==='ellipsis'||metrics.titleClamp!=='none'||metrics.titleLines>2)throw Error('PR5 popular title must wrap within two unclipped lines: '+JSON.stringify({width,metrics}));
            if(width===390){
                await showBeforePr3(); await shotPr3('popup-before-reconstructed-390'); await showAfterPr3();
                await shotPr3('popup-after-390');
            }
            // Popup dismissal must retain its "do not show again" option.
            if(width===390){
                const dismissed=await evaluate('(()=>{const checkbox=document.querySelector(".lf-home-popup-dismiss input");const close=document.querySelector(".lf-home-popup-close");if(!checkbox||!close)return false;checkbox.click();close.click();return Object.keys(localStorage).some(key=>key.startsWith("lfamilia-home-popup:")&&Number(localStorage.getItem(key))>Date.now())})()');
                if(!dismissed)throw Error('PR3 popup persisted dismissal failed');
            }else{
                await evaluate('document.querySelector(".lf-home-popup-close")?.click()');
            }
            // Search, focus return, and mobile drawer use existing handlers.
            await evaluate('document.querySelector(".lf-search-mobile").click()');
            const searchOpened=await evaluate('Boolean(document.querySelector(".lf-search-dialog[role=dialog]"))');
            if(!searchOpened)throw Error('PR3 mobile search did not open '+width);
            await evaluate('document.querySelector(".lf-search-dialog-close").click()');
            await pause(60);
            const searchClosed=await evaluate('!document.querySelector(".lf-search-dialog")&&document.activeElement===document.querySelector(".lf-search-mobile")');
            if(!searchClosed)throw Error('PR3 mobile search failed to close/restore focus '+width);
            await evaluate('document.querySelector(".lf-menu").click()');
            if(!await evaluate('Boolean(document.querySelector(".lf-drawer.open"))'))throw Error('PR3 mobile drawer did not open '+width);
            await evaluate('document.querySelector(".lf-overlay").click()');
            await pause(60);
            if(await evaluate('Boolean(document.querySelector(".lf-drawer.open"))'))throw Error('PR3 mobile drawer did not close '+width);
        }else{
            if(metrics.links.length<3||metrics.links.some(link=>link.height<44||!link.href||link.href==="#")){
                throw Error('PR3 desktop navigation is inaccessible: '+JSON.stringify(metrics.links));
            }
            // Keep the backdrop from obscuring the desktop header/popular screenshots.
            await evaluate('document.querySelector(".lf-home-popup-close")?.click()');
        }
        if(metrics.gridColumns!==(width<901?2:4)||metrics.publisher!=='Moonton'||metrics.categoryDisplayed||!metrics.emptyPublisherExists||metrics.emptyPublisherSubline||!metrics.emptyPublisherText?.includes('Produk Tanpa Penerbit'))throw Error('PR5 popular grid/publisher mismatch: '+JSON.stringify({width,metrics}));
        // Capture header and visible popular pill together (sticky header).
        await evaluate('(()=>{const el=document.querySelector(".lf-popular");if(el)window.scrollTo(0,Math.max(0,window.scrollY+el.getBoundingClientRect().top-100))})()');
        await pause(120);
        await showBeforePr3(); await shotPr3('header-popular-before-reconstructed-'+width);
        await showAfterPr3(); await shotPr3('header-popular-after-'+width);
        const overflow=await evaluate('document.documentElement.scrollWidth-innerWidth');
        if(overflow>2||metrics.overflow>2)throw Error('PR3 horizontal overflow '+JSON.stringify({width,overflow,metrics}));
        if(width===390){
            // Keep the fixture available to the subsequent responsive smoke.
            await evaluate('Object.keys(localStorage).filter(key=>key.startsWith("lfamilia-home-popup:")).forEach(key=>localStorage.removeItem(key))');
        }
        console.log('PASS PR3 F7/F8 geometry interactions and screenshot '+width);
    }
    // Regression: customer homepage category switching must not remount or scroll to the top.
    for (const width of [390, 1440]) {
        await send('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width<640});
        await navigate('/');
        const before = await evaluate(`(()=>{
            document.querySelector('.lf-home-popup-close')?.click();
            const tab=[...document.querySelectorAll('#produk .lf-filters a')]
                .find(link=>new URL(link.href).searchParams.get('category')==='game');
            const section=document.querySelector('#produk');
            if(!tab||!section)return null;
            window.__lfCategoryNavigationMarker='kept-alive';
            window.scrollTo(0,section.getBoundingClientRect().top+window.scrollY-80);
            const scroll=window.scrollY;
            tab.click();
            return {scroll};
        })()`);
        if(!before)throw Error('Game category tab or homepage product section missing');
        let state;
        for(let attempt=0;attempt<100;attempt++){
            await pause(100);
            state=await evaluate(`({
                marker:window.__lfCategoryNavigationMarker,
                url:location.search,
                scroll:window.scrollY,
                active:!![...document.querySelectorAll('#produk .lf-filters a')]
                    .find(link=>new URL(link.href).searchParams.get('category')==='game' && link.classList.contains('active')),
                hasProduct:!!document.querySelector('a.lf-product[href="/catalog/browser-checkout-game"]')
            })`);
            if(state.url.includes('category=game')&&state.active)break;
        }
        if(!state?.active||state.marker!=='kept-alive'||Math.abs(state.scroll-before.scroll)>6||!state.hasProduct){
            throw Error('Homepage category switching caused reload, scroll jump, or wrong products: '+JSON.stringify({width,before,state}));
        }
        console.log('PASS homepage category SPA '+width);
    }
    await navigate('/login');
    const login=await evaluate(`(async()=>{const token=document.querySelector('meta[name="csrf-token"]').content;const response=await fetch('/login',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':token},body:JSON.stringify({email:'browser-account@example.test',password:'Browser-test-password-123'})});return response.status;})()`);
    if(login!==200&&login!==204)throw Error('Fixture login failed: '+login);
    const accountPages=['/account','/account/wallet','/account/orders','/account/tickets','/account/profile','/account/membership','/account/game-accounts','/account/codes','/account/notifications'];
    for(const width of [360,390,1440]){
        await send('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width<640});
        for(const path of accountPages)await inspect(path,width);
        await navigate('/account/profile');
        const phoneAlignment=await evaluate(`(()=>{const root=document.querySelector('.lf-country-picker');const button=root?.querySelector('button');const input=root?.querySelector('input[type="tel"]');if(!button||!input)return null;const a=button.getBoundingClientRect(),b=input.getBoundingClientRect();return {top:Math.abs(a.top-b.top),height:Math.abs(a.height-b.height)};})()`);
        if(!phoneAlignment||phoneAlignment.top>1||phoneAlignment.height>1)throw Error('F13 account phone alignment mismatch '+JSON.stringify({width,phoneAlignment}));
        await navigate('/account/game-accounts');
        const gameActions=await evaluate(`(()=>[...document.querySelectorAll('.lf-account-card-head button[aria-label^="Edit "], .lf-account-card-head button[aria-label^="Hapus "]')].map(el=>({height:el.getBoundingClientRect().height,visible:getComputedStyle(el).display!=='none'})))()`);
        if(gameActions.length<2||gameActions.some(action=>action.visible&&action.height<40))throw Error('F18 game account action missing or below 40px '+JSON.stringify({width,gameActions}));

        // Wallet uses the same component and chip geometry as Checkout.
        await navigate('/account/wallet');
        for(const category of ['E-Wallet','Virtual Account']){
            const before=await evaluate(`(()=>{
                const region=document.querySelector('[aria-label="Pilih metode pembayaran top up"]');
                const group=[...(region?.querySelectorAll('.lf-payment-group-card')||[])].find(g=>g.querySelector('.lf-payment-group-copy strong')?.textContent.trim()===${JSON.stringify(category)});
                const preview=group?.querySelector('.lf-payment-brand-strip--logos');
                const style=preview&&getComputedStyle(preview);
                const item=preview?.querySelector('.lf-payment-brand-logo');
                const itemStyle=item&&getComputedStyle(item);
                return {found:!!group,images:preview?.querySelectorAll('img').length||0,text:preview?.textContent?.trim()||'',wrap:style?.flexWrap,overflow:style?.overflow,shrink:itemStyle?.flexShrink,minWidth:itemStyle?.minWidth};
            })()`);
            if(!before.found||before.images<1||before.text||before.wrap!=='nowrap'||before.overflow!=='hidden'||Number(before.shrink)!==1||before.minWidth!=='0px')throw Error('Shared Wallet logo row must stay shrinkable on one line '+JSON.stringify({width,category,before}));
            await evaluate(`(()=>{
                const region=document.querySelector('[aria-label="Pilih metode pembayaran top up"]');
                const group=[...region.querySelectorAll('.lf-payment-group-card')].find(g=>g.querySelector('.lf-payment-group-copy strong')?.textContent.trim()===${JSON.stringify(category)});
                group.querySelector('.lf-payment-group-main').click();
            })()`);
            await pause(100);
            const after=await evaluate(`(()=>{
                const region=document.querySelector('[aria-label="Pilih metode pembayaran top up"]');
                const group=[...region.querySelectorAll('.lf-payment-group-card')].find(g=>g.querySelector('.lf-payment-group-copy strong')?.textContent.trim()===${JSON.stringify(category)});
                const header=group?.querySelector('.lf-payment-group-main');
                const title=header?.querySelector('.lf-payment-group-copy strong');
                const description=header?.querySelector('.lf-payment-group-copy small');
                const grid=group?.querySelector('.lfx-payment-method-grid--compact');
                const cols=grid?getComputedStyle(grid).gridTemplateColumns.split(' ').length:0;
                const h=header?.getBoundingClientRect();
                return {selected:group?.classList.contains('selected'),preview:!!group?.querySelector('.lf-payment-brand-strip--logos'),cols,method:!!grid?.querySelector('.lfx-payment-method-card strong'),scroll:document.documentElement.scrollWidth,descriptionInside:!!description&&description.getBoundingClientRect().bottom<=h.bottom+1,titleInside:!!title&&title.getBoundingClientRect().left>=h.left-1};
            })()`);
            if(!after.selected||after.preview||after.cols!==2||!after.method||!after.descriptionInside||!after.titleInside||after.scroll>width+2)throw Error('Wallet shared picker geometry invalid '+JSON.stringify({width,category,after}));
            await mkdir('checkout-visual-results',{recursive:true});
            const screenshot=await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:true});
            await writeFile('checkout-visual-results/wallet-'+(category==='E-Wallet'?'ewallet':'va')+'-'+width+'.png',Buffer.from(screenshot.data,'base64'));
            console.log('PASS shared Wallet picker '+category+' '+width);
        }
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
