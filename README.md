# Hendy Bot Tele

Monorepo Node.js triển khai trên **Render + Managed PostgreSQL**. Ứng dụng gồm Express API, một hoặc hai Telegram Bot dùng grammY, Dashboard tiếng Việt, Reply Keyboard / Inline Keyboard và WebSocket realtime.

> **Kiến trúc chính:** Render Web Service + Render PostgreSQL. Cloudflare Workers, Pages, D1 và Wrangler không còn là thành phần runtime của bản này.

## Chức năng

- **Hai Telegram Bot trong một mã nguồn:** bot chính dùng `BOTCHINH_TOKEN`, bot phụ dùng `BOTPHU_TOKEN`. Để tương thích, backend cũng nhận `BOT1_TOKEN`/`BOT2_TOKEN` và tên cũ `BOT_TOKEN`/`BOT_SECOND_TOKEN`. Bot phụ có thể dùng `BOTPHU_ADMIN_ID`; nếu bỏ trống sẽ dùng chung `ADMIN_ID`.
- **Hai loại menu Telegram:** Reply Keyboard dạng lưới cố định phía dưới khung nhập; Inline Keyboard nằm bên dưới tin nhắn. Cho phép chọn số cột, nội dung giới thiệu, nhãn/mô tả nút, persistent/resize và semantic style.
- **Dashboard tiếng Việt:** quản lý sản phẩm, đơn hàng, thành viên, số dư, giftcode, hỗ trợ, thiết lập VietQR/liên kết cộng đồng và menu theo từng bot.
- **Realtime WebSocket:** trạng thái bot, snapshot cấu hình và thông báo thay đổi menu.
- **PostgreSQL:** dữ liệu thành viên, giao dịch, đơn hàng, ví, giftcode, ticket, menu, audit, liên kết tài khoản và ghép đôi.
- **Webhook ngân hàng:** xác thực bằng secret riêng, kiểm tra nội dung nạp và mã giao dịch idempotent.
- **Tự scaffold tính năng:** CLI và GitHub Actions tạo nhánh/tệp thử nghiệm; scaffold không tự kích hoạt code.
- **Kiểm tra CI:** cú pháp JavaScript, unit tests và build dashboard.

## Cấu trúc repository

```text
.
├── .github/workflows/
│   ├── ci.yml
│   └── scaffold-feature.yml
├── apps/
│   ├── backend-api/
│   │   ├── src/
│   │   │   ├── index.js
│   │   │   ├── api.js
│   │   │   ├── database.js
│   │   │   ├── botClient.js
│   │   │   ├── botAdmin.js
│   │   │   ├── telegramAuth.js
│   │   │   ├── ui/buttonTheme.js
│   │   │   └── features/registry.js
│   │   └── package.json
│   └── admin-dashboard/
│       ├── index.html
│       ├── styles/buttons.css
│       ├── build.js
│       └── package.json
├── scripts/
│   ├── scaffold-feature.js
│   └── scaffold-feature.test.js
├── .env.example
├── package.json
└── render.yaml
```

## Triển khai Render

Blueprint ở `render.yaml` định nghĩa:

- Web Service `hendy-bot-tele`, Node.js 22, vùng Singapore.
- PostgreSQL `hendy-bot-tele-db`, database `hendy_bot_tele`, cùng vùng Singapore.
- `DATABASE_URL` được nối từ thuộc tính `connectionString` của database; không lưu connection string trong repository.
- Ứng dụng dùng kết nối nội bộ Render, vì vậy `DATABASE_SSL=false` trong cấu hình Blueprint.
- Health check tại `/healthz`; Render chỉ tự deploy khi các kiểm tra CI thành công.

### Tạo Blueprint

1. Đưa nhánh `main` của repository này lên GitHub và kết nối GitHub với Render.
2. Mở [Render Blueprint](https://dashboard.render.com/blueprint/new?repo=https://github.com/hendyngo-servers/Hendy-Bot-Tele).
3. Kiểm tra tên Web Service và database, vùng Singapore, cùng chi phí/plan hiển thị trước khi chọn **Apply**.
4. Nhập `BOTCHINH_TOKEN` và `ADMIN_ID`. Blueprint sinh `TELEGRAM_WEBHOOK_SECRET`, `TELEGRAM_SECOND_WEBHOOK_SECRET`, `BANK_WEBHOOK_SECRET` và `LEGACY_SESSION_SECRET`.
5. Để chạy bot phụ, nhập `BOTPHU_TOKEN` và có thể thêm `BOTPHU_ADMIN_ID` (nếu cùng Admin thì để trống). Nếu Render không cho để trống các biến bot thứ hai, chỉ bật chúng sau khi service đầu tiên đã chạy bằng cách thêm ở Environment.
6. Đợi deploy hoàn tất, sau đó kiểm tra `https://<ten-service>.onrender.com/healthz` và `/api/health`.

Render cấp `RENDER_EXTERNAL_URL` cho Web Service. Khi biến này có mặt, ứng dụng tự đăng ký webhook:

- Bot chính: `/webhook/telegram`
- Bot thứ hai: `/webhook/telegram/secondary`

Không đưa token Telegram, mật khẩu PostgreSQL hoặc webhook secret vào GitHub Actions, source code, issue hay log. Quản lý chúng trong **Render → Environment**.

### Biến môi trường

| Biến | Bắt buộc | Mục đích |
|---|---:|---|
| `BOTCHINH_TOKEN` | Có | Token BOT CHÍNH; bí danh `BOT1_TOKEN` hoặc `BOT_TOKEN` |
| `ADMIN_ID` | Có | Telegram user ID của Admin chính |
| `DATABASE_URL` | Tự nối | Render PostgreSQL connection string |
| `DATABASE_SSL` | Có trên Render | `false` cho kết nối nội bộ cùng vùng |
| `TELEGRAM_WEBHOOK_SECRET` | Tự sinh | Bảo vệ webhook bot chính |
| `BOTPHU_TOKEN` | Không | Bật BOT PHỤ; bí danh `BOT2_TOKEN` hoặc `BOT_SECOND_TOKEN` |
| `BOTPHU_ADMIN_ID` | Không | Admin riêng cho BOT PHỤ; mặc định dùng `ADMIN_ID` |
| `TELEGRAM_SECOND_WEBHOOK_SECRET` | Khi bật bot 2 | Secret webhook bot thứ hai |
| `BANK_WEBHOOK_SECRET` | Khi dùng nạp tự động | Xác thực webhook ngân hàng |
| `LEGACY_ACCOUNT_URL` | Không | URL hệ thống tài khoản cũ |
| `LEGACY_SESSION_SECRET` | Khi dùng đăng nhập cũ | Ký ticket phiên đăng nhập một lần |
| `VIETQR_BANK_ID`, `VIETQR_ACCOUNT_NO`, `VIETQR_ACCOUNT_NAME` | Khi dùng VietQR | Thông tin nhận tiền |
| `GROUP_URL`, `CHANNEL_URL` | Không | Liên kết cộng đồng |

Các trường nghiệp vụ có thể được quản trị trong Dashboard. Không nhập credential vào biểu mẫu cài đặt nghiệp vụ.

## Chạy local

Yêu cầu Node.js 22+ và một PostgreSQL database có thể kết nối từ máy local.

```bash
cp .env.example .env
# Điền token bot, Admin ID, DATABASE_URL và webhook secret trong .env
npm install
npm run check
npm run build
npm start
```

Khi chạy local và không có `RENDER_EXTERNAL_URL`, bot dùng long polling. Đặt `DATABASE_SSL=true` khi kết nối PostgreSQL qua external URL yêu cầu TLS; với DB local có thể đặt `DATABASE_SSL=false`.

## Dashboard và xác thực Telegram

Dashboard được phục vụ tại đường dẫn gốc `/`. Các phiên cần dữ liệu Telegram WebApp `initData` đã ký; không hỗ trợ đăng nhập bằng Telegram ID tự nhập. Admin được đối chiếu với `ADMIN_ID` hoặc `BOT_SECOND_ADMIN_ID` theo bot đang sử dụng.

## Scaffold tính năng

```bash
npm run scaffold:feature -- --name order-history --category api --description "Lịch sử đơn hàng"
```

Hoặc vào GitHub → Actions → **Scaffold feature branch**. Các loại hợp lệ: `api`, `bot`, `dashboard`. Feature mới mặc định disabled; cần review phân quyền, migration, API và test trước khi đăng ký/bật.

## Chuyển đổi từ Cloudflare D1

Việc thay thế mã nguồn và hạ tầng **không tự chuyển dữ liệu đang lưu trong D1** sang PostgreSQL. Trước khi ngừng Workers/Pages/D1 cũ:

1. Sao lưu/export D1 và xác nhận có thể khôi phục.
2. Mapping schema D1 sang các bảng PostgreSQL của ứng dụng; kiểm tra số dư, giao dịch, đơn hàng, referral và mã giftcode.
3. Import vào database Render, đối soát số dòng và tổng số dư/ledger.
4. Chạy thử cả hai bot, webhook, Dashboard và luồng nạp tiền trong môi trường kiểm thử.
5. Chỉ ngừng tài nguyên Cloudflare cũ sau khi đã xác nhận dữ liệu và nghiệp vụ trên Render.

Không xóa hoặc bỏ database Cloudflare hiện tại trước khi hoàn tất đối soát và sao lưu.

## Cảnh báo bảo mật quan trọng

Kho nguồn `hendy-bot-render` đã có commit công khai chứa chuỗi có hình thức giống Telegram Bot Token và PostgreSQL credential trong `.env.example`. Bản sao sang repository này đã thay bằng placeholder, nhưng việc sửa file **không xóa bí mật khỏi Git history của kho nguồn**. Hãy thu hồi/cấp lại token bot bị lộ, đổi mật khẩu PostgreSQL, tạo webhook secret mới và không tái sử dụng bất kỳ credential nào đã commit công khai.

