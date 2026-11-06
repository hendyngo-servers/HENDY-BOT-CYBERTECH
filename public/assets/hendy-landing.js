(() => {
  'use strict';
  const byId = (id) => document.getElementById(id);
  const status = byId('bot-status');
  const footerStatus = byId('footer-bot-status');
  const botActions = ['header-bot-link','hero-bot-link','footer-bot-link'].map(byId).filter(Boolean);
  function safeTelegramUrl(value) {
    if (typeof value !== 'string' || value.length > 512) return '';
    try {
      const url = new URL(value);
      const hosts = new Set(['t.me','telegram.me','www.telegram.me']);
      if (url.protocol !== 'https:' || !hosts.has(url.hostname.toLowerCase()) || url.username || url.password || url.port) return '';
      if (!/^\/[A-Za-z0-9_+./-]{1,200}$/.test(url.pathname) || url.pathname.includes('..')) return '';
      return 'https://t.me' + url.pathname;
    } catch (_) { return ''; }
  }
  function activateBot(element,url,label) {
    if (!element) return;
    if (!url) { element.removeAttribute('href');element.setAttribute('aria-disabled','true');element.classList.add('is-disabled');return; }
    element.href=url;element.target='_blank';element.rel='noopener noreferrer';element.removeAttribute('aria-disabled');element.classList.remove('is-disabled');
    if(label) element.setAttribute('aria-label',label);
  }
  function activateOptionalLink(id,candidate) {
    const element=byId(id);if(!element)return;const url=safeTelegramUrl(candidate);
    if(!url){element.hidden=true;element.removeAttribute('href');return;}
    element.href=url;element.target='_blank';element.rel='noopener noreferrer';element.hidden=false;
  }
  async function loadPublicConfig() {
    if(status)status.textContent='Đang đồng bộ danh tính Bot từ Telegram…';
    const controller=new AbortController();const timeout=window.setTimeout(()=>controller.abort(),5000);
    try {
      const response=await fetch('/api/v1/public/config',{method:'GET',headers:{Accept:'application/json'},cache:'no-store',credentials:'same-origin',signal:controller.signal});
      if(!response.ok)throw new Error('Không thể tải cấu hình Bot.');
      const config=await response.json();const username=typeof config.botUsername==='string'?config.botUsername.trim().replace(/^@/,''):'';
      const valid=/^[A-Za-z][A-Za-z0-9_]{4,31}$/.test(username);const link=valid?'https://t.me/'+username:'';
      botActions.forEach(el=>activateBot(el,link,valid?'Mở Telegram Bot @'+username:'Bot chưa sẵn sàng'));
      activateOptionalLink('group-link',config.groupUrl);activateOptionalLink('channel-link',config.channelUrl);activateOptionalLink('support-link',config.supportUrl);
      if(status)status.textContent=valid?'Bot chính thức đã đồng bộ: @'+username:'Bot chưa sẵn sàng. Vui lòng thử lại sau khi máy chủ đồng bộ getMe().';
      if(footerStatus)footerStatus.textContent=valid?'Kết nối Telegram: @'+username:'Danh tính Bot đang chờ đồng bộ từ Telegram.';
    } catch(error) {
      const message=error&&error.name==='AbortError'?'Máy chủ phản hồi chậm. Vui lòng tải lại trang sau ít phút.':'Chưa thể tải thông tin Bot. Các liên kết dịch vụ đang được tạm khóa.';
      if(status)status.textContent=message;if(footerStatus)footerStatus.textContent='Không tải được danh tính Bot.';
      botActions.forEach(el=>activateBot(el,'','Bot chưa sẵn sàng'));
    } finally {window.clearTimeout(timeout);}
  }
  const year=byId('current-year');if(year)year.textContent=String(new Date().getFullYear());loadPublicConfig();
})();