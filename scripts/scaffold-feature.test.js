import {test} from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import {buildFeatureFiles,validateFeatureName,writeFeature} from "./scaffold-feature.js";
test("validates kebab-case and rejects traversal",()=>{assert.equal(validateFeatureName("order-history"),"order-history");assert.throws(()=>validateFeatureName("../secret"));assert.throws(()=>validateFeatureName("UpperCase"));});
test("generates disabled ESM feature files",()=>{const files=buildFeatureFiles({name:"order-history",category:"api",description:"Lịch sử đơn hàng"});assert.ok(files["src/features/order-history/feature.json"].includes('"enabled": false'));assert.ok(files["src/features/order-history/index.js"].includes("export async function register"));assert.throws(()=>buildFeatureFiles({name:"bad",category:"other"}));});
test("never overwrites an existing scaffold",()=>{const cwd=fs.mkdtempSync(path.join(os.tmpdir(),"hendy-scaffold-"));try{assert.equal(writeFeature({name:"vip-tools",category:"bot"},cwd).length,3);assert.throws(()=>writeFeature({name:"vip-tools",category:"bot"},cwd));}finally{fs.rmSync(cwd,{recursive:true,force:true});}});
