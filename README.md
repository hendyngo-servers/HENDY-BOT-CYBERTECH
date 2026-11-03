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

PHP không có runtime native trong Blueprint này; Dockerfile cung cấp PHP 8.3, Apache và các extension PostgreSQL/cURL/mbstring cần thiết.

### Thiết lập Web Service

1. Runtime: **Docker**; Dockerfile: `./Dockerfile`; Docker Context: `.`.
2. **Build Command để trống** vì Render tự build Dockerfile.
3. **Docker Command / Start Command**: để trống để dùng `CMD` trong Dockerfile, hoặc đặt chính xác `/var/www/app/docker/start.sh`.
4. HTTP port: `10000` (đọc từ biến môi trường `PORT`, mặc định của Render).
5. Health Check Path: `/healthz`.
6. Không nhập `preDeployCommand: php bin/migrate.php` vào Docker Command. Đây là cấu hình sai và sẽ khiến Render cố chạy nguyên chuỗi đó như tên chương trình.

Blueprint khai báo Docker Command chính xác là `/var/www/app/docker/start.sh`. Với Web Service đã tạo thủ công, mở **Settings → Build & Deploy → Docker Command**, xóa nội dung sai đang có và để trống hoặc nhập đúng đường dẫn trên, sau đó lưu và deploy lại. Push code không tự xóa một Docker Command override đã lưu trực tiếp trong Dashboard.

### Migration và khởi động

Entrypoint chạy theo thứ tự: kiểm tra cấu hình → cấu hình Apache trên `PORT` → chờ PostgreSQL → áp dụng schema idempotent có PostgreSQL advisory lock → đăng ký webhook (lỗi Telegram được ghi cảnh báo, không chặn Apache) → chạy Apache foreground.

Mặc định `RUN_MIGRATIONS_ON_START=true`, phù hợp cả Render Free vì **Pre-Deploy Command chỉ khả dụng trên dịch vụ trả phí**. Với dịch vụ trả phí, có thể chuyển migration sang Pre-Deploy Command `php bin/migrate.php` và đặt `RUN_MIGRATIONS_ON_START=false`; không chạy cả hai cơ chế đồng thời. Realtime service chỉ chờ bảng `realtime_events` sẵn sàng và không chạy migration riêng.

### PostgreSQL, vùng triển khai và dữ liệu

Web Service và PostgreSQL phải cùng workspace và region để sử dụng Internal Database URL. Không dùng hostname nội bộ giữa hai region. Nếu buộc phải kết nối khác region, chỉ dùng External Database URL với TLS (`sslmode=require`, `DATABASE_SSL=true`) và cho phép các outbound IP của Web Service trong IP allow-list của database.

Region của PostgreSQL hiện hữu không thể đổi trực tiếp. Trước khi tạo database mới hoặc thay URL, hãy sao lưu và xác minh dữ liệu; không xóa database hiện tại khi chưa đối soát số dư, ledger, đơn hàng và referral. Nếu tạo Web Service mới để cùng region với database hiện có, cần kiểm tra lại URL công khai, webhook Telegram và cấu hình Mini App.

Blueprint dùng gói Starter và persistent disk cho vùng Tệp & Module. Việc Apply Blueprint có thể phát sinh chi phí; kiểm tra giá trước khi áp dụng. Web Service Free không có persistent disk, vì vậy tệp được upload vào filesystem trên gói Free không được bảo đảm tồn tại sau redeploy hoặc restart.

### Checklist triển khai

1. Mở Render Blueprint: https://dashboard.render.com/blueprint/new?repo=https://github.com/hendyngo-servers/Hendy-Bot-Tele.
2. Xác nhận Web Service, realtime service và PostgreSQL ở cùng region; kiểm tra plan/chi phí.
3. Thiết lập `BOT1_TOKEN`, `ADMIN_ID`; thêm `BOT2_TOKEN` nếu bật bot thứ hai.
4. Xác nhận `DATABASE_URL` trỏ đúng database cần dùng. Internal URL chỉ dùng cùng workspace/region; External URL cần TLS và IP allow-list.
5. Kiểm tra `PORT=10000`, `RUN_MIGRATIONS_ON_START=true`, `DB_STARTUP_WAIT_SECONDS=120`.
6. Deploy, sau đó mở `/healthz` và trang Dashboard.

Webhook đăng ký tự động khi `WEBHOOK_AUTO_REGISTER=true`. Ứng dụng gọi `getMe` trước `setWebhook`; nếu Telegram trả `401 Unauthorized`, log chỉ rõ bot nào bị từ chối nhưng không in token. Cập nhật token đúng biến `BOT1_TOKEN` hoặc `BOT2_TOKEN` trong Render Environment. Đặt `WEBHOOK_AUTO_REGISTER=false` để tắt đăng ký tự động khi cần.

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

## GitHub Actions: quét cấu hình, đồng bộ Render và xác minh Telegram

File nguồn chuẩn của biến runtime là `config/env.schema.json`. Lệnh:

```bash
php scripts/audit-env.php --check --self-test
```

quét các tham chiếu tĩnh `Env::get/int/bool`, `getenv()`, `$_ENV`, `process.env` và shell expansion trong source PHP/JS/HTML/Shell; đối chiếu từng tên với manifest và chạy self-test các biểu thức regex. Biến được phát hiện nhưng chưa khai báo sẽ làm CI thất bại. Scanner tĩnh không thể suy ra tên biến được tạo động lúc runtime.

### Thiết lập GitHub Environments

Tạo Environment tên `production` (và `staging` nếu cần) trong **Settings → Environments**. Trong từng Environment, thêm:

**Environment Secrets**
- Bắt buộc: `BOT1_TOKEN`.
- Nếu chạy bot thứ hai: `BOT2_TOKEN`.
- Có thể đặt trước hoặc để workflow tự sinh: `TELEGRAM_WEBHOOK_SECRET`, `TELEGRAM_SECOND_WEBHOOK_SECRET`, `BANK_WEBHOOK_SECRET`, `LEGACY_SESSION_SECRET`.
- Khi dùng VietQR: `VIETQR_ACCOUNT_NO`, `VIETQR_ACCOUNT_NAME`.
- Hạ tầng CI: `RENDER_API_KEY`, `GH_CONFIG_SYNC_TOKEN`.
- `DATABASE_URL` không được workflow ghi đè. Giữ biến này do Render PostgreSQL/Blueprint quản lý để tránh thay nhầm database.

**Environment Variables**
- Bắt buộc: `ADMIN_ID`, `APP_URL`, `RENDER_SERVICE_ID`.
- Có thể thêm: `APP_ENV`, `PORT`, `WEBAPP_URL`, `ALLOWED_ORIGINS`, `BOT_USERNAME`, `DATABASE_SSL`, `DB_STARTUP_WAIT_SECONDS`, `RUN_MIGRATIONS_ON_START`, `WEBHOOK_AUTO_REGISTER`, `VIETQR_BANK_ID`, `GROUP_URL`, `CHANNEL_URL`, `TELEGRAM_INIT_DATA_MAX_AGE_SECONDS`, `WEBSOCKET_URL`, `WEBSOCKET_BIND`, `WEBSOCKET_PORT`.

`GH_CONFIG_SYNC_TOKEN` cần quyền ghi GitHub Environment secrets/variables cho repository (fine-grained PAT hoặc GitHub App token với repository permission **Environments: write**). `RENDER_API_KEY` cần quyền cập nhật biến môi trường và trigger deploy cho service được chọn. Không dùng một token cá nhân có quyền rộng hơn mức cần thiết.

### Chạy workflow theo từng giai đoạn

Vào **Actions → Sync Runtime Config & Verify Telegram → Run workflow**:
1. Chọn Environment đích.
2. Lần đầu để `apply_changes=false`: chỉ audit, kiểm tra cấu hình và dry-run.
3. Khi report hợp lệ, bật `apply_changes=true`: đồng bộ từng key đúng loại Secret/Variable lên GitHub Environment và Render.
4. Chỉ bật `deploy_after_sync=true` sau khi đã bật `apply_changes=true`: trigger deploy, chờ trạng thái Render `live`, kiểm tra `/api/health` có `status=ok`, gọi Telegram `getMe` và đối chiếu `getWebhookInfo.result.url` cho từng bot.

Workflow mặc định không ghi cấu hình và không deploy. Khóa nội bộ còn thiếu được tạo bằng `openssl rand -hex 32`, che khỏi log, lưu lại trong GitHub Environment và Render để không đổi ở các lần chạy tiếp theo. `BOT1_TOKEN`, `ADMIN_ID`, `DATABASE_URL` không bao giờ được tự sinh.

Script dùng endpoint cập nhật **từng biến** của Render, không dùng PUT thay thế toàn bộ danh sách environment variables. Vì vậy biến khác không nằm trong manifest được giữ nguyên. Các biến do Render Blueprint/Environment Group quản lý có thể cần chỉnh tại đúng nguồn quản lý; riêng `DATABASE_URL` được bỏ qua có chủ đích. Nếu `BOT2_TOKEN` hoặc `TELEGRAM_SECOND_WEBHOOK_SECRET` không còn được cung cấp, manifest đánh dấu `delete_on_empty` để xóa đúng key trực tiếp khỏi service Render, không ảnh hưởng các key khác. API per-key không áp dụng cho biến nằm trong linked Environment Group.

### Giới hạn của các tích hợp ngoài

- Mini Game OAuth cần tên nhà cung cấp, authorization URL, token URL, profile URL, scope và client credentials hợp lệ.
- Bank adapter production cần tài liệu chữ ký callback của ngân hàng/đơn vị trung gian (header, thuật toán, timestamp, payload canonicalization và cơ chế idempotency).
- App Store Connect, Google Play và Microsoft Store cần quyền nhà phát hành, issuer/key hoặc service account, package/bundle ID và cấu hình upload theo từng store.
- Ghép đôi hiện là queue FIFO; Elo/rating, kết quả có xác nhận và cơ chế thưởng chưa được kích hoạt.

Không tự bật login, nạp tiền thật hoặc publish store chỉ bằng cách điền placeholder. Những API/credential bên ngoài chưa được cung cấp phải giữ trạng thái disabled đến khi có cấu hình chính thức và bộ kiểm thử sandbox.

## Lưu ý nghiệp vụ và bảo mật

- Liên kết Mini Game Live hiện chỉ lưu thương hiệu và username do thành viên nhập; không tự đăng nhập vào dịch vụ bên thứ ba.
- Ghép đôi hiện là hàng chờ kết nối hai thành viên; không phải engine game trực tiếp, hệ thống điểm hoặc giải thưởng game.
- VietQR tạo mã thanh toán và backend nhận callback ngân hàng qua secret. Cần cấu hình nhà cung cấp webhook/bank adapter thực tế để gửi dữ liệu đã xác thực đến POST /webhook/bank; không coi nội dung chuyển khoản do người dùng tự gửi là bằng chứng nạp tiền.
- Các token bot và webhook secrets không hiển thị hoặc lưu trong Dashboard. Chúng được quản lý tại Render Environment.
- Migration chỉ tạo bảng/index/setting còn thiếu. Trước khi deploy với dữ liệu hiện hữu, hãy backup PostgreSQL và thử trên staging.
- Thay đổi code không tự chuyển dữ liệu đang ở Cloudflare D1 hoặc database khác. Hãy export, mapping, import, đối soát số dư/ledger/đơn hàng/referral trước khi ngừng nguồn dữ liệu cũ.
