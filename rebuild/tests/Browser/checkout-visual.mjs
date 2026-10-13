import {chromium} from 'playwright';
import fs from 'node:fs/promises';
const output='checkout-visual-results';await fs.mkdir(output,{recursive:true});
const browser=await chromium.launch({executablePath:process.argv[2],args:['--no-sandbox','--disable-dev-shm-usage'],headless:true});
const lab=process.env.CHECKOUT_LAB_URL||'https://lfamilia-checkout-lab.vercel.app/';
const base=process.env.CHECKOUT_STORE_URL||'http://127.0.0.1:8000';
const report=[];
const sizes=[[360,780],[390,844],[430,932],[640,960],[768,1024],[1024,768],[1366,768],[1440,900],[1920,1080],[2560,1440]];
async function inspect(page){return page.evaluate(()=>{
 const box=selector=>{const e=document.querySelector(selector);if(!e)return null;const r=e.getBoundingClientRect(),c=getComputedStyle(e);return {x:r.x,y:r.y,width:r.width,height:r.height,font:c.fontSize,line:c.lineHeight,transform:c.transform,origin:c.transformOrigin,fit:c.objectFit};};
 return {scroll:document.documentElement.scrollWidth,hero:box('.lfx-hero'),image:box('.lfx-hero img'),cover:box('.lfx-cover-frame'),coverImage:box('.lfx-cover-frame img'),section:box('.lfx-panel-header'),card:box('.lfx-nominal'),summary:box('#lfx-bottom-summary'),pay:box('#lfx-bottom-pay'),sidebar:!!document.querySelector('.lf-order-summary'),adminCss:[...document.querySelectorAll('link[rel=stylesheet]')].some(e=>/\/admin-[^/]+\.css/.test(e.href)),broken:[...document.images].filter(e=>e.getBoundingClientRect().width>0&&e.complete&&!e.naturalWidth).map(e=>e.getAttribute('src')),heights:[...document.querySelectorAll('.lfx-nominal')].map(e=>e.getBoundingClientRect().height)};
});}
try{
 for(const [width,height] of sizes){
  const item={width,height,labUrl:lab};
  for(const [name,url] of [['lab',lab],['store',base+'/catalog/checkout-lab-reference']]){
   const page=await browser.newPage({viewport:{width,height}});const errors=[];page.on('pageerror',e=>errors.push(e.message));
   await page.goto(url,{waitUntil:'networkidle',timeout:45000});await page.locator('.lfx').waitFor();
   item[name]=await inspect(page);await page.screenshot({path:`${output}/${name}-${width}.png`,fullPage:true});
   if(name==='store'){
    const result=item.store,expected=width<768?54:width<1024?62:70;
    if(result.scroll>width+1||result.broken.length||result.sidebar||result.adminCss||errors.length)throw Error('Checkout regression '+JSON.stringify({width,result,errors}));
    if(result.summary.height!==expected||result.pay.height!==expected||Math.abs(result.summary.y-result.pay.y)>.1)throw Error('Sticky alignment mismatch '+JSON.stringify(item));
    if(Math.abs(result.cover.width-result.cover.height)>.1)throw Error('Product cover must be square');
    for(const field of ['hero','cover','section','card'])for(const dimension of ['width','height'])if(Math.abs(result[field][dimension]-item.lab[field][dimension])>1.1)throw Error('Lab geometry mismatch '+JSON.stringify({width,field,dimension,lab:item.lab[field],store:result[field]}));
    if(new Set(result.heights.map(h=>Math.round(h*10))).size>1)throw Error('Nominal card heights differ');
    await page.evaluate(()=>{const shell=document.querySelector('.lf-customer');for(const viewport of ['mobile','desktop']){shell.style.setProperty('--lf-config-'+viewport+'-controlHeight','56px');shell.style.setProperty('--lf-config-'+viewport+'-heading','32px');shell.style.setProperty('--lf-config-'+viewport+'-action','24px');}});
    const isolated=await inspect(page);
    for(const part of ['summary','pay','section','card'])for(const dimension of ['width','height','font'])if(isolated[part][dimension]!==result[part][dimension])throw Error('Admin typography crossed checkout boundary: '+part+' '+dimension);

    await page.locator('#lfx-bottom-summary').click();await page.locator('.lfx-modal[role=dialog]').waitFor();await page.screenshot({path:`${output}/summary-${width}.png`});
   }
   await page.close();
  }
  report.push(item);console.log('PASS checkout Lab comparison '+width+'x'+height);
 }
 const page=await browser.newPage({viewport:{width:390,height:844}});
 await page.goto(base+'/catalog/browser-checkout-game');
 await page.locator('.lfx-nominal').first().click();
 await page.waitForFunction(()=>/Rp/.test(document.querySelector('.lfx-bottom-amount strong')?.textContent||''));
 await page.locator('#lfx-account input').first().fill('12345678');
 await page.locator('#lfx-contact input[type=tel]').fill('081234567890');
 await page.locator('#lfx-contact input[type=email]').fill('visual-customer@example.test');
 await page.waitForTimeout(700);
 await page.locator('#lfx-bottom-pay').click();await page.locator('.lfx-agreement').waitFor();
 await page.screenshot({path:`${output}/confirmation-real-quote.png`});
 const quote=await page.evaluate(async()=>{
  const props=JSON.parse(document.querySelector('#app').dataset.page).props;
  const channel=props.paymentChannels.find(x=>x.available!==false);
  const response=await fetch('/checkout/quote',{method:'POST',headers:{'Content-Type':'application/json',Accept:'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content},body:JSON.stringify({package_id:props.packages[0].id,payment_channel_code:channel.code,include_channel_totals:true})});
  return {status:response.status,body:await response.json()};
 });
 if(quote.status!==200||!Number.isFinite(quote.body.total_idr)||!quote.body.channel_quotes)throw Error('Real server quote failed '+JSON.stringify(quote));
 await page.locator('.lfx-modal-header button').click();await page.locator('.lfx-voucher-browse').click();await page.locator('.lfx-offer-modal').waitFor();
 await page.screenshot({path:`${output}/voucher-real-backend.png`});
 await fs.writeFile(output+'/report.json',JSON.stringify({baseline:'a42f97d3a6d5a8a5cb59bd651ce9ffe1a8c928f2',report,quote,noFinancialActions:true},null,2));
 await page.close();
}finally{await browser.close();}
