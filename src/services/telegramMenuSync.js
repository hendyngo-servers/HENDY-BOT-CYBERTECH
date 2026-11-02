import {executeD1Query} from "./productionD1.js";
import {buildMainKeyboard} from "./telegramKeyboard.js";
const delay=ms=>new Promise(resolve=>setTimeout(resolve,ms));
function botToken(botKey){return String(botKey==="secondary"?process.env.BOT_SECOND_TOKEN||"":process.env.BOT_TOKEN||"").trim();}
async function sendMenu(token,row,markup){
 const response=await fetch("https://api.telegram.org/bot"+token+"/sendMessage",{
  method:"POST",headers:{"Content-Type":"application/json"},
  body:JSON.stringify({chat_id:row.chat_id,text:"Menu chức năng đã được cập nhật.",reply_markup:markup}),
  signal:AbortSignal.timeout(10000)
 });
 const payload=await response.json().catch(()=>({}));
 if(!response.ok||payload.ok!==true)throw new Error(payload.description||"Telegram sendMessage failed");
 return payload.result;
}
export async function refreshTelegramMenuMessages(settings,botKey="primary"){
 const token=botToken(botKey);
 if(!token)return {botKey,updated:0,failed:0,total:0,skipped:true};
 const result=await executeD1Query(
  "SELECT chat_id,message_id FROM telegram_bot_menu_messages WHERE bot_key=? ORDER BY updated_at DESC LIMIT 300",[botKey]
 );
 const rows=result.results||[],markup=buildMainKeyboard(process.env,settings,botKey);
 let updated=0,failed=0;
 for(let offset=0;offset<rows.length;offset+=20){
  const batch=rows.slice(offset,offset+20);
  const outcomes=await Promise.all(batch.map(async row=>{
   try{
    const sent=await sendMenu(token,row,markup);
    if(sent?.message_id)await executeD1Query(
     "UPDATE telegram_bot_menu_messages SET message_id=?,updated_at=datetime('now') WHERE bot_key=? AND chat_id=?",
     [Number(sent.message_id),botKey,String(row.chat_id)]
    );
    return true;
   }catch(error){console.warn("Telegram menu refresh failed",botKey,row.chat_id,error.message);return false;}
  }));
  updated+=outcomes.filter(Boolean).length;failed+=outcomes.filter(v=>!v).length;
  if(offset+20<rows.length)await delay(1000);
 }
 return {botKey,updated,failed,total:rows.length,skipped:false};
}
