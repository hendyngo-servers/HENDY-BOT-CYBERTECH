import React,{useEffect,useState} from "react";
import {Bot,Save,RefreshCw,Keyboard,Info} from "lucide-react";
import {telegramFetch} from "../services/api.js";
const BUTTONS=[
 ["mini_game_live","🎮 MINI GAME LIVE","Mở khu vực Mini Game Live"],["app_tool","🧰 THUÊ TOOL","Xem công cụ và thời hạn thuê"],
 ["buy_code","💻 MUA CODE","Xem kho mã và sản phẩm"],["wallet","💳 NẠP TIỀN","Mở ví và hướng dẫn nạp tiền"],
 ["otp_proxy","🛡️ OTP & PROXY","Xem dịch vụ OTP và Proxy"],["vip_tools","👑 KHO VIP","Mở kho công cụ VIP"],
 ["support","💎 TRUNG TÂM KHÁCH HÀNG","Mở trung tâm hỗ trợ"],["pairing","💞 GHÉP ĐÔI","Mở tính năng ghép đôi"],
 ["referral","🤝 GIỚI THIỆU REF","Xem liên kết giới thiệu"],["giftcode","🎟️ GIFTCODE","Nhập mã quà tặng"]
];
const defaults=()=>({menu_mode:"inline_keyboard",intro_text:"Chào mừng bạn đến với Hendy Bot.",menu_columns:2,
 persistent_keyboard:true,resize_keyboard:true,
 button_config:Object.fromEntries(BUTTONS.map(([module,label,description])=>[module,{label,description,style:"primary",enabled:true}]))});
export function BotFleetMenuCard({showToast}){
 const [botKey,setBotKey]=useState("primary"),[bots,setBots]=useState([]),[config,setConfig]=useState(defaults);
 const [loading,setLoading]=useState(true),[saving,setSaving]=useState(false),[reload,setReload]=useState(0);
 const current=bots.find(item=>item.botKey===botKey);
 useEffect(()=>{
  let active=true;setLoading(true);
  telegramFetch("/api/admin/bot-menu").then(async response=>{
   const data=await response.json();if(!response.ok)throw new Error(data.error||"Không thể tải cấu hình bot.");
   if(!active)return;setBots(data.bots||[]);
   const saved=data.configs?.[botKey]||{};
   setConfig({...defaults(),...saved,button_config:{...defaults().button_config,...(saved.button_config||{})}});
  }).catch(error=>showToast(error.message,"error")).finally(()=>{if(active)setLoading(false);});
  return()=>{active=false;};
 },[botKey,reload]);
 const updateButton=(module,patch)=>setConfig(value=>({...value,button_config:{...value.button_config,[module]:{...value.button_config[module],...patch}}}));
 const save=async event=>{
  event.preventDefault();setSaving(true);
  try{
   const response=await telegramFetch("/api/admin/bot-menu",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({botKey,config})});
   const result=await response.json();if(!response.ok)throw new Error(result.error||"Không thể lưu menu.");
   const sync=result.menuRefresh||{};
   showToast(sync.skipped?"Đã lưu cấu hình; bot chưa có token.":"Đã cập nhật menu "+botKey+": "+(sync.updated||0)+"/"+(sync.total||0)+" chat.");
   setReload(value=>value+1);
  }catch(error){showToast(error.message,"error");}finally{setSaving(false);}
 };
 return <section className="glass-panel p-6 space-y-5 border-cyan-500/20">
  <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
   <div className="flex items-center gap-3"><div className="w-10 h-10 rounded-xl bg-cyan-500/15 text-cyan-300 flex items-center justify-center"><Bot className="w-5 h-5"/></div>
    <div><h4 className="font-bold text-white text-base">Quản lý hai Telegram Bot</h4><p className="text-xs text-slate-400">Hai bot dùng chung D1 nhưng có token, webhook, Admin và menu độc lập.</p></div></div>
   <button type="button" onClick={()=>setReload(v=>v+1)} className="px-3 py-2 rounded-lg bg-white/5 text-xs text-white"><RefreshCw className="inline w-3.5 h-3.5 mr-1"/>Tải lại</button>
  </div>
  <div className="flex flex-wrap gap-2">{[["primary","Bot chính"],["secondary","Bot phụ"]].map(([key,label])=>{
   const bot=bots.find(item=>item.botKey===key);
   return <button key={key} type="button" onClick={()=>setBotKey(key)} className={"px-4 py-2 rounded-xl text-xs font-semibold border "+(botKey===key?"bg-cyan-500/15 border-cyan-400/40 text-cyan-200":"bg-white/[0.03] border-white/10 text-slate-300")}>{label} · {bot?.configured?"Đã cấu hình":"Chưa có token"}</button>;
  })}</div>
  {loading?<p className="text-sm text-slate-400">Đang tải cấu hình…</p>:<form onSubmit={save} className="space-y-4">
   <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
    <label className="text-xs text-slate-300 space-y-1"><span>Kiểu menu</span><select value={config.menu_mode} onChange={e=>setConfig(v=>({...v,menu_mode:e.target.value}))} className="w-full rounded-xl bg-slate-900 border border-white/10 px-3 py-2 text-white"><option value="reply_keyboard">Reply Keyboard · cố định dưới ô nhập</option><option value="inline_keyboard">Inline Keyboard · dưới tin nhắn</option></select></label>
    <label className="text-xs text-slate-300 space-y-1"><span>Số cột</span><select value={String(config.menu_columns)} onChange={e=>setConfig(v=>({...v,menu_columns:Number(e.target.value)}))} className="w-full rounded-xl bg-slate-900 border border-white/10 px-3 py-2 text-white"><option value="1">1 cột</option><option value="2">2 cột</option><option value="3">3 cột</option></select></label>
    <label className="text-xs text-slate-300 space-y-1"><span>Lời giới thiệu</span><input maxLength={1000} value={config.intro_text} onChange={e=>setConfig(v=>({...v,intro_text:e.target.value}))} className="w-full rounded-xl bg-white/5 border border-white/10 px-3 py-2 text-white"/></label>
   </div>
   {config.menu_mode==="reply_keyboard"&&<div className="flex flex-wrap gap-4 text-xs text-slate-300">
    <label><input type="checkbox" checked={config.persistent_keyboard} onChange={e=>setConfig(v=>({...v,persistent_keyboard:e.target.checked}))}/> Giữ bàn phím luôn hiển thị</label>
    <label><input type="checkbox" checked={config.resize_keyboard} onChange={e=>setConfig(v=>({...v,resize_keyboard:e.target.checked}))}/> Tự co giãn bàn phím</label>
   </div>}
   <div className="space-y-2"><div className="flex items-center gap-2"><Keyboard className="w-4 h-4 text-cyan-300"/><h5 className="text-sm font-semibold text-white">Nhãn, mô tả và kiểu nút</h5></div>
    {BUTTONS.map(([module,label,description],index)=>{const item=config.button_config?.[module]||{label,description,style:"primary",enabled:true};return <div key={module} className="grid grid-cols-1 md:grid-cols-[auto_1fr_1fr_120px_auto] gap-2 items-center rounded-xl bg-white/[0.035] border border-white/5 p-3">
     <span className="text-xs text-slate-500 font-mono">{String(index+1).padStart(2,"0")}</span>
     <input maxLength={48} value={item.label} onChange={e=>updateButton(module,{label:e.target.value})} aria-label={"Nhãn "+module} className="min-w-0 rounded-lg bg-black/20 border border-white/10 px-3 py-2 text-xs text-white"/>
     <input maxLength={160} value={item.description||""} onChange={e=>updateButton(module,{description:e.target.value})} placeholder={description} aria-label={"Mô tả "+module} className="min-w-0 rounded-lg bg-black/20 border border-white/10 px-3 py-2 text-xs text-white"/>
     <select value={item.style||"primary"} onChange={e=>updateButton(module,{style:e.target.value})} className="rounded-lg bg-slate-900 border border-white/10 px-2 py-2 text-xs text-white"><option value="primary">Chính</option><option value="success">Thành công</option><option value="danger">Cảnh báo</option></select>
     <label className="flex items-center gap-2 text-xs text-slate-300"><input type="checkbox" checked={item.enabled!==false} onChange={e=>updateButton(module,{enabled:e.target.checked})}/>Hiện</label>
    </div>;})}
   </div>
   <div className="flex flex-wrap items-center justify-between gap-3"><p className="text-[11px] text-slate-500 flex items-start gap-2"><Info className="w-4 h-4 shrink-0"/>Màu nút native do Telegram quyết định; style lưu làm metadata, màu giao diện Mini App được tùy chỉnh riêng.</p>
    <button disabled={saving||!current?.configured} className="px-4 py-2 rounded-xl bg-cyan-600 hover:bg-cyan-500 text-white text-xs font-bold flex items-center gap-2 disabled:opacity-50"><Save className="w-4 h-4"/>{saving?"Đang lưu…":"Lưu cấu hình bot"}</button>
   </div>
   {!current?.configured&&<p className="text-xs text-amber-300">Bot chưa có token trong Render Environment. Thêm token và webhook secret để kích hoạt.</p>}
  </form>}
  <div className="text-[11px] text-slate-500">Cấu hình menu lưu riêng theo bot trong Cloudflare D1, không thay đổi dữ liệu thành viên/đơn hàng.</div>
 </section>;
}
