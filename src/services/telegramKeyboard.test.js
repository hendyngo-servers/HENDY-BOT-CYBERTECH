import {test,describe} from "node:test";
import assert from "node:assert/strict";
import {buildMainKeyboard} from "./telegramKeyboard.js";
describe("Telegram keyboard layouts",()=>{
 test("keeps existing inline keyboard default",()=>{
  const kb=buildMainKeyboard({WEBAPP_URL:"https://mini.example.com",GROUP_CHAT_URL:"https://t.me/example_group",SUPPORT_URL:"https://t.me/example_support"});
  const buttons=kb.inline_keyboard.flat();assert.equal(buttons.length,12);assert.equal(buttons.filter(b=>b.web_app).length,10);
  assert.equal(buttons.filter(b=>b.url).length,2);assert.ok(buttons[0].web_app.url.includes("bot=primary"));
 });
 test("builds persistent secondary Reply Keyboard and respects visibility",()=>{
  const kb=buildMainKeyboard({WEBAPP_URL:"https://mini.example.com"},{bot_menu:{menu_mode:"reply_keyboard",menu_columns:2,persistent_keyboard:true,resize_keyboard:true,
   button_config:{mini_game_live:{label:"GAME PHỤ",enabled:true},app_tool:{label:"TOOL OFF",enabled:false}}}},"secondary");
  assert.ok(Array.isArray(kb.keyboard));assert.equal(kb.is_persistent,true);const buttons=kb.keyboard.flat();
  assert.ok(buttons.some(b=>b.text==="GAME PHỤ"));assert.ok(!buttons.some(b=>b.text==="TOOL OFF"));
  assert.ok(buttons[0].web_app.url.includes("bot=secondary"));
 });
 test("preserves legacy overrides for older Mini App config",()=>{
  const kb=buildMainKeyboard({WEBAPP_URL:"https://mini.example.com"},{menu_config:[{module:"mini_game_live",label:"GAME MỚI"},{module:"app_tool",enabled:false}]});
  const buttons=kb.inline_keyboard.flat();assert.ok(buttons.some(b=>b.text==="GAME MỚI"));assert.ok(!buttons.some(b=>b.text==="🧰 THUÊ TOOL"));
 });
});
