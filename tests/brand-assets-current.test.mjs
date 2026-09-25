import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";
const root=process.cwd();
const read=(file)=>fs.readFileSync(path.join(root,file),"utf8");
test("storefront brand uses the verified LF icon",()=>{
 const brand=read("components/store-brand.tsx");
 assert.match(brand,/src="\/icon-192\.png"/);
 assert.doesNotMatch(brand,/lfamilia-neon-logo\.webp\?v=/);
});
test("footer banner is native and full viewport width",()=>{
 const footer=read("components/store-footer.tsx");
 assert.match(footer,/flex h-\[72px\] w-screen/);
 assert.match(footer,/src="\/icon-192\.png"/);
 assert.match(footer,/LFAMILIA/);
 assert.match(footer,/STORE/);
 assert.doesNotMatch(footer,/lfamilia-footer-banner\.webp/);
});
