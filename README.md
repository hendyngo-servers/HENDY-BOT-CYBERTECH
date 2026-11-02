# Hendy Bot Tele — PHP Edition

Hệ thống Telegram Bot kép trong một mã nguồn, triển khai bằng **PHP 8.3 + Apache + PostgreSQL** trên Render. Giao diện Mini App/Dashboard tiếng Việt được giữ lại bằng HTML/CSS/JavaScript thuần; backend, xác thực Telegram, Bot API, API nghiệp vụ, webhook và cơ sở dữ liệu đã chuyển sang PHP.

> **Chỉ dùng hai biến Telegram Bot Token:** **BOT1_TOKEN** và **BOT2_TOKEN**. BOT2_TOKEN có thể để trống để chỉ chạy một bot. ADMIN_ID là Telegram user ID dùng chung cho hai bot.

## Những tính năng được giữ lại

- Hai Telegram bot độc lập trong một ứng dụng PHP, cùng dùng một PostgreSQL database.
- Reply Keyboard dạng lưới cố định phía dưới khung nhập và Inline Keyboard nằm dưới tin nhắn; từng bot có thể chọn loại menu, số cột, phần giới thiệu, tên/mô tả nút, màu semantic, resize và persistent.
- Dashboard tiếng Việt: tổng quan, sản phẩm, đơn hàng, thành viên, điều chỉnh số dư, ticket CSKH, giftcode, thiết lập VietQR/liên kết cộng đồng và cấu hình menu của hai bot.
- Telegram Mini App authentication bằng chữ ký initData và HMAC SHA-256; không tin Telegram ID do client tự nhập.
- Sản phẩm/dịch vụ: Mini Game Live, thuê tool, code, OTP, proxy, VIP.
- Hồ sơ thành viên, liên kết tài khoản game bằng thương hiệu và username, không lưu mật khẩu/OTP.
- Ví, lịch sử giao dịch, VietQR, nhận callback ngân hàng qua secret riêng và idempotency theo mã giao dịch.
- Đơn hàng, trừ ví trong transaction, hoàn tiền khi Admin từ chối/hủy, bàn giao khi hoàn tất.
- Giftcode có số lượt, hạn dùng, khóa/mở và giới hạn mỗi thành viên một lần.
- Referral, phần trăm hoa hồng, ghi nhận thưởng khi nạp tiền.
- Ticket hỗ trợ, phản hồi từ Admin qua Telegram.
- Ghép đôi theo hàng chờ nhanh/luyện tập.
- Cấu hình menu theo từng bot và nhật ký thao tác quản trị.
- Tạo feature scaffold bằng PHP CLI và GitHub Actions.
- Health checks, CI PHP lint và unit test xác thực Telegram.

## Kiến trúc

    .
    ├── app/
    │   ├── Env.php
    │   ├── Database.php
    │   ├── TelegramApi.php
    │   ├── TelegramAuth.php
    │   ├── BotService.php
    │   └── ApiController.php
    ├── bin/
    │   ├── migrate.php
    │   ├── validate-config.php
    │   ├── register-webhooks.php
    │   └── polling.php
    ├── database/schema.sql
    ├── public/
    │   ├── index.php
    │   └── .htaccess
    ├── apps/admin-dashboard/
    │   ├── index.html
    │   └── styles/buttons.css
    ├── docker/
    │   ├── apache-vhost.conf
    │   └── start.sh
    ├── scripts/scaffold-feature.php
    ├── tests/run.php
    ├── Dockerfile
    ├── composer.json
    ├── .env.example
    └── render.yaml

### Phân chia PHP và JavaScript

Backend chạy PHP: nhận webhook Telegram, gửi Telegram Bot API, xác thực WebApp, API, xử lý giao dịch, quản trị, database, migration và bảo mật HTTP.

JavaScript trong apps/admin-dashboard/index.html chỉ chạy trong trình duyệt để dựng giao diện, gọi API và thao tác form. Không cần và không nên thay JavaScript phía trình duyệt bằng PHP; PHP phục vụ HTML và xử lý dữ liệu trên máy chủ. Mini App dùng một bộ token CSS tập trung cho màu nút, hiệu ứng ripple, trạng thái loading/success và icon động; các emoji được gắn class tự động bằng text node để không thay đổi dữ liệu đầu vào. Tôn trọng cài đặt giảm chuyển động của thiết bị.

## Cấu hình môi trường

Tạo file .env từ .env.example. Hai biến token bot duy nhất:

    BOT1_TOKEN=YOUR_TELEGRAM_BOT_1_TOKEN
    BOT2_TOKEN=
    ADMIN_ID=YOUR_TELEGRAM_NUMERIC_ADMIN_ID

Các biến vận hành còn lại:

    APP_ENV=local
    PORT=8000
    APP_URL=
    WEBAPP_URL=
    ALLOWED_ORIGINS=http://localhost:8000,http://127.0.0.1:8000
    BOT_USERNAME=

    DATABASE_URL=postgresql://USER:PASSWORD@HOST:5432/hendy_bot_tele?sslmode=require
    DATABASE_SSL=true

    TELEGRAM_WEBHOOK_SECRET=RANDOM_SECRET_FOR_BOT_1
    TELEGRAM_SECOND_WEBHOOK_SECRET=RANDOM_SECRET_FOR_BOT_2
    BANK_WEBHOOK_SECRET=RANDOM_BANK_WEBHOOK_SECRET

    VIETQR_BANK_ID=
    VIETQR_ACCOUNT_NO=
    VIETQR_ACCOUNT_NAME=
    GROUP_URL=
    CHANNEL_URL=
    TELEGRAM_INIT_DATA_MAX_AGE_SECONDS=86400
    LEGACY_SESSION_SECRET=

Token mẫu chỉ là placeholder. Không commit .env, token Telegram, mật khẩu PostgreSQL hoặc webhook secret.

| Biến | Bắt buộc | Ý nghĩa |
|---|---:|---|
| BOT1_TOKEN | Có | Token bot chính |
| BOT2_TOKEN | Không | Token bot thứ hai; để trống để tắt bot 2 |
| ADMIN_ID | Có | Telegram numeric user ID của Admin; dùng cho cả hai bot |
| DATABASE_URL | Có | PostgreSQL connection string |
| DATABASE_SSL | Có | false cho PostgreSQL private/internal Render cùng region; true cho DB yêu cầu TLS |
| TELEGRAM_WEBHOOK_SECRET | Có khi deploy webhook | Secret bảo vệ webhook bot 1 |
| TELEGRAM_SECOND_WEBHOOK_SECRET | Khi bật bot 2 | Secret bảo vệ webhook bot 2 |
| BANK_WEBHOOK_SECRET | Khi dùng nạp tự động | Secret riêng do nhà cung cấp callback ngân hàng gửi |
| WEBHOOK_AUTO_REGISTER | Không | Mặc định `true`; đặt `false` để bỏ qua đăng ký webhook tự động khi khởi động |
| VIETQR_* | Khi dùng VietQR | Thông tin tài khoản nhận tiền |
| GROUP_URL, CHANNEL_URL | Không | Liên kết cộng đồng |
| APP_URL / RENDER_EXTERNAL_URL | Khi deploy | URL HTTPS để đăng ký webhook |
| LEGACY_SESSION_SECRET | Khi dùng cầu nối đăng nhập cũ | Ký ticket đăng nhập một lần |

Runtime PHP chỉ đọc BOT1_TOKEN và BOT2_TOKEN. Không dùng các bí danh BOTCHINH_TOKEN, BOTPHU_TOKEN, BOT_TOKEN hoặc BOT_SECOND_TOKEN.

## Chạy local

Yêu cầu PHP 8.3+, extension curl, mbstring, pdo, pdo_pgsql, cùng PostgreSQL.

1. Sao chép .env.example thành .env.
2. Điền BOT1_TOKEN, ADMIN_ID, DATABASE_URL và webhook secrets.
3. Kiểm tra cấu hình bằng lệnh: php bin/validate-config.php
4. Cập nhật schema bằng lệnh: php bin/migrate.php
5. Khởi chạy web: php -S 0.0.0.0:8000 -t public public/index.php
6. Truy cập http://localhost:8000.

Local polling cần chạy ở terminal riêng: php bin/polling.php. Polling sẽ gọi deleteWebhook cho token đã cấu hình. Không chạy polling cùng lúc với webhook trên production. Production dùng webhook HTTPS.

## Triển khai Render

PHP không có runtime native trên Render trong Blueprint này; Dockerfile cung cấp PHP 8.3, Apache và các extension PostgreSQL/cURL/mbstring cần thiết.

1. Mở Render Blueprint tại https://dashboard.render.com/blueprint/new?repo=https://github.com/hendyngo-servers/Hendy-Bot-Tele.
2. Kiểm tra Web Service, realtime service và PostgreSQL ở cùng một region (Blueprint mẫu dùng Singapore). Render chỉ phân giải Internal Database URL giữa các service cùng workspace và cùng region; nếu database hiện hữu ở Oregon thì phải chọn URL/region tương ứng, không dùng hostname nội bộ khác region.
3. Kiểm tra plan/chi phí hiển thị trước khi Apply.
4. Thiết lập BOT1_TOKEN, ADMIN_ID; thiết lập thêm BOT2_TOKEN nếu bật bot thứ hai.
5. Điền thông tin VietQR và liên kết cộng đồng nếu nghiệp vụ cần.
6. Render tạo webhook secret ngẫu nhiên và nối DATABASE_URL tới PostgreSQL.
7. Deploy hoàn tất, kiểm tra https://<service>.onrender.com/healthz và mở URL gốc để kiểm tra Dashboard.

Webhook được đăng ký tự động khi container khởi động nếu `WEBHOOK_AUTO_REGISTER=true` (mặc định). Ứng dụng gọi `getMe` để xác thực token trước khi gọi `setWebhook`. Nếu Telegram trả về `401 Unauthorized`, log sẽ chỉ rõ bot nào bị từ chối và yêu cầu cập nhật `BOT1_TOKEN`/`BOT2_TOKEN` trong Render; Dashboard/Apache vẫn khởi động, nhưng bot tương ứng chưa nhận update cho tới khi token hợp lệ và webhook được đăng ký lại. Đặt `WEBHOOK_AUTO_REGISTER=false` để tắt thao tác này khi cần. Blueprint chạy migration bằng `preDeployCommand: php bin/migrate.php` trước khi phát hành phiên bản mới. Container Web Service chỉ kiểm tra cấu hình, đăng ký webhook và chạy Apache; migration không còn chạy lặp lại mỗi lần container khởi động. Realtime service chờ PostgreSQL và bảng `realtime_events` sẵn sàng thay vì tự chạy migration cạnh tranh với Web Service. Nếu service được tạo thủ công và không quản lý bằng Blueprint, nhập chính lệnh `php bin/migrate.php` vào trường Pre-Deploy Command trong Settings của Web Service; không đưa lệnh này vào Docker Start Command.

### Chuyển service Render đang tồn tại từ Node sang Docker

Khi thay Blueprint trên một service đã tồn tại, đừng giả định các biến môi trường cũ sẽ tự bị xóa chỉ vì chúng không còn ở render.yaml. Mở Render → Service → Environment, nhập BOT1_TOKEN / BOT2_TOKEN, sau đó chủ động xóa các biến token cũ BOTCHINH_TOKEN, BOTPHU_TOKEN, BOT_TOKEN, BOT_SECOND_TOKEN và BOTPHU_ADMIN_ID / BOT_SECOND_ADMIN_ID nếu chúng còn tồn tại. Bản PHP không đọc các tên cũ.

Nếu dùng chính PostgreSQL hiện tại, giữ đúng database đang có dữ liệu; không tạo database mới thay thế trước khi sao lưu và đối soát. Mở database → Connect để lấy đúng URL: Render service cùng region/workspace dùng Internal Database URL; local/CI hoặc service khác region phải dùng External Database URL đầy đủ với `sslmode=require` và `DATABASE_SSL=true`. Blueprint mẫu tạo database `hendy-bot-tele-db` tại Singapore; nếu database hiện tại ở Oregon thì không áp dụng nguyên xi cấu hình này lên service đang chạy. Nếu Render báo xung đột resource, hãy ghép service với database hiện hữu thay vì xóa database.

## Realtime, module và kho ứng dụng\n\nDashboard Admin có tab Tệp & Module. Tệp tải lên (JSON/MD/TXT/CSS/HTML/ảnh, tối đa 2 MB) được lưu ở vùng cách ly riêng trong persistent disk, kiểm tra MIME và SHA-256, trạng thái ban đầu pending_review; không có chức năng thực thi code hoặc tự ghi đè production. Mọi thay đổi mã nguồn phải qua Pull Request và CI.\n\nRealtime dùng PHP Ratchet trong service Render riêng. Sau khi Blueprint tạo service `hendy-bot-tele-realtime`, đặt `WEBSOCKET_URL` ở service API thành URL `wss://...` của service realtime.\n\nCác adapter App Store Connect, Google Play và Microsoft Store mới là khung tích hợp. Chưa có credential/API permissions hoặc luồng upload/submit/publish hoạt động; cần thiết lập riêng theo tài khoản nhà phát hành. Xem `docs/REALTIME-MODULES-AND-STORES.md`.\n\n## Endpoint chính

- GET /: Dashboard / Telegram Mini App.
- GET /healthz: Health check cho Render.
- GET /api/health: trạng thái database và cấu hình hai bot.
- POST /webhook/telegram: webhook BOT1.
- POST /webhook/telegram/secondary: webhook BOT2 (khi bật).
- POST /webhook/bank: callback ngân hàng, yêu cầu header x-bank-webhook-secret.
- /api/bootstrap, /api/products, /api/me, /api/orders, /api/wallet, /api/referrals, /api/giftcodes/redeem, /api/support/tickets, /api/matchmaking, /api/vip.
- /api/admin/*: Dashboard quản trị; mọi yêu cầu phải có Telegram WebApp initData hợp lệ và trùng ADMIN_ID.\n- POST /api/admin/realtime-ticket: cấp ticket WebSocket ngắn hạn.\n- GET /api/admin/modules và POST /api/admin/modules/upload: quản lý tệp chờ kiểm duyệt.

## GitHub Actions / kiểm tra

CI chạy PHP 8.3, kiểm tra cú pháp tất cả file PHP, kiểm tra cú pháp JavaScript inline của Mini App, kiểm tra chữ ký Telegram, và xác nhận .env.example chỉ khai báo hai tên bot token. Docker cài `unzip` trước Composer để cài các gói dist trong image.

Scaffold feature bằng lệnh: php scripts/scaffold-feature.php --name order-history --category api --description "Lịch sử đơn hàng". Hoặc vào GitHub → Actions → Scaffold PHP feature. Feature được tạo ở trạng thái disabled; phải review phân quyền, migration, API và test trước khi tích hợp.

## Lưu ý nghiệp vụ và bảo mật

- Liên kết Mini Game Live hiện chỉ lưu thương hiệu và username do thành viên nhập; không tự đăng nhập vào dịch vụ bên thứ ba.
- Ghép đôi hiện là hàng chờ kết nối hai thành viên; không phải engine game trực tiếp, hệ thống điểm hoặc giải thưởng game.
- VietQR tạo mã thanh toán và backend nhận callback ngân hàng qua secret. Cần cấu hình nhà cung cấp webhook/bank adapter thực tế để gửi dữ liệu đã xác thực đến POST /webhook/bank; không coi nội dung chuyển khoản do người dùng tự gửi là bằng chứng nạp tiền.
- Các token bot và webhook secrets không hiển thị hoặc lưu trong Dashboard. Chúng được quản lý tại Render Environment.
- Migration chỉ tạo bảng/index/setting còn thiếu. Trước khi deploy với dữ liệu hiện hữu, hãy backup PostgreSQL và thử trên staging.
- Thay đổi code không tự chuyển dữ liệu đang ở Cloudflare D1 hoặc database khác. Hãy export, mapping, import, đối soát số dư/ledger/đơn hàng/referral trước khi ngừng nguồn dữ liệu cũ.
