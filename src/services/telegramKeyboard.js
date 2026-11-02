const BASE_ACTIONS=[
 ["🎮 MINI GAME LIVE","mini_game_live"],["🧰 THUÊ TOOL","app_tool"],["💻 MUA CODE","buy_code"],["💳 NẠP TIỀN","wallet"],
 ["🛡️ OTP & PROXY","otp_proxy"],["👑 KHO VIP","vip_tools"],["💎 TRUNG TÂM KHÁCH HÀNG","support"],
 ["💞 GHÉP ĐÔI","pairing"],["🤝 GIỚI THIỆU REF","referral"],["🎟️ GIFTCODE","giftcode"]
];
export function getMenuActions(){return BASE_ACTIONS.map(([label,module])=>({label,module}));}
function appUrl(module,env,botKey){
 const base=String(env.WEBAPP_URL||env.BASE_URL||"").trim();if(!base)return null;
 try{const url=new URL(base);if(url.protocol!=="https:")return null;url.searchParams.set("module",module);url.searchParams.set("bot",botKey);return url.toString();}catch{return null;}
}
function externalButton(label,raw){
 const value=String(raw||"").trim();if(!value)return null;
 try{const url=new URL(value);if(url.protocol!=="https:"||!["t.me","telegram.me"].includes(url.hostname.toLowerCase())||url.pathname==="/")return null;return {text:label,url:url.toString()};}catch{return null;}
}
function chunk(items,size){const rows=[];for(let i=0;i<items.length;i+=size)rows.push(items.slice(i,i+size));return rows;}
export function buildMainKeyboard(env=process.env,settings={},botKey="primary"){
 const menu=settings.bot_menu||{},overrides=menu.button_config||{},legacy=Array.isArray(settings.menu_config)?settings.menu_config:[];
 const mode=menu.menu_mode==="reply_keyboard"?"reply_keyboard":"inline_keyboard";
 const columns=Math.max(1,Math.min(3,Number(menu.menu_columns||2)));
 const buttons=BASE_ACTIONS.map(([defaultLabel,module])=>{
  const old=legacy.find(item=>item?.module===module),entry=overrides[module]||{};
  if(entry.enabled===false||(!Object.keys(entry).length&&old?.enabled===false))return null;
  const label=String(entry.label||old?.label||defaultLabel).trim().slice(0,48)||defaultLabel,url=appUrl(module,env,botKey);
  if(mode==="reply_keyboard")return {text:label,...(url?{web_app:{url}}:{})};
  return url?{text:label,web_app:{url}}:{text:label,callback_data:"open_"+module};
 }).filter(Boolean);
 const rows=chunk(buttons,columns);
 if(mode==="reply_keyboard"){
  const community=[
   (settings.group_chat_url??env.GROUP_CHAT_URL)?{text:"👥 NHÓM CHAT"}:null,
   (settings.news_channel_url??env.NEWS_CHANNEL_URL)?{text:"📢 KÊNH THÔNG BÁO"}:null,
   (settings.support_url??env.SUPPORT_URL)?{text:"☎️ LIÊN HỆ CSKH"}:null
  ].filter(Boolean);
  if(community.length)rows.push(community);
  return {keyboard:rows,resize_keyboard:menu.resize_keyboard!==false,is_persistent:menu.persistent_keyboard!==false,input_field_placeholder:"Chọn chức năng Hendy Bot"};
 }
 const links=[
  externalButton("👥 NHÓM CHAT",settings.group_chat_url??env.GROUP_CHAT_URL),
  externalButton("📢 KÊNH THÔNG BÁO",settings.news_channel_url??env.NEWS_CHANNEL_URL)
 ].filter(Boolean);
 if(links.length)rows.push(links);
 const support=externalButton("☎️ LIÊN HỆ CSKH",settings.support_url??env.SUPPORT_URL);if(support)rows.push([support]);
 return {inline_keyboard:rows};
}
