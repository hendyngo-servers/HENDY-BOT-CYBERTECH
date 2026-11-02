const API_BASE = String(import.meta.env.VITE_API_BASE_URL || "").replace(/\/+$/, "");
export function getTelegramBotKey(){
  try{return new URLSearchParams(window.location.search).get("bot")==="secondary"?"secondary":"primary";}
  catch{return "primary";}
}
export function telegramFetch(resource,options={}){
  const initData=window.Telegram?.WebApp?.initData||"";
  const headers=new Headers(options.headers||{});
  if(initData)headers.set("Authorization","tma "+initData);
  headers.set("X-Telegram-Bot-Key",getTelegramBotKey());
  const url=resource.startsWith("http")?resource:API_BASE+resource;
  return fetch(url,{...options,headers,credentials:"omit"});
}
export function getRealtimeUrl(){
  const base=API_BASE||window.location.origin,url=new URL("/ws",base);
  url.protocol=url.protocol==="https:"?"wss:":"ws:";return url.toString();
}
