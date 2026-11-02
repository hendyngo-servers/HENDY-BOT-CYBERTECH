import React, { useState, useEffect, useMemo } from "react";
import { telegramFetch, getRealtimeUrl, getTelegramBotKey } from "./services/api.js";
import {
  ShoppingBag,
  Wallet,
  Package,
  Award,
  Gift,
  Users,
  LifeBuoy,
  Link as LinkIcon,
  MessageCircle,
  Megaphone,
  ShieldCheck,
  Bot,
  Cloud,
  Settings,
  Search,
  Star,
  Download,
  CheckCircle2,
  Clock,
  AlertCircle,
  ChevronRight,
  ExternalLink,
  Plus,
  RefreshCw,
  Send,
  Zap,
  ArrowUpRight,
  Server,
  Layers,
  Database,
  Key,
  X,
  CreditCard,
  QrCode,
  Tag,
  Check,
  History,
  FileText
} from "lucide-react";
import { MaintenanceBanner } from "./components/MaintenanceBanner.jsx";
import { TelegramWebhookManager } from "./components/TelegramWebhookManager.jsx";
import { D1StatsAndConsole } from "./components/D1StatsAndConsole.jsx";
import { BotSettingsCard } from "./components/BotSettingsCard.jsx";
import { MenuAppearanceCard } from "./components/MenuAppearanceCard.jsx";
import { BotFleetMenuCard } from "./components/BotFleetMenuCard.jsx";
import { RuntimeConfigManager } from "./components/RuntimeConfigManager.jsx";

// Safe numeric and currency formatting helpers
export const formatVnd = (value) => {
  if (value === null || value === undefined || value === "") return "0";
  const num = Number(value);
  if (isNaN(num)) return "0";
  return num.toLocaleString("vi-VN");
};

export const formatPriceBadge = (itemOrPrice) => {
  if (itemOrPrice === null || itemOrPrice === undefined) return "Liên hệ";
  const val = typeof itemOrPrice === "object" ? (itemOrPrice.price ?? itemOrPrice.unit_price) : itemOrPrice;
  if (val === null || val === undefined) return "Liên hệ";
  const num = Number(val);
  if (isNaN(num)) return "Liên hệ";
  if (num === 0) return "Miễn Phí";
  return `${num.toLocaleString("vi-VN")} đ`;
};

// Initial default categories
const DEFAULT_CATEGORIES = [
  { id: "all", name: "Tất cả" },
  { id: "tools", name: "Công cụ & Tiện ích" },
  { id: "gaming", name: "Dịch vụ Game" },
  { id: "source_code", name: "Mã nguồn Dev" },
  { id: "proxy", name: "Proxy & Mạng" },
  { id: "vip_accounts", name: "Tài khoản VIP" }
];

export default function App() {
  const [activeTab, setActiveTab] = useState("store"); // store | member | admin | support
  const [activeCategory, setActiveCategory] = useState("all");
  const [searchQuery, setSearchQuery] = useState("");

  // Data states
  const [catalog, setCatalog] = useState([]);
  const [userProfile, setUserProfile] = useState(null);
  const [wallet, setWallet] = useState(null);
  const [orders, setOrders] = useState([]);
  const [vipProfile, setVipProfile] = useState(null);
  const [referralProfile, setReferralProfile] = useState(null);
  const [tickets, setTickets] = useState([]);
  const [adminOrders, setAdminOrders] = useState([]);
  const [adminDeposits, setAdminDeposits] = useState([]);
  const [adminGiftcodes, setAdminGiftcodes] = useState([]);
  const [adminStats, setAdminStats] = useState(null);
  const [botSettings, setBotSettings] = useState(null);
  const [webhookInfo, setWebhookInfo] = useState(null);
  const [realtimeStatus, setRealtimeStatus] = useState("connecting");
  const [realtimeSocket, setRealtimeSocket] = useState(null);
  const [configRegistry, setConfigRegistry] = useState(null);
  const [loading, setLoading] = useState(true);
  const [notice, setNotice] = useState(null);

  // Modals & Selected items
  const [selectedProduct, setSelectedProduct] = useState(null);
  const [showDepositModal, setShowDepositModal] = useState(false);
  const [depositAmount, setDepositAmount] = useState(100000);
  const [depositResult, setDepositResult] = useState(null);
  const [redeemCodeInput, setRedeemCodeInput] = useState("");
  const [selectedTicket, setSelectedTicket] = useState(null);
  const [ticketReplyMessage, setTicketReplyMessage] = useState("");
  const [newTicketSubject, setNewTicketSubject] = useState("");
  const [newTicketMessage, setNewTicketMessage] = useState("");
  const [newTicketCategory, setNewTicketCategory] = useState("order");

  // Admin Packaging Studio Form State
  const [packageForm, setPackageForm] = useState({
    title: "",
    category: "tools",
    price: 150000,
    version: "1.0.0",
    packageId: "",
    tagline: "",
    r2Bucket: "hendy-bot-assets",
    r2ObjectKey: "",
    pagesDeployUrl: "",
    description: "",
    tags: "cloudflare,r2,production"
  });

  // Helper toast notice
  const showToast = (message, type = "success") => {
    setNotice({ message, type });
    setTimeout(() => setNotice(null), 4000);
  };

  // Telegram WebApp Init
  useEffect(() => {
    if (window.Telegram?.WebApp) {
      const tg = window.Telegram.WebApp;
      tg.ready();
      tg.expand();
      if (tg.setHeaderColor) tg.setHeaderColor("#0a0e17");
      if (tg.setBackgroundColor) tg.setBackgroundColor("#0a0e17");
    }
  }, []);

  // Fetch initial data
  const fetchData = async () => {
    setLoading(true);
    try {
      const publicConfigResponse = await telegramFetch("/api/public-config").catch(() => null);
      if (publicConfigResponse?.ok) {
        const publicConfig = await publicConfigResponse.json();
        setBotSettings((current) => ({ ...(current || {}), ...publicConfig }));
      }

      // Catalog
      const catRes = await telegramFetch("/api/catalog").catch(() => null);
      if (catRes && catRes.ok) {
        const catData = await catRes.json();
        const items = (catData.items || []).map((item) => {
          const rawPrice = item.price !== undefined ? item.price : item.unit_price;
          const parsed = rawPrice !== null && rawPrice !== undefined ? Number(rawPrice) : 0;
          return {
            ...item,
            price: parsed,
            unit_price: parsed
          };
        });
        setCatalog(items);
      }

      // Member Home & Wallet
      const homeRes = await telegramFetch("/api/home").catch(() => null);
      if (homeRes && homeRes.ok) {
        const homeData = await homeRes.json();
        setUserProfile(homeData.user);
      }

      const walletRes = await telegramFetch("/api/wallet").catch(() => null);
      if (walletRes && walletRes.ok) {
        const walletData = await walletRes.json();
        setWallet(walletData);
      }

      const ordersRes = await telegramFetch("/api/orders").catch(() => null);
      if (ordersRes && ordersRes.ok) {
        const ordersData = await ordersRes.json();
        setOrders(ordersData.orders || []);
      }

      const vipRes = await telegramFetch("/api/vip").catch(() => null);
      if (vipRes && vipRes.ok) {
        const vipData = await vipRes.json();
        setVipProfile(vipData);
      }

      const referralRes = await telegramFetch("/api/referral").catch(() => null);
      if (referralRes && referralRes.ok) {
        setReferralProfile(await referralRes.json());
      }

      const ticketRes = await telegramFetch("/api/support/tickets").catch(() => null);
      if (ticketRes && ticketRes.ok) {
        const tData = await ticketRes.json();
        setTickets(tData.tickets || []);
      }

      // Admin Data
      const admOrdersRes = await telegramFetch("/api/admin/orders").catch(() => null);
      if (admOrdersRes && admOrdersRes.ok) {
        const oData = await admOrdersRes.json();
        setAdminOrders(oData.orders || []);
      }

      const admDepRes = await telegramFetch("/api/deposits").catch(() => null);
      if (admDepRes && admDepRes.ok) {
        const dData = await admDepRes.json();
        setAdminDeposits(dData.deposits || []);
      }

      const admGiftRes = await telegramFetch("/api/giftcodes").catch(() => null);
      if (admGiftRes && admGiftRes.ok) {
        const gData = await admGiftRes.json();
        setAdminGiftcodes(gData.giftcodes || []);
      }

      const statsRes = await telegramFetch("/api/admin/stats").catch(() => null);
      if (statsRes && statsRes.ok) {
        const sData = await statsRes.json();
        setAdminStats(sData);
      }

      const settingsRes = await telegramFetch("/api/admin/settings").catch(() => null);
      if (settingsRes && settingsRes.ok) {
        const settsData = await settingsRes.json();
        setBotSettings(settsData.settings);
      }

      const webhookRes = await telegramFetch("/api/admin/telegram/webhook-info").catch(() => null);
      if (webhookRes && webhookRes.ok) {
        const wData = await webhookRes.json();
        setWebhookInfo(wData.webhookInfo?.result || wData.webhookInfo);
      }
    } catch (err) {
      console.error("fetchData error:", err);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchData();
  }, []);

  // Làm mới số liệu quản trị từ Cloudflare D1 định kỳ, không sử dụng số liệu mẫu.
  useEffect(() => {
    if (!window.Telegram?.WebApp?.initData) return undefined;
    let disposed = false;
    const refreshStats = async () => {
      try {
        const response = await telegramFetch("/api/admin/stats");
        if (!response.ok) return;
        const data = await response.json();
        if (!disposed) setAdminStats(data);
      } catch (error) {
        console.warn("Không thể cập nhật thống kê D1:", error.message);
      }
    };
    const timer = window.setInterval(refreshStats, 15000);
    return () => { disposed = true; window.clearInterval(timer); };
  }, []);

  // Apply admin-selected colors to the Mini App without changing Telegram's native button palette.
  useEffect(() => {
    const theme = botSettings?.ui_theme || {};
    const root = document.documentElement;
    const values = {
      "--hendy-primary": theme.primary || "#2563eb",
      "--hendy-accent": theme.accent || "#22d3ee",
      "--hendy-background": theme.background || "#0a0e17",
      "--hendy-panel": theme.panel || "#111827"
    };
    for (const [key, value] of Object.entries(values)) root.style.setProperty(key, value);
  }, [botSettings?.ui_theme]);

  // Telegram initData authenticates the WebSocket handshake; the socket only pushes configuration events.
  useEffect(() => {
    const initData = window.Telegram?.WebApp?.initData || "";
    if (!initData || typeof WebSocket === "undefined") {
      setRealtimeStatus("disconnected");
      return undefined;
    }

    let disposed = false;
    let socket = null;
    let reconnectTimer = null;
    let pingTimer = null;
    let attempts = 0;

    const connect = () => {
      if (disposed) return;
      setRealtimeStatus(attempts === 0 ? "connecting" : "reconnecting");
      try {
        socket = new WebSocket(getRealtimeUrl());
      } catch {
        setRealtimeStatus("disconnected");
        reconnectTimer = window.setTimeout(connect, Math.min(30000, 1000 * (2 ** attempts++)));
        return;
      }

      socket.onopen = () => {
        attempts = 0;
        socket.send(JSON.stringify({ type: "auth", initData, botKey: getTelegramBotKey() }));
        pingTimer = window.setInterval(() => {
          if (socket?.readyState === WebSocket.OPEN) socket.send(JSON.stringify({ type: "ping" }));
        }, 25000);
      };

      socket.onmessage = (event) => {
        let frame;
        try { frame = JSON.parse(event.data); } catch { return; }
        const payload = frame.payload || {};
        if (frame.type === "auth.ok") {
          setRealtimeSocket(socket);
          setRealtimeStatus("connected");
        } else if (frame.type === "public_config" || frame.type === "public_config.updated") {
          setBotSettings((current) => ({ ...(current || {}), ...payload }));
          setRealtimeStatus("connected");
        } else if (frame.type === "config.registry") {
          setConfigRegistry(payload);
        } else if (frame.type === "config.registry.error") {
          setNotice({ message: payload.message || "Không quét được vị trí cấu hình.", type: "error" });
        } else if (frame.type === "admin.settings.updated") {
          setNotice({ message: "Cấu hình bot vừa được cập nhật từ một phiên quản trị khác.", type: "success" });
        } else if (frame.type === "runtime_config.updated") {
          setNotice({ message: "Đã gửi yêu cầu triển khai cấu hình runtime.", type: "success" });
        }
      };

      socket.onerror = () => socket?.close();
      socket.onclose = () => {
        if (!disposed) setRealtimeSocket((current) => current === socket ? null : current);
        if (pingTimer) window.clearInterval(pingTimer);
        pingTimer = null;
        if (disposed) return;
        setRealtimeStatus("disconnected");
        const wait = Math.min(30000, 1000 * (2 ** Math.min(attempts++, 5)));
        reconnectTimer = window.setTimeout(connect, wait);
      };
    };

    connect();
    return () => {
      disposed = true;
      if (reconnectTimer) window.clearTimeout(reconnectTimer);
      if (pingTimer) window.clearInterval(pingTimer);
      socket?.close(1000, "Mini App closed");
    };
  }, []);

  // Filtered Catalog
  const filteredCatalog = useMemo(() => {
    return catalog.filter((item) => {
      const matchCat =
        activeCategory === "all" ||
        item.category === activeCategory ||
        item.module === activeCategory;
      const matchSearch =
        !searchQuery ||
        item.title?.toLowerCase().includes(searchQuery.toLowerCase()) ||
        item.tagline?.toLowerCase().includes(searchQuery.toLowerCase()) ||
        item.description?.toLowerCase().includes(searchQuery.toLowerCase());
      return matchCat && matchSearch;
    });
  }, [catalog, activeCategory, searchQuery]);

  // Featured hero item
  const featuredItem = useMemo(() => {
    return catalog.find((i) => i.featured) || catalog[0] || null;
  }, [catalog]);

  // Handle Quick Deposit
  const handleCreateDeposit = async () => {
    try {
      const res = await telegramFetch("/api/deposits", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ amountVnd: depositAmount })
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || "Không thể tạo yêu cầu nạp tiền.");
      setDepositResult(data);
      showToast("Đã tạo mã QR nạp tiền thành công! Vui lòng chuyển khoản đúng nội dung.");
      fetchData();
    } catch (err) {
      showToast(err.message, "error");
    }
  };

  // Handle Order Checkout from Wallet
  const handleBuyProduct = async (product) => {
    if (!product) return;
    try {
      const res = await telegramFetch("/api/orders", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          title: `Đặt mua: ${product.title}`,
          productId: product.id,
          module: product.category || "tools",
          quantity: 1,
          details: { version: product.version }
        })
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || "Không thể tạo đơn hàng.");

      // Direct pay if balance sufficient
      const orderId = data.order.id;
      const payRes = await telegramFetch(`/api/orders/${orderId}/pay`, { method: "POST" });
      const payData = await payRes.json();
      if (payRes.ok && payData.success) {
        showToast(payData.alreadyPaid
          ? `Đơn hàng ${orderId} đã được thanh toán trước đó.`
          : `Thanh toán thành công cho đơn hàng ${orderId}.`);
      } else {
        showToast(`Đã tạo đơn ${orderId}. Số dư ví chưa đủ để thanh toán ngay.`, "warning");
      }
      setSelectedProduct(null);
      fetchData();
    } catch (err) {
      showToast(err.message, "error");
    }
  };

  // Handle Redeem Giftcode
  const handleRedeemGiftcode = async (e) => {
    e.preventDefault();
    if (!redeemCodeInput.trim()) return;
    try {
      const res = await telegramFetch("/api/giftcodes/redeem", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ code: redeemCodeInput.trim() })
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || "Mã Giftcode không hợp lệ hoặc đã hết hạn.");
      showToast(`🎁 Đổi mã thành công: ${formatVnd(data.rewardVoucher ?? data.amount)} điểm voucher.`);
      setRedeemCodeInput("");
      fetchData();
    } catch (err) {
      showToast(err.message, "error");
    }
  };

  // Handle Packaging & Deploy Product to Store
  const handlePackageAndDeploy = async (e) => {
    e.preventDefault();
    try {
      const payload = {
        title: packageForm.title,
        category: packageForm.category,
        price: Number(packageForm.price),
        version: packageForm.version,
        packageId: packageForm.packageId || `com.hendy.${Date.now()}`,
        tagline: packageForm.tagline,
        r2Bucket: packageForm.r2Bucket,
        r2ObjectKey: packageForm.r2ObjectKey || `packages/${packageForm.version}/${packageForm.title.toLowerCase().replace(/\s+/g, "-")}.zip`,
        pagesDeployUrl: packageForm.pagesDeployUrl || "https://hendy-web-user.pages.dev",
        description: packageForm.description,
        tags: packageForm.tags.split(",").map((s) => s.trim()).filter(Boolean)
      };

      const res = await telegramFetch("/api/admin/catalog/package-deploy", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload)
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || "Không thể phát hành ứng dụng.");
      showToast(`🚀 Đã đóng gói và phát hành "${payload.title}" thành công lên App Store & Cloudflare R2!`);
      setPackageForm({
        title: "",
        category: "tools",
        price: 150000,
        version: "1.0.0",
        packageId: "",
        tagline: "",
        r2Bucket: "hendy-bot-assets",
        r2ObjectKey: "",
        pagesDeployUrl: "",
        description: "",
        tags: "cloudflare,r2,production"
      });
      fetchData();
      setActiveTab("store");
    } catch (err) {
      showToast(err.message, "error");
    }
  };

  // Handle Admin Deposit Review
  const handleReviewDeposit = async (id, decision) => {
    try {
      const res = await telegramFetch(`/api/deposits/${id}/review`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ decision })
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || "Không thể cập nhật nạp tiền.");
      showToast(`Đã ${decision === "approved" ? "phê duyệt" : "từ chối"} yêu cầu nạp tiền #${id}`);
      fetchData();
    } catch (err) {
      showToast(err.message, "error");
    }
  };

  // Handle Admin Order Status
  const handleUpdateOrderStatus = async (orderId, status) => {
    try {
      const res = await telegramFetch(`/api/orders/${orderId}/status`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ status })
      });
      if (!res.ok) throw new Error("Không thể cập nhật trạng thái đơn.");
      showToast(`Đã cập nhật đơn #${orderId} -> ${status}`);
      fetchData();
    } catch (err) {
      showToast(err.message, "error");
    }
  };

  // Handle Maintenance Mode Toggle & Settings
  const handleToggleMaintenance = async (enabled, customMsg) => {
    try {
      const res = await telegramFetch("/api/admin/maintenance", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ enabled, message: customMsg })
      });
      const data = await res.json();
      if (data.success) {
        setBotSettings(data.settings);
        showToast(
          data.maintenance_mode
            ? "Đã kích hoạt Chế Độ Bảo Trì!"
            : "Đã tắt Chế Độ Bảo Trì! Hệ thống bot đã hoạt động bình thường."
        );
        fetchData();
      }
    } catch (err) {
      showToast(err.message, "error");
    }
  };

  const handleUpdateMaintenanceMessage = async (customMsg) => {
    try {
      const res = await telegramFetch("/api/admin/maintenance", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ enabled: botSettings?.maintenance_mode, message: customMsg })
      });
      const data = await res.json();
      if (data.success) {
        setBotSettings(data.settings);
        showToast("Đã cập nhật thông điệp bảo trì!");
      }
    } catch (err) {
      showToast(err.message, "error");
    }
  };

  const handleSaveBotSettings = async (formData) => {
    const res = await telegramFetch("/api/admin/settings", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(formData)
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || "Không thể lưu cài đặt");
    setBotSettings(data.settings);
  };

  // Handle Support Ticket Reply
  const handleReplyTicket = async (ticketId) => {
    if (!ticketReplyMessage.trim()) return;
    try {
      const res = await telegramFetch(`/api/support/tickets/${ticketId}/reply`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ message: ticketReplyMessage.trim(), sender: "support" })
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || "Không thể gửi tin nhắn.");
      showToast("Đã gửi phản hồi thành công!");
      setTicketReplyMessage("");
      fetchData();
      if (selectedTicket?.id === ticketId) {
        setSelectedTicket(data.ticket);
      }
    } catch (err) {
      showToast(err.message, "error");
    }
  };

  // Handle Create Support Ticket
  const handleCreateTicket = async (e) => {
    e.preventDefault();
    if (!newTicketSubject.trim() || !newTicketMessage.trim()) return;
    try {
      const res = await telegramFetch("/api/support/tickets", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          subject: newTicketSubject,
          message: newTicketMessage,
          category: newTicketCategory
        })
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || "Không thể tạo ticket.");
      showToast("Đã tạo yêu cầu hỗ trợ mới. Kỹ thuật viên sẽ phản hồi sớm!");
      setNewTicketSubject("");
      setNewTicketMessage("");
      fetchData();
    } catch (err) {
      showToast(err.message, "error");
    }
  };

  return (
    <div className="min-h-screen flex flex-col bg-[#0a0e17] text-[#e6edf3]">
      {/* Toast Notification */}
      {notice && (
        <div className="fixed top-4 right-4 z-50 flex items-center gap-3 px-5 py-3 rounded-xl bg-[#1e293b] border border-blue-500/40 text-white shadow-2xl animate-fade-in backdrop-blur-md">
          {notice.type === "error" ? (
            <AlertCircle className="w-5 h-5 text-red-400" />
          ) : (
            <CheckCircle2 className="w-5 h-5 text-emerald-400" />
          )}
          <span className="text-sm font-medium">{notice.message}</span>
        </div>
      )}

      {/* TOP HEADER / APP BAR */}
      <header className="sticky top-0 z-40 bg-[#0a0e17]/90 backdrop-blur-xl border-b border-white/10 px-4 lg:px-8 py-3">
        <div className="max-w-7xl mx-auto flex items-center justify-between gap-4">
          {/* Logo & Platform Info */}
          <div className="flex items-center gap-3">
            <div className="w-10 h-10 rounded-2xl bg-gradient-to-tr from-blue-600 via-indigo-600 to-cyan-400 p-[2px] shadow-lg shadow-blue-500/20">
              <div className="w-full h-full bg-[#0d121f] rounded-[14px] flex items-center justify-center">
                <ShoppingBag className="w-5 h-5 text-cyan-400" />
              </div>
            </div>
            <div>
              <div className="flex items-center gap-2">
                <h1 className="text-base font-bold tracking-tight text-white">Hendy Store</h1>
                <span className="text-[10px] font-semibold uppercase px-2 py-0.5 rounded-full bg-blue-500/20 text-blue-400 border border-blue-500/30">
                  Cloudflare Edition
                </span>
              </div>
              <p className="text-xs text-slate-400 hidden sm:block">Render Core • D1 + R2 + Pages</p>
            </div>
          </div>

          {/* Search Bar */}
          <div className="flex-1 max-w-md hidden md:block">
            <div className="relative">
              <Search className="w-4 h-4 absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400" />
              <input
                type="text"
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                placeholder="Tìm kiếm ứng dụng, công cụ, mã nguồn..."
                className="w-full pl-10 pr-4 py-2 rounded-xl bg-white/5 border border-white/10 text-sm focus:outline-none focus:border-blue-500/60 focus:bg-white/10 transition-all placeholder:text-slate-500"
              />
              {searchQuery && (
                <button
                  onClick={() => setSearchQuery("")}
                  className="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-slate-400 hover:text-white"
                >
                  ✕
                </button>
              )}
            </div>
          </div>

          {/* User Quick Wallet & Actions */}
          <div className="flex items-center gap-2 sm:gap-3">
            <button
              onClick={() => setShowDepositModal(true)}
              className="flex items-center gap-2 px-3 py-1.5 rounded-xl bg-gradient-to-r from-emerald-500/20 to-teal-500/10 border border-emerald-500/30 text-emerald-300 hover:border-emerald-500/50 transition-all text-xs sm:text-sm font-semibold"
            >
              <Wallet className="w-4 h-4 text-emerald-400" />
              <span>{formatVnd(wallet?.balance ?? userProfile?.balance ?? 0)} đ</span>
              <Plus className="w-3.5 h-3.5 ml-0.5 text-emerald-400" />
            </button>

            <button
              onClick={() => setActiveTab("admin")}
              className={`p-2 rounded-xl border transition-all ${
                activeTab === "admin"
                  ? "bg-blue-600/30 border-blue-500 text-blue-300"
                  : "bg-white/5 border-white/10 text-slate-400 hover:text-white hover:bg-white/10"
              }`}
              title="Khu Vực Quản Trị Viên (Admin)"
            >
              <Settings className="w-4 h-4" />
            </button>
          </div>
        </div>
      </header>

      {/* NAVIGATION TABS BAR */}
      <div className="bg-[#0f1422] border-b border-white/5 sticky top-[57px] z-30">
        <div className="max-w-7xl mx-auto px-4 lg:px-8 flex items-center justify-between overflow-x-auto scrollbar-none">
          <nav className="flex items-center gap-1 sm:gap-2 py-2">
            <button
              onClick={() => setActiveTab("store")}
              className={`px-4 py-2 rounded-xl text-xs sm:text-sm font-semibold flex items-center gap-2 transition-all whitespace-nowrap ${
                activeTab === "store"
                  ? "bg-blue-600 text-white shadow-lg shadow-blue-500/25"
                  : "text-slate-400 hover:text-slate-200 hover:bg-white/5"
              }`}
            >
              <ShoppingBag className="w-4 h-4" />
              <span>Cửa Hàng (Store UI)</span>
            </button>

            <button
              onClick={() => setActiveTab("member")}
              className={`px-4 py-2 rounded-xl text-xs sm:text-sm font-semibold flex items-center gap-2 transition-all whitespace-nowrap ${
                activeTab === "member"
                  ? "bg-blue-600 text-white shadow-lg shadow-blue-500/25"
                  : "text-slate-400 hover:text-slate-200 hover:bg-white/5"
              }`}
            >
              <Users className="w-4 h-4" />
              <span>Hội Viên & 14 Nút Bot</span>
            </button>

            <button
              onClick={() => setActiveTab("admin")}
              className={`px-4 py-2 rounded-xl text-xs sm:text-sm font-semibold flex items-center gap-2 transition-all whitespace-nowrap ${
                activeTab === "admin"
                  ? "bg-blue-600 text-white shadow-lg shadow-blue-500/25"
                  : "text-slate-400 hover:text-slate-200 hover:bg-white/5"
              }`}
            >
              <Layers className="w-4 h-4" />
              <span>Admin Panel & Đóng Gói R2</span>
            </button>

            <button
              onClick={() => setActiveTab("support")}
              className={`px-4 py-2 rounded-xl text-xs sm:text-sm font-semibold flex items-center gap-2 transition-all whitespace-nowrap ${
                activeTab === "support"
                  ? "bg-blue-600 text-white shadow-lg shadow-blue-500/25"
                  : "text-slate-400 hover:text-slate-200 hover:bg-white/5"
              }`}
            >
              <LifeBuoy className="w-4 h-4" />
              <span>Hỗ Trợ (Tickets Hub)</span>
            </button>

          </nav>

          <button
            onClick={fetchData}
            disabled={loading}
            className="p-2 text-slate-400 hover:text-white rounded-lg hover:bg-white/5 transition-all flex items-center gap-1.5 text-xs"
            title="Đồng bộ dữ liệu Cloudflare"
          >
            <RefreshCw className={`w-3.5 h-3.5 ${loading ? "animate-spin text-blue-400" : ""}`} />
            <span className="hidden sm:inline">Làm Mới</span>
          </button>
        </div>
      </div>

      {/* MAIN CONTENT CONTAINER */}
      <main className="flex-1 max-w-7xl w-full mx-auto p-4 lg:p-8 space-y-6">
        {/* Global Maintenance Banner when active */}
        {activeTab !== "admin" && botSettings?.maintenance_mode && (
          <MaintenanceBanner
            isMaintenance={true}
            message={botSettings.maintenance_message}
            isAdmin={false}
          />
        )}

        {/* ========================================================= */}
        {/* TAB 1: STORE UI (Google Play / App Store Inspired) */}
        {/* ========================================================= */}
        {activeTab === "store" && (
          <div className="space-y-8 animate-fade-in">
            {/* Category Pills Strip */}
            <div className="flex items-center gap-2 overflow-x-auto scrollbar-none pb-1">
              {DEFAULT_CATEGORIES.map((cat) => (
                <button
                  key={cat.id}
                  onClick={() => setActiveCategory(cat.id)}
                  className={`px-4 py-2 rounded-full text-xs font-semibold transition-all whitespace-nowrap ${
                    activeCategory === cat.id
                      ? "bg-white text-slate-900 shadow-md"
                      : "bg-white/5 text-slate-300 hover:bg-white/10 hover:text-white border border-white/5"
                  }`}
                >
                  {cat.name}
                </button>
              ))}
            </div>

            {/* Featured Hero Banner */}
            {featuredItem && (
              <div className="glass-panel overflow-hidden relative border-blue-500/30 bg-gradient-to-br from-blue-900/40 via-indigo-900/20 to-purple-900/30 p-6 sm:p-8">
                <div className="absolute top-0 right-0 -mt-12 -mr-12 w-96 h-96 bg-blue-500/10 rounded-full blur-3xl pointer-events-none" />
                <div className="relative z-10 flex flex-col md:flex-row items-start md:items-center justify-between gap-6">
                  <div className="max-w-2xl space-y-3">
                    <div className="flex items-center gap-2">
                      <span className="px-2.5 py-1 rounded-md text-[11px] font-bold uppercase tracking-wider bg-blue-500/20 text-blue-300 border border-blue-500/30">
                        Ứng Dụng Tiêu Biểu
                      </span>
                      <span className="text-xs text-slate-400 font-mono">
                        {featuredItem.developer || "Hendy Cloud"}
                      </span>
                    </div>
                    <h2 className="text-2xl sm:text-3xl font-extrabold text-white tracking-tight">
                      {featuredItem.title}
                    </h2>
                    <p className="text-slate-300 text-sm sm:text-base leading-relaxed">
                      {featuredItem.description || featuredItem.tagline}
                    </p>
                    <div className="flex flex-wrap items-center gap-4 pt-1">
                      <div className="star-rating">
                        <Star className="w-4 h-4 fill-amber-400" />
                        <span>{featuredItem.rating ?? "Chưa có đánh giá"}</span>
                        <span className="text-slate-400 text-xs font-normal">({featuredItem.reviewsCount ?? "Chưa có đánh giá"} đánh giá)</span>
                      </div>
                      <span className="text-slate-500">•</span>
                      <span className="text-xs text-slate-300 font-medium">{featuredItem.downloads ?? "Chưa có dữ liệu"} lượt cài</span>
                      <span className="text-slate-500">•</span>
                      <span className="text-xs font-semibold px-2 py-0.5 rounded-md bg-white/10 text-cyan-300">
                        {featuredItem.r2Bucket ? "Lưu trữ Cloudflare R2" : "Cloudflare Pages"}
                      </span>
                    </div>
                    <div className="flex items-center gap-3 pt-2">
                      <button
                        onClick={() => setSelectedProduct(featuredItem)}
                        className="btn-primary"
                      >
                        <Download className="w-4 h-4" />
                        <span>
                          {formatPriceBadge(featuredItem)}
                        </span>
                      </button>
                      <button
                        onClick={() => setSelectedProduct(featuredItem)}
                        className="btn-secondary"
                      >
                        <span>Chi Tiết Gói</span>
                        <ChevronRight className="w-4 h-4" />
                      </button>
                    </div>
                  </div>

                  {/* Hero Right Visual Preview */}
                  <div className="w-full md:w-auto flex flex-col sm:flex-row items-center gap-4">
                    <div className="w-32 h-32 sm:w-40 sm:h-40 rounded-3xl bg-gradient-to-tr from-blue-600 via-indigo-600 to-cyan-400 p-[3px] shadow-2xl shadow-indigo-500/30">
                      <div className="w-full h-full bg-[#111827] rounded-[22px] flex flex-col items-center justify-center p-4 text-center">
                        <ShoppingBag className="w-12 h-12 text-cyan-400 mb-2" />
                        <span className="text-xs font-bold text-white uppercase tracking-wider">{featuredItem.version || "v2.0"}</span>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            )}

            {/* Product Catalog Grid */}
            <div className="space-y-4">
              <div className="flex items-center justify-between">
                <div>
                  <h3 className="text-lg font-bold text-white">Khám Phá Ứng Dụng & Công Cụ</h3>
                  <p className="text-xs text-slate-400">Được đồng bộ trực tiếp với cơ sở dữ liệu Cloudflare D1</p>
                </div>
                <span className="text-xs text-slate-400 font-mono">{filteredCatalog.length} ứng dụng</span>
              </div>

              {filteredCatalog.length === 0 ? (
                <div className="glass-panel p-12 text-center space-y-3">
                  <Package className="w-12 h-12 text-slate-500 mx-auto" />
                  <p className="text-sm text-slate-400">Không tìm thấy ứng dụng phù hợp với tìm kiếm của bạn.</p>
                </div>
              ) : (
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                  {filteredCatalog.map((item) => (
                    <div
                      key={item.id}
                      onClick={() => setSelectedProduct(item)}
                      className="glass-panel glass-panel-hover p-5 cursor-pointer flex flex-col justify-between group"
                    >
                      {/* Top Item Info */}
                      <div className="flex items-start gap-4">
                        <div className="w-14 h-14 rounded-2xl bg-gradient-to-tr from-blue-600 to-indigo-600 p-[2px] shrink-0 shadow-md">
                          <div className="w-full h-full bg-[#111827] rounded-[14px] flex items-center justify-center text-2xl">
                            {item.icon || "📦"}
                          </div>
                        </div>

                        <div className="flex-1 min-w-0">
                          <h4 className="font-bold text-white text-base group-hover:text-blue-400 transition-colors truncate">
                            {item.title}
                          </h4>
                          <p className="text-xs text-slate-400 mt-0.5">{item.developer || "Hendy Studio"}</p>
                          <div className="flex items-center gap-2 mt-1">
                            <span className="star-rating text-xs">
                              <Star className="w-3.5 h-3.5 fill-amber-400 text-amber-400" />
                              <span>{item.rating || "4.8"}</span>
                            </span>
                            <span className="text-slate-500 text-xs">•</span>
                            <span className="text-[11px] text-slate-400">{item.downloads || "1.2K"} tải</span>
                          </div>
                        </div>
                      </div>

                      {/* Tagline / Snippet */}
                      <p className="text-xs text-slate-300 line-clamp-2 mt-3 mb-4 leading-relaxed">
                        {item.tagline || item.description}
                      </p>

                      {/* Bottom Price & Action */}
                      <div className="flex items-center justify-between pt-3 border-t border-white/5">
                        <span className="text-xs font-semibold px-2 py-0.5 rounded-md bg-blue-500/10 text-blue-400 border border-blue-500/20">
                          {formatPriceBadge(item)}
                        </span>
                        <button
                          onClick={(e) => {
                            e.stopPropagation();
                            setSelectedProduct(item);
                          }}
                          className="px-3.5 py-1.5 rounded-xl bg-white/10 hover:bg-blue-600 hover:text-white text-xs font-semibold text-slate-200 transition-all flex items-center gap-1"
                        >
                          <span>Xem Gói</span>
                          <ChevronRight className="w-3.5 h-3.5" />
                        </button>
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </div>
          </div>
        )}

        {/* ========================================================= */}
        {/* TAB 2: TELEGRAM MEMBER HUB (14 MODULES & WALLET LEDGER) */}
        {/* ========================================================= */}
        {activeTab === "member" && (
          <div className="space-y-6 animate-fade-in">
            {/* User Membership Identity Card */}
            <div className="glass-panel p-6 bg-gradient-to-r from-blue-950/40 via-indigo-950/30 to-purple-950/40 border border-blue-500/20">
              <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
                <div className="flex items-center gap-4">
                  <div className="w-16 h-16 rounded-2xl bg-gradient-to-tr from-blue-600 to-indigo-600 flex items-center justify-center shadow-lg shadow-blue-500/30">
                    <span className="text-2xl font-black text-white">
                      {(userProfile?.name || "H").slice(0, 1).toUpperCase()}
                    </span>
                  </div>
                  <div>
                    <div className="flex items-center gap-2">
                      <h3 className="text-xl font-bold text-white">{userProfile?.name || "Hội viên Hendy"}</h3>
                      <span className="px-2 py-0.5 rounded-full text-xs font-bold uppercase bg-amber-500/20 text-amber-300 border border-amber-500/30">
                        {vipProfile?.currentTier?.name || "Thành viên"}
                      </span>
                    </div>
                    <p className="text-xs text-slate-400 mt-1">
                      Telegram ID: <span className="font-mono text-slate-300">{userProfile?.id || "Chưa xác định"}</span> • Điểm tích lũy: <span className="text-cyan-400 font-bold">{vipProfile?.points ?? userProfile?.points ?? 0} pts</span>
                    </p>
                  </div>
                </div>

                <div className="flex items-center gap-3">
                  <button
                    onClick={() => setShowDepositModal(true)}
                    className="btn-primary"
                  >
                    <Plus className="w-4 h-4" />
                    <span>Nạp Tiền VietQR</span>
                  </button>
                </div>
              </div>

              {/* Wallet Balances Strip */}
              <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 mt-6 pt-6 border-t border-white/10">
                <div className="p-3 rounded-xl bg-white/5">
                  <span className="text-[11px] text-slate-400 block">Số Dư Chính</span>
                  <span className="text-base sm:text-lg font-bold text-emerald-400">
                    {formatVnd(wallet?.balance ?? userProfile?.balance ?? 0)} đ
                  </span>
                </div>
                <div className="p-3 rounded-xl bg-white/5">
                  <span className="text-[11px] text-slate-400 block">Voucher Giảm Giá</span>
                  <span className="text-base sm:text-lg font-bold text-cyan-400">
                    {formatVnd(wallet?.voucher ?? 0)} điểm
                  </span>
                </div>
                <div className="p-3 rounded-xl bg-white/5">
                  <span className="text-[11px] text-slate-400 block">Tổng Đơn Hàng</span>
                  <span className="text-base sm:text-lg font-bold text-white">{orders.length} đơn</span>
                </div>
                <div className="p-3 rounded-xl bg-white/5">
                  <span className="text-[11px] text-slate-400 block">Hạng Tiếp Theo</span>
                  <span className="text-base sm:text-lg font-bold text-indigo-300">
                    {vipProfile?.nextTier?.name || "VIP Huyền Thoại"}
                  </span>
                </div>
              </div>
            </div>

            {/* 14 CORE TELEGRAM MODULES GRID */}
            <div>
              <h3 className="text-lg font-bold text-white mb-3">Trung Tâm Tiện Ích (14 Nút Bấm Telegram)</h3>
              <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
                {/* 1. Store */}
                <button
                  onClick={() => setActiveTab("store")}
                  className="glass-panel glass-panel-hover p-4 text-left flex flex-col justify-between"
                >
                  <div className="w-10 h-10 rounded-xl bg-blue-500/20 text-blue-400 flex items-center justify-center mb-3">
                    <ShoppingBag className="w-5 h-5" />
                  </div>
                  <div>
                    <h4 className="font-bold text-white text-sm">1. Cửa Hàng</h4>
                    <p className="text-[11px] text-slate-400 mt-0.5">Kho ứng dụng, công cụ R2</p>
                  </div>
                </button>

                {/* 2. Wallet */}
                <button
                  onClick={() => setShowDepositModal(true)}
                  className="glass-panel glass-panel-hover p-4 text-left flex flex-col justify-between"
                >
                  <div className="w-10 h-10 rounded-xl bg-emerald-500/20 text-emerald-400 flex items-center justify-center mb-3">
                    <Wallet className="w-5 h-5" />
                  </div>
                  <div>
                    <h4 className="font-bold text-white text-sm">2. Ví D1 & Nạp</h4>
                    <p className="text-[11px] text-slate-400 mt-0.5">Quét mã QR VietQR tự động</p>
                  </div>
                </button>

                {/* 3. Orders */}
                <button
                  onClick={() => setActiveTab("member")}
                  className="glass-panel glass-panel-hover p-4 text-left flex flex-col justify-between"
                >
                  <div className="w-10 h-10 rounded-xl bg-purple-500/20 text-purple-400 flex items-center justify-center mb-3">
                    <Package className="w-5 h-5" />
                  </div>
                  <div>
                    <h4 className="font-bold text-white text-sm">3. Đơn Hàng</h4>
                    <p className="text-[11px] text-slate-400 mt-0.5">Lịch sử và key phần mềm</p>
                  </div>
                </button>

                {/* 4. VIP Privileges */}
                <button
                  onClick={() => showToast(`Hạng hiện tại: ${vipProfile?.currentTier?.name || "VIP"}. Hoàn tiền 5% mọi đơn hàng.`)}
                  className="glass-panel glass-panel-hover p-4 text-left flex flex-col justify-between"
                >
                  <div className="w-10 h-10 rounded-xl bg-amber-500/20 text-amber-400 flex items-center justify-center mb-3">
                    <Award className="w-5 h-5" />
                  </div>
                  <div>
                    <h4 className="font-bold text-white text-sm">4. Đặc Quyền VIP</h4>
                    <p className="text-[11px] text-slate-400 mt-0.5">Quyền lợi và chiết khấu riêng</p>
                  </div>
                </button>

                {/* 5. Redeem Giftcode */}
                <button
                  onClick={() => {
                    const el = document.getElementById("giftcode-redeem-box");
                    el?.scrollIntoView({ behavior: "smooth" });
                  }}
                  className="glass-panel glass-panel-hover p-4 text-left flex flex-col justify-between"
                >
                  <div className="w-10 h-10 rounded-xl bg-rose-500/20 text-rose-400 flex items-center justify-center mb-3">
                    <Gift className="w-5 h-5" />
                  </div>
                  <div>
                    <h4 className="font-bold text-white text-sm">5. Đổi Giftcode</h4>
                    <p className="text-[11px] text-slate-400 mt-0.5">Nhận điểm voucher theo mã hợp lệ</p>
                  </div>
                </button>

                {/* 6. Referral */}
                <button
                  onClick={async () => {
                    if (!referralProfile?.inviteUrl) {
                      showToast("Chưa cấu hình BOT_USERNAME hoặc mã giới thiệu chưa sẵn sàng.", "error");
                      return;
                    }
                    try {
                      if (!navigator.clipboard?.writeText) throw new Error("Trình duyệt không hỗ trợ sao chép liên kết.");
                      await navigator.clipboard.writeText(referralProfile.inviteUrl);
                      showToast("Đã sao chép liên kết giới thiệu.");
                    } catch (error) {
                      showToast(error.message, "error");
                    }
                  }}
                  className="glass-panel glass-panel-hover p-4 text-left flex flex-col justify-between"
                >
                  <div className="w-10 h-10 rounded-xl bg-cyan-500/20 text-cyan-400 flex items-center justify-center mb-3">
                    <Users className="w-5 h-5" />
                  </div>
                  <div>
                    <h4 className="font-bold text-white text-sm">6. Giới Thiệu Bạn</h4>
                    <p className="text-[11px] text-slate-400 mt-0.5">Nhận thưởng theo chính sách hoa hồng hiện hành</p>
                  </div>
                </button>

                {/* 7. Support */}
                <button
                  onClick={() => setActiveTab("support")}
                  className="glass-panel glass-panel-hover p-4 text-left flex flex-col justify-between"
                >
                  <div className="w-10 h-10 rounded-xl bg-orange-500/20 text-orange-400 flex items-center justify-center mb-3">
                    <LifeBuoy className="w-5 h-5" />
                  </div>
                  <div>
                    <h4 className="font-bold text-white text-sm">7. CSKH & Ticket</h4>
                    <p className="text-[11px] text-slate-400 mt-0.5">Phản hồi kỹ thuật trong 5p</p>
                  </div>
                </button>

                {/* 8. Community */}
                <button
                  onClick={() => showToast("Đang kết nối tới Telegram Channel: @hendy_community")}
                  className="glass-panel glass-panel-hover p-4 text-left flex flex-col justify-between"
                >
                  <div className="w-10 h-10 rounded-xl bg-indigo-500/20 text-indigo-400 flex items-center justify-center mb-3">
                    <MessageCircle className="w-5 h-5" />
                  </div>
                  <div>
                    <h4 className="font-bold text-white text-sm">8. Kênh Cộng Đồng</h4>
                    <p className="text-[11px] text-slate-400 mt-0.5">Nhóm thảo luận & hỗ trợ</p>
                  </div>
                </button>

                {/* 9. Announcements */}
                <button
                  onClick={() => showToast("Bản cập nhật v2.4.1: Đồng bộ Cloudflare D1 và tăng tốc tải R2!")}
                  className="glass-panel glass-panel-hover p-4 text-left flex flex-col justify-between"
                >
                  <div className="w-10 h-10 rounded-xl bg-yellow-500/20 text-yellow-400 flex items-center justify-center mb-3">
                    <Megaphone className="w-5 h-5" />
                  </div>
                  <div>
                    <h4 className="font-bold text-white text-sm">9. Bảng Tin</h4>
                    <p className="text-[11px] text-slate-400 mt-0.5">Thông báo hệ thống mới nhất</p>
                  </div>
                </button>

                {/* 10. Security */}
                <button
                  onClick={() => showToast("Bảo mật: Mã hóa AES-256-GCM & Xác thực Telegram WebApp an toàn.")}
                  className="glass-panel glass-panel-hover p-4 text-left flex flex-col justify-between"
                >
                  <div className="w-10 h-10 rounded-xl bg-emerald-500/20 text-emerald-400 flex items-center justify-center mb-3">
                    <ShieldCheck className="w-5 h-5" />
                  </div>
                  <div>
                    <h4 className="font-bold text-white text-sm">10. Bảo Mật</h4>
                    <p className="text-[11px] text-slate-400 mt-0.5">Mã hóa AES-256, 2FA</p>
                  </div>
                </button>

                {/* 11. Bot Settings */}
                <button
                  onClick={() => setActiveTab("admin")}
                  className="glass-panel glass-panel-hover p-4 text-left flex flex-col justify-between"
                >
                  <div className="w-10 h-10 rounded-xl bg-pink-500/20 text-pink-400 flex items-center justify-center mb-3">
                    <Bot className="w-5 h-5" />
                  </div>
                  <div>
                    <h4 className="font-bold text-white text-sm">11. Cài Đặt Bot</h4>
                    <p className="text-[11px] text-slate-400 mt-0.5">Webhook & Long Polling</p>
                  </div>
                </button>

                {/* 12. Cloudflare Sync */}
                <button
                  onClick={() => setActiveTab("admin")}
                  className="glass-panel glass-panel-hover p-4 text-left flex flex-col justify-between"
                >
                  <div className="w-10 h-10 rounded-xl bg-sky-500/20 text-sky-400 flex items-center justify-center mb-3">
                    <Cloud className="w-5 h-5" />
                  </div>
                  <div>
                    <h4 className="font-bold text-white text-sm">12. Cloudflare D1/R2</h4>
                    <p className="text-[11px] text-slate-400 mt-0.5">Đồng bộ cơ sở dữ liệu</p>
                  </div>
                </button>

                {/* 13. Quick Links */}
                <button
                  onClick={() => showToast("Tài liệu API và Hướng dẫn sử dụng đã được tích hợp trong trang docs.")}
                  className="glass-panel glass-panel-hover p-4 text-left flex flex-col justify-between"
                >
                  <div className="w-10 h-10 rounded-xl bg-teal-500/20 text-teal-400 flex items-center justify-center mb-3">
                    <LinkIcon className="w-5 h-5" />
                  </div>
                  <div>
                    <h4 className="font-bold text-white text-sm">13. Liên Kết Nhanh</h4>
                    <p className="text-[11px] text-slate-400 mt-0.5">Docs, Portal & Mini App</p>
                  </div>
                </button>

                {/* 14. Admin Portal */}
                <button
                  onClick={() => setActiveTab("admin")}
                  className="glass-panel glass-panel-hover p-4 text-left flex flex-col justify-between border-blue-500/40"
                >
                  <div className="w-10 h-10 rounded-xl bg-blue-600/30 text-blue-400 flex items-center justify-center mb-3">
                    <Settings className="w-5 h-5" />
                  </div>
                  <div>
                    <h4 className="font-bold text-white text-sm">14. Quản Trị Viên</h4>
                    <p className="text-[11px] text-slate-400 mt-0.5">Đơn hàng, duyệt nạp, đóng gói</p>
                  </div>
                </button>
              </div>
            </div>

            {/* Redeem Giftcode Box & Order History */}
            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6 pt-2">
              {/* Giftcode Redeem */}
              <div id="giftcode-redeem-box" className="glass-panel p-6 space-y-4">
                <div className="flex items-center gap-2">
                  <Gift className="w-5 h-5 text-rose-400" />
                  <h4 className="font-bold text-white text-base">Đổi Mã Quà Tặng (Giftcode)</h4>
                </div>
                <p className="text-xs text-slate-400">
                  Nhập mã Giftcode do ban quản trị phát hành để nhận tiền ngay vào số dư ví Cloudflare D1.
                </p>
                <form onSubmit={handleRedeemGiftcode} className="space-y-3">
                  <input
                    type="text"
                    value={redeemCodeInput}
                    onChange={(e) => setRedeemCodeInput(e.target.value.toUpperCase())}
                    placeholder="VD: HENDY-VIP-100K"
                    className="w-full px-4 py-2.5 rounded-xl bg-white/5 border border-white/10 text-sm font-mono text-white placeholder:text-slate-500 focus:outline-none focus:border-rose-500"
                  />
                  <button type="submit" className="w-full btn-primary bg-rose-600 hover:bg-rose-500">
                    <Gift className="w-4 h-4" />
                    <span>Kích Hoạt Giftcode</span>
                  </button>
                </form>
              </div>

              {/* User Orders History */}
              <div className="lg:col-span-2 glass-panel p-6 space-y-4">
                <div className="flex items-center justify-between">
                  <div className="flex items-center gap-2">
                    <History className="w-5 h-5 text-blue-400" />
                    <h4 className="font-bold text-white text-base">Lịch Sử Đơn Hàng Của Bạn</h4>
                  </div>
                  <span className="text-xs text-slate-400 font-mono">{orders.length} giao dịch</span>
                </div>

                {orders.length === 0 ? (
                  <p className="text-xs text-slate-500 py-8 text-center">Bạn chưa có đơn hàng nào.</p>
                ) : (
                  <div className="space-y-2.5 max-h-72 overflow-y-auto pr-1">
                    {orders.map((ord) => (
                      <div
                        key={ord.id}
                        className="p-3.5 rounded-xl bg-white/5 border border-white/5 flex items-center justify-between gap-3"
                      >
                        <div>
                          <div className="flex items-center gap-2">
                            <span className="font-semibold text-sm text-white">{ord.title}</span>
                            <span className="text-xs font-mono text-slate-400">#{ord.id}</span>
                          </div>
                          <span className="text-xs text-slate-400 block mt-0.5">
                            {new Date(ord.created_at || Date.now()).toLocaleDateString("vi-VN")}
                          </span>
                        </div>

                        <div className="text-right">
                          <span className="font-bold text-sm text-emerald-400 block">
                            {(ord.amount ?? ord.quoted_amount ?? 0) === 0 ? "Miễn Phí" : `${formatVnd(ord.amount ?? ord.quoted_amount)} đ`}
                          </span>
                          <span
                            className={`text-[10px] font-bold uppercase px-2 py-0.5 rounded-md ${
                              ord.status === "completed"
                                ? "bg-emerald-500/20 text-emerald-300"
                                : ord.status === "processing"
                                ? "bg-blue-500/20 text-blue-300"
                                : ord.status === "confirmed"
                                ? "bg-amber-500/20 text-amber-300"
                                : "bg-slate-500/20 text-slate-300"
                            }`}
                          >
                            {ord.status}
                          </span>
                        </div>
                      </div>
                    ))}
                  </div>
                )}
              </div>
            </div>
          </div>
        )}

        {/* ========================================================= */}
        {/* TAB 3: ADMIN PANEL (Order Queue, Deposits, Packaging R2, Cloudflare D1) */}
        {/* ========================================================= */}
        {activeTab === "admin" && (
          <div className="space-y-6 animate-fade-in">
            {/* Interactive Maintenance Mode Control Banner */}
            <MaintenanceBanner
              isMaintenance={botSettings?.maintenance_mode}
              message={botSettings?.maintenance_message}
              onToggle={handleToggleMaintenance}
              onUpdateMessage={handleUpdateMaintenanceMessage}
              isAdmin={true}
            />

            {/* Real-time Cloudflare D1 Statistics & Interactive SQL Console */}
            <D1StatsAndConsole
              adminStats={adminStats}
              showToast={showToast}
            />

            <RuntimeConfigManager
              showToast={showToast}
              realtimeStatus={realtimeStatus}
              registry={configRegistry}
              onRescan={() => {
                if (realtimeSocket?.readyState === WebSocket.OPEN) {
                  realtimeSocket.send(JSON.stringify({ type: "scan_config" }));
                  return true;
                }
                return false;
              }}
            />

            {/* Telegram Webhook & Render Service Manager */}
            <TelegramWebhookManager
              webhookInfo={webhookInfo}
              onRefresh={fetchData}
              showToast={showToast}
            />

            {/* Admin Stats Strip — dữ liệu truy xuất trực tiếp từ Cloudflare D1 */}
            <div className="grid grid-cols-2 sm:grid-cols-4 gap-4">
              <div className="glass-panel p-4">
                <span className="text-xs text-slate-400">Doanh Thu Đã Thanh Toán</span>
                <span className="text-xl font-bold text-emerald-400 block mt-1">
                  {formatVnd(adminStats?.totalRevenue ?? 0)} đ
                </span>
              </div>
              <div className="glass-panel p-4">
                <span className="text-xs text-slate-400">Tổng Thành Viên</span>
                <span className="text-xl font-bold text-white block mt-1">
                  {formatVnd(adminStats?.totalUsers ?? 0)}
                </span>
              </div>
              <div className="glass-panel p-4">
                <span className="text-xs text-slate-400">Người Dùng Hoạt Động 24 Giờ</span>
                <span className="text-xl font-bold text-cyan-400 block mt-1">
                  {formatVnd(adminStats?.activeUsers24h ?? 0)}
                </span>
              </div>
              <div className="glass-panel p-4">
                <span className="text-xs text-slate-400">Yêu Cầu Nạp Đang Chờ</span>
                <span className="text-xl font-bold text-indigo-400 block mt-1">
                  {formatVnd(adminStats?.pending_deposits ?? 0)}
                </span>
              </div>
            </div>

            {/* Packaging & Deploying Studio (Feature requested by user) */}
            <div className="glass-panel p-6 border-blue-500/30">
              <div className="flex items-center justify-between mb-4">
                <div>
                  <h3 className="text-lg font-bold text-white flex items-center gap-2">
                    <Package className="w-5 h-5 text-blue-400" />
                    <span>Studio Đóng Gói Sản Phẩm & Deploy Cloudflare R2</span>
                  </h3>
                  <p className="text-xs text-slate-400">
                    Phát hành trực tiếp ứng dụng mới lên kho Hendy App Store và triển khai tới hạ tầng Pages / R2.
                  </p>
                </div>
                <span className="text-xs font-semibold px-2 py-0.5 rounded-full bg-blue-500/20 text-blue-400">
                  R2 + D1 Sync
                </span>
              </div>

              <form onSubmit={handlePackageAndDeploy} className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label className="text-xs font-semibold text-slate-300 block mb-1">Tên Ứng Dụng / Tool</label>
                  <input
                    type="text"
                    required
                    value={packageForm.title}
                    onChange={(e) => setPackageForm({ ...packageForm, title: e.target.value })}
                    placeholder="VD: Telegram Multi-Session Sender"
                    className="w-full px-3.5 py-2 rounded-xl bg-white/5 border border-white/10 text-sm text-white focus:outline-none focus:border-blue-500"
                  />
                </div>

                <div>
                  <label className="text-xs font-semibold text-slate-300 block mb-1">Danh Mục</label>
                  <select
                    value={packageForm.category}
                    onChange={(e) => setPackageForm({ ...packageForm, category: e.target.value })}
                    className="w-full px-3.5 py-2 rounded-xl bg-[#111827] border border-white/10 text-sm text-white focus:outline-none focus:border-blue-500"
                  >
                    <option value="tools">Công cụ & Tiện ích</option>
                    <option value="gaming">Dịch vụ Game</option>
                    <option value="source_code">Mã nguồn Dev</option>
                    <option value="proxy">Proxy & Mạng</option>
                    <option value="vip_accounts">Tài khoản VIP</option>
                  </select>
                </div>

                <div>
                  <label className="text-xs font-semibold text-slate-300 block mb-1">Giá Bán (VNĐ, 0 = Miễn Phí)</label>
                  <input
                    type="number"
                    min="0"
                    step="10000"
                    value={packageForm.price}
                    onChange={(e) => setPackageForm({ ...packageForm, price: e.target.value })}
                    className="w-full px-3.5 py-2 rounded-xl bg-white/5 border border-white/10 text-sm font-mono text-white focus:outline-none focus:border-blue-500"
                  />
                </div>

                <div>
                  <label className="text-xs font-semibold text-slate-300 block mb-1">Phiên Bản (SemVer)</label>
                  <input
                    type="text"
                    value={packageForm.version}
                    onChange={(e) => setPackageForm({ ...packageForm, version: e.target.value })}
                    placeholder="1.0.0"
                    className="w-full px-3.5 py-2 rounded-xl bg-white/5 border border-white/10 text-sm text-white focus:outline-none focus:border-blue-500"
                  />
                </div>

                <div className="md:col-span-2">
                  <label className="text-xs font-semibold text-slate-300 block mb-1">Khẩu Hiệu / Tagline</label>
                  <input
                    type="text"
                    value={packageForm.tagline}
                    onChange={(e) => setPackageForm({ ...packageForm, tagline: e.target.value })}
                    placeholder="Tóm tắt ngắn gọn tính năng của gói..."
                    className="w-full px-3.5 py-2 rounded-xl bg-white/5 border border-white/10 text-sm text-white focus:outline-none focus:border-blue-500"
                  />
                </div>

                <div className="md:col-span-2">
                  <label className="text-xs font-semibold text-slate-300 block mb-1">Mô Tả Chi Tiết Gói</label>
                  <textarea
                    rows={2}
                    value={packageForm.description}
                    onChange={(e) => setPackageForm({ ...packageForm, description: e.target.value })}
                    placeholder="Mô tả kỹ thuật, hướng dẫn cài đặt trên Render hoặc Cloudflare..."
                    className="w-full px-3.5 py-2 rounded-xl bg-white/5 border border-white/10 text-sm text-white focus:outline-none focus:border-blue-500"
                  />
                </div>

                <div className="md:col-span-2 flex justify-end pt-2">
                  <button type="submit" className="btn-primary">
                    <Cloud className="w-4 h-4" />
                    <span>Đóng Gói & Xuất Bản Lên App Store</span>
                  </button>
                </div>
              </form>
            </div>

            {/* Dual Column: Orders Queue & Deposit Approvals */}
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
              {/* Order Queue Management */}
              <div className="glass-panel p-6 space-y-4">
                <div className="flex items-center justify-between">
                  <div className="flex items-center gap-2">
                    <Layers className="w-5 h-5 text-indigo-400" />
                    <h4 className="font-bold text-white text-base">Hàng Đợi Đơn Hàng (Order Queue)</h4>
                  </div>
                  <span className="text-xs font-mono text-slate-400">{adminOrders.length} đơn</span>
                </div>

                {adminOrders.length === 0 ? (
                  <p className="text-xs text-slate-500 py-6 text-center">Không có đơn hàng nào.</p>
                ) : (
                  <div className="space-y-3 max-h-96 overflow-y-auto pr-1">
                    {adminOrders.map((ord) => (
                      <div
                        key={ord.id}
                        className="p-3.5 rounded-xl bg-white/5 border border-white/5 space-y-2"
                      >
                        <div className="flex items-start justify-between">
                          <div>
                            <span className="font-bold text-sm text-white block">{ord.title}</span>
                            <span className="text-xs text-slate-400">
                              Mã: <span className="font-mono text-slate-300">{ord.id}</span> • KH: {ord.userName || ord.userId}
                            </span>
                          </div>
                          <span className="text-sm font-bold text-emerald-400">
                            {(ord.amount ?? ord.quoted_amount ?? 0) === 0 ? "Miễn Phí" : `${formatVnd(ord.amount ?? ord.quoted_amount)} đ`}
                          </span>
                        </div>

                        <div className="flex items-center justify-between pt-2 border-t border-white/5 text-xs">
                          <span className="text-slate-400">Trạng thái: <strong className="text-white">{ord.status}</strong></span>
                          <div className="flex items-center gap-1.5">
                            {ord.status !== "completed" && (
                              <button
                                onClick={() => handleUpdateOrderStatus(ord.id, "completed")}
                                className="px-2 py-1 rounded bg-emerald-500/20 text-emerald-300 hover:bg-emerald-500/30 font-semibold text-[11px]"
                              >
                                Hoàn tất
                              </button>
                            )}
                            {ord.status !== "processing" && (
                              <button
                                onClick={() => handleUpdateOrderStatus(ord.id, "processing")}
                                className="px-2 py-1 rounded bg-blue-500/20 text-blue-300 hover:bg-blue-500/30 font-semibold text-[11px]"
                              >
                                Đang xử lý
                              </button>
                            )}
                          </div>
                        </div>
                      </div>
                    ))}
                  </div>
                )}
              </div>

              {/* Deposit Approvals Queue */}
              <div className="glass-panel p-6 space-y-4">
                <div className="flex items-center justify-between">
                  <div className="flex items-center gap-2">
                    <Wallet className="w-5 h-5 text-emerald-400" />
                    <h4 className="font-bold text-white text-base">Duyệt Nạp Tiền VietQR (Deposits)</h4>
                  </div>
                  <span className="text-xs font-mono text-slate-400">{adminDeposits.length} yêu cầu</span>
                </div>

                {adminDeposits.length === 0 ? (
                  <p className="text-xs text-slate-500 py-6 text-center">Không có yêu cầu nạp tiền nào.</p>
                ) : (
                  <div className="space-y-3 max-h-96 overflow-y-auto pr-1">
                    {adminDeposits.map((dep) => (
                      <div
                        key={dep.id}
                        className="p-3.5 rounded-xl bg-white/5 border border-white/5 space-y-2"
                      >
                        <div className="flex items-start justify-between">
                          <div>
                            <span className="text-sm font-bold text-emerald-400 block">
                              +{formatVnd(dep.amount)} đ
                            </span>
                            <span className="text-xs text-slate-400">
                              Nội dung: <span className="font-mono text-cyan-300 font-semibold">{dep.transferContent}</span>
                            </span>
                            <span className="text-[11px] text-slate-500 block">
                              ID: {dep.id} • KH: {dep.userId}
                            </span>
                          </div>
                          <span
                            className={`text-[10px] font-bold uppercase px-2 py-0.5 rounded-md ${
                              dep.status === "approved"
                                ? "bg-emerald-500/20 text-emerald-300"
                                : dep.status === "rejected"
                                ? "bg-red-500/20 text-red-300"
                                : "bg-amber-500/20 text-amber-300"
                            }`}
                          >
                            {dep.status}
                          </span>
                        </div>

                        {dep.status === "pending" && (
                          <div className="flex items-center justify-end gap-2 pt-2 border-t border-white/5">
                            <button
                              onClick={() => handleReviewDeposit(dep.id, "approved")}
                              className="px-3 py-1 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white font-semibold text-xs flex items-center gap-1"
                            >
                              <Check className="w-3.5 h-3.5" />
                              <span>Duyệt & Cộng Tiền</span>
                            </button>
                            <button
                              onClick={() => handleReviewDeposit(dep.id, "rejected")}
                              className="px-3 py-1 rounded-lg bg-red-600/30 hover:bg-red-600/40 text-red-300 font-semibold text-xs flex items-center gap-1"
                            >
                              <X className="w-3.5 h-3.5" />
                              <span>Từ chối</span>
                            </button>
                          </div>
                        )}
                      </div>
                    ))}
                  </div>
                )}
              </div>
            </div>

            {/* Giftcode Vault Management */}
            <div className="glass-panel p-6 space-y-4">
              <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
                <div>
                  <h4 className="font-bold text-white text-base flex items-center gap-2">
                    <Gift className="w-5 h-5 text-rose-400" />
                    <span>Kho Mã Giftcode Phát Hành (Giftcode Vault)</span>
                  </h4>
                  <p className="text-xs text-slate-400">Quản trị mã quà tặng tặng tiền cho khách hàng</p>
                </div>

                <button
                  onClick={async () => {
                    const code = prompt("Nhập tiền tố Giftcode (VD: PROMO):", "VIP");
                    if (!code) return;
                    const amount = Number(prompt("Nhập số tiền VNĐ tặng:", "50000"));
                    if (!amount || isNaN(amount)) return;
                    try {
                      const res = await telegramFetch("/api/giftcodes", {
                        method: "POST",
                        headers: { "Content-Type": "application/json" },
                        body: JSON.stringify({ codePrefix: code, amountVnd: amount, maxUses: 10 })
                      });
                      const d = await res.json();
                      if (res.ok) {
                        showToast(`Đã tạo Giftcode: ${d.code} (${formatVnd(amount)} đ)`);
                        fetchData();
                      }
                    } catch (err) {
                      showToast(err.message, "error");
                    }
                  }}
                  className="px-3 py-1.5 rounded-xl bg-rose-600 hover:bg-rose-500 text-white text-xs font-semibold flex items-center gap-1.5"
                >
                  <Plus className="w-3.5 h-3.5" />
                  <span>Phát Hành Giftcode Mới</span>
                </button>
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                {adminGiftcodes.map((gc) => (
                  <div key={gc.code} className="p-3.5 rounded-xl bg-white/5 border border-white/5 space-y-1.5">
                    <div className="flex items-center justify-between">
                      <span className="font-mono font-bold text-sm text-cyan-300">{gc.code}</span>
                      <span className="text-xs font-bold text-emerald-400">{formatVnd(gc.amount)} đ</span>
                    </div>
                    <div className="text-[11px] text-slate-400 flex items-center justify-between">
                      <span>Đã dùng: {gc.usedCount} / {gc.maxUses}</span>
                      <span className="text-slate-500">{gc.status}</span>
                    </div>
                  </div>
                ))}
              </div>
            </div>

            {/* Bot System Settings & VietQR Configuration (Stored in Cloudflare D1) */}
            <BotSettingsCard
              settings={botSettings}
              onSaveSettings={handleSaveBotSettings}
              showToast={showToast}
            />
            <BotFleetMenuCard showToast={showToast} />
            <MenuAppearanceCard
              settings={botSettings}
              onSaveSettings={handleSaveBotSettings}
              showToast={showToast}
            />
          </div>
        )}

        {/* ========================================================= */}
        {/* TAB 4: SUPPORT TICKETS HUB (Mini App for Customer Support) */}
        {/* ========================================================= */}
        {activeTab === "support" && (
          <div className="space-y-6 animate-fade-in">
            <div className="glass-panel p-6 border-orange-500/30">
              <div className="flex items-center justify-between mb-4">
                <div className="flex items-center gap-3">
                  <div className="w-10 h-10 rounded-xl bg-orange-500/20 text-orange-400 flex items-center justify-center">
                    <LifeBuoy className="w-5 h-5" />
                  </div>
                  <div>
                    <h3 className="font-bold text-white text-lg">Trung Tâm Hỗ Trợ Khách Hàng (Support Hub)</h3>
                    <p className="text-xs text-slate-400">Xem lịch sử đơn hàng, gửi thắc mắc và nhận phản hồi trực tiếp</p>
                  </div>
                </div>
              </div>

              <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                {/* Create Ticket */}
                <form onSubmit={handleCreateTicket} className="space-y-3.5">
                  <h4 className="font-bold text-white text-sm">Gửi Yêu Cầu Hỗ Trợ Mới</h4>
                  <div>
                    <label className="text-xs text-slate-400 block mb-1">Chủ đề cần hỗ trợ</label>
                    <input
                      type="text"
                      required
                      value={newTicketSubject}
                      onChange={(e) => setNewTicketSubject(e.target.value)}
                      placeholder="VD: Không nhận được mã truy cập..."
                      className="w-full px-3.5 py-2 rounded-xl bg-white/5 border border-white/10 text-sm text-white focus:outline-none focus:border-orange-500"
                    />
                  </div>

                  <div>
                    <label className="text-xs text-slate-400 block mb-1">Danh mục</label>
                    <select
                      value={newTicketCategory}
                      onChange={(e) => setNewTicketCategory(e.target.value)}
                      className="w-full px-3.5 py-2 rounded-xl bg-[#111827] border border-white/10 text-sm text-white focus:outline-none focus:border-orange-500"
                    >
                      <option value="order">Vấn đề Đơn Hàng / Thanh Toán</option>
                      <option value="technical">Hỗ Trợ Kỹ Thuật Cloudflare/Render</option>
                      <option value="giftcode">Thắc Mắc Giftcode / Ví</option>
                      <option value="other">Ý kiến đóng góp khác</option>
                    </select>
                  </div>

                  <div>
                    <label className="text-xs text-slate-400 block mb-1">Nội dung chi tiết</label>
                    <textarea
                      rows={3}
                      required
                      value={newTicketMessage}
                      onChange={(e) => setNewTicketMessage(e.target.value)}
                      placeholder="Mô tả cụ thể sự cố bạn gặp phải..."
                      className="w-full px-3.5 py-2 rounded-xl bg-white/5 border border-white/10 text-sm text-white focus:outline-none focus:border-orange-500"
                    />
                  </div>

                  <button type="submit" className="w-full btn-primary bg-orange-600 hover:bg-orange-500">
                    <Send className="w-4 h-4" />
                    <span>Gửi Yêu Cầu Hỗ Trợ</span>
                  </button>
                </form>

                {/* Tickets List */}
                <div className="lg:col-span-2 space-y-3">
                  <h4 className="font-bold text-white text-sm">Danh Sách Phiếu Hỗ Trợ ({tickets.length})</h4>
                  {tickets.length === 0 ? (
                    <p className="text-xs text-slate-500 py-8 text-center">Chưa có ticket hỗ trợ nào.</p>
                  ) : (
                    <div className="space-y-3 max-h-96 overflow-y-auto pr-1">
                      {tickets.map((t) => (
                        <div
                          key={t.id}
                          className="p-4 rounded-xl bg-white/5 border border-white/5 space-y-3"
                        >
                          <div className="flex items-start justify-between">
                            <div>
                              <span className="font-bold text-sm text-white block">{t.subject}</span>
                              <span className="text-xs text-slate-400">
                                Mã: <span className="font-mono text-cyan-300">#{t.id}</span> • {new Date(t.created_at).toLocaleDateString("vi-VN")}
                              </span>
                            </div>
                            <span className="text-[10px] font-bold uppercase px-2 py-0.5 rounded bg-blue-500/20 text-blue-300">
                              {t.status}
                            </span>
                          </div>

                          <div className="space-y-2 pt-2 border-t border-white/5">
                            {t.messages?.map((msg, idx) => (
                              <div
                                key={idx}
                                className={`p-2.5 rounded-lg text-xs ${
                                  msg.sender === "support"
                                    ? "bg-blue-600/20 text-blue-200 border border-blue-500/30"
                                    : "bg-white/5 text-slate-300"
                                }`}
                              >
                                <span className="font-bold block mb-0.5">
                                  {msg.sender === "support" ? "🎧 Kỹ Thuật Viên Hendy:" : "👤 Khách Hàng:"}
                                </span>
                                {msg.text}
                              </div>
                            ))}
                          </div>

                          <div className="flex gap-2 pt-2">
                            <input
                              type="text"
                              placeholder="Nhập câu trả lời..."
                              value={selectedTicket?.id === t.id ? ticketReplyMessage : ""}
                              onFocus={() => setSelectedTicket(t)}
                              onChange={(e) => {
                                setSelectedTicket(t);
                                setTicketReplyMessage(e.target.value);
                              }}
                              className="flex-1 px-3 py-1.5 rounded-lg bg-white/5 border border-white/10 text-xs text-white focus:outline-none focus:border-blue-500"
                            />
                            <button
                              onClick={() => handleReplyTicket(t.id)}
                              className="px-3 py-1.5 rounded-lg bg-blue-600 hover:bg-blue-500 text-white text-xs font-semibold"
                            >
                              Gửi
                            </button>
                          </div>
                        </div>
                      ))}
                    </div>
                  )}
                </div>
              </div>
            </div>
          </div>
        )}

              </main>

      {/* ========================================================= */}
      {/* MODAL: PRODUCT DETAILS (Google Play Style Modal) */}
      {/* ========================================================= */}
      {selectedProduct && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm animate-fade-in">
          <div className="glass-panel max-w-lg w-full max-h-[90vh] overflow-y-auto p-6 space-y-6 relative border-blue-500/40">
            <button
              onClick={() => setSelectedProduct(null)}
              className="absolute top-4 right-4 p-2 rounded-xl bg-white/10 hover:bg-white/20 text-slate-300 hover:text-white"
            >
              <X className="w-5 h-5" />
            </button>

            {/* Product Header */}
            <div className="flex items-start gap-4">
              <div className="w-16 h-16 rounded-2xl bg-gradient-to-tr from-blue-600 to-indigo-600 p-[2px] shrink-0 shadow-lg">
                <div className="w-full h-full bg-[#111827] rounded-[14px] flex items-center justify-center text-3xl">
                  {selectedProduct.icon || "📦"}
                </div>
              </div>
              <div className="flex-1 pr-6">
                <h3 className="text-xl font-bold text-white">{selectedProduct.title}</h3>
                <p className="text-xs text-blue-400 font-semibold">{selectedProduct.developer || "Hendy Studio"}</p>
                <div className="flex items-center gap-2 mt-2 text-xs">
                  <span className="star-rating">
                    <Star className="w-3.5 h-3.5 fill-amber-400" />
                    <span>{selectedProduct.rating ?? "Chưa có đánh giá"}</span>
                  </span>
                  <span className="text-slate-500">•</span>
                  <span className="text-slate-300">{selectedProduct.downloads ?? "Chưa có dữ liệu"} tải</span>
                  <span className="text-slate-500">•</span>
                  <span className="text-indigo-300 font-mono font-semibold">{selectedProduct.version || "Chưa cập nhật"}</span>
                </div>
              </div>
            </div>

            {/* Action Buttons */}
            <div className="flex items-center gap-3 pt-2 border-t border-white/10">
              <button
                onClick={() => handleBuyProduct(selectedProduct)}
                className="btn-primary flex-1 py-3 text-base"
              >
                <Download className="w-5 h-5" />
                <span>
                  {(selectedProduct.price ?? selectedProduct.unit_price ?? 0) === 0
                    ? "Cài Đặt Ngay (Miễn Phí)"
                    : `Thanh Toán Bằng Ví • ${formatVnd(selectedProduct.price ?? selectedProduct.unit_price)} đ`}
                </span>
              </button>
              <button
                onClick={() => {
                  navigator.clipboard?.writeText(window.location.href);
                  showToast("Đã sao chép liên kết ứng dụng!");
                }}
                className="btn-secondary py-3"
              >
                Chia Sẻ
              </button>
            </div>

            {/* Description & About */}
            <div className="space-y-2">
              <h4 className="text-sm font-bold uppercase tracking-wider text-slate-400">Về Ứng Dụng Này</h4>
              <p className="text-sm text-slate-300 leading-relaxed">
                {selectedProduct.description || selectedProduct.tagline}
              </p>
            </div>

            {/* Technical Package Data (Cloudflare R2 & D1) */}
            <div className="p-4 rounded-xl bg-black/40 border border-white/10 space-y-2 text-xs">
              <div className="flex justify-between">
                <span className="text-slate-400">Kho lưu trữ R2:</span>
                <span className="font-mono text-cyan-300">{selectedProduct.r2Bucket || "hendy-bot-assets"}</span>
              </div>
              <div className="flex justify-between">
                <span className="text-slate-400">File gói:</span>
                <span className="font-mono text-slate-300">{selectedProduct.r2ObjectKey || "Chưa có tệp"}</span>
              </div>
              <div className="flex justify-between">
                <span className="text-slate-400">Cloudflare Pages:</span>
                <span className="font-mono text-indigo-300">{selectedProduct.pagesDeployUrl || "Chưa cấu hình"}</span>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* ========================================================= */}
      {/* MODAL: VIETQR QUICK DEPOSIT MODAL */}
      {/* ========================================================= */}
      {showDepositModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm animate-fade-in">
          <div className="glass-panel max-w-md w-full p-6 space-y-5 relative border-emerald-500/40">
            <button
              onClick={() => {
                setShowDepositModal(false);
                setDepositResult(null);
              }}
              className="absolute top-4 right-4 p-2 rounded-xl bg-white/10 hover:bg-white/20 text-slate-300 hover:text-white"
            >
              <X className="w-5 h-5" />
            </button>

            <div className="flex items-center gap-3">
              <div className="w-10 h-10 rounded-xl bg-emerald-500/20 text-emerald-400 flex items-center justify-center">
                <QrCode className="w-6 h-6" />
              </div>
              <div>
                <h3 className="font-bold text-white text-base">Nạp Tiền VietQR Tự Động</h3>
                <p className="text-xs text-slate-400">Quét mã QR chuyển khoản ngân hàng 24/7</p>
              </div>
            </div>

            {!depositResult ? (
              <div className="space-y-4">
                <div>
                  <label className="text-xs font-semibold text-slate-300 block mb-1.5">Chọn hoặc nhập số tiền (VNĐ)</label>
                  <div className="grid grid-cols-3 gap-2 mb-2">
                    {[50000, 100000, 200000, 500000, 1000000, 2000000].map((amt) => (
                      <button
                        key={amt}
                        type="button"
                        onClick={() => setDepositAmount(amt)}
                        className={`py-2 rounded-xl text-xs font-semibold border transition-all ${
                          depositAmount === amt
                            ? "bg-emerald-600 text-white border-emerald-500"
                            : "bg-white/5 text-slate-300 border-white/10 hover:bg-white/10"
                        }`}
                      >
                        {formatVnd(amt)} đ
                      </button>
                    ))}
                  </div>
                  <input
                    type="number"
                    min="10000"
                    step="10000"
                    value={depositAmount}
                    onChange={(e) => setDepositAmount(Number(e.target.value))}
                    className="w-full px-4 py-2.5 rounded-xl bg-white/5 border border-white/10 text-white font-mono text-sm focus:outline-none focus:border-emerald-500"
                  />
                </div>

                <button onClick={handleCreateDeposit} className="w-full btn-primary bg-emerald-600 hover:bg-emerald-500">
                  <CreditCard className="w-4 h-4" />
                  <span>Tạo Mã Chuyển Khoản VietQR</span>
                </button>
              </div>
            ) : (
              <div className="space-y-4 text-center">
                <div className="bg-white p-3 rounded-2xl inline-block mx-auto shadow-xl">
                  {depositResult.vietQrUrl ? (
                    <img
                      src={depositResult.vietQrUrl}
                      alt="Mã QR Chuyển Khoản"
                      className="w-48 h-48 mx-auto object-contain"
                    />
                  ) : (
                    <QrCode className="w-48 h-48 text-slate-900" />
                  )}
                </div>

                <div className="p-3.5 rounded-xl bg-white/5 border border-white/10 space-y-1.5 text-left text-xs">
                  <div className="flex justify-between">
                    <span className="text-slate-400">Số tiền:</span>
                    <span className="font-bold text-emerald-400 text-sm">
                      {formatVnd(depositResult.deposit?.amount)} đ
                    </span>
                  </div>
                  <div className="flex justify-between">
                    <span className="text-slate-400">Nội dung CK:</span>
                    <span className="font-mono font-bold text-cyan-300">
                      {depositResult.deposit?.transferContent}
                    </span>
                  </div>
                  <div className="flex justify-between">
                    <span className="text-slate-400">Ngân hàng:</span>
                    <span className="text-white font-semibold">{depositResult.transfer?.bankId || "Chưa xác định"}</span>
                  </div>
                </div>

                <p className="text-[11px] text-amber-300/90">
                  ⚠️ Vui lòng ghi chính xác nội dung chuyển khoản. Yêu cầu sẽ được lưu để đối soát và chờ quản trị viên xác nhận trước khi cộng tiền.
                </p>

                <button
                  onClick={() => {
                    setShowDepositModal(false);
                    setDepositResult(null);
                  }}
                  className="w-full btn-secondary"
                >
                  Đã Hoàn Tất Chuyển Khoản
                </button>
              </div>
            )}
          </div>
        </div>
      )}
    </div>
  );
}
