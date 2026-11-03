# Hendy Bot Tele — PHP Edition

Hệ thống Telegram Bot kép trong một mã nguồn, triển khai bằng **PHP 8.3 + Apache + PostgreSQL** trên Render. **BOT1/primary là bot chính @HendyBot_Bot**; **BOT2/secondary là bot thử nghiệm @hendymono_bot**. Hai bot có webhook, session, menu và danh tính riêng nhưng dùng chung backend/PostgreSQL; update, menu và pending input được phân vùng theo bot. Trang `/` là landing page HENDY, `/mini-app?bot=primary|secondary` dùng chung giao diện và xác thực theo token từng bot, còn `/admin` là Dashboard quản trị riêng.

> **Chỉ dùng hai biến Telegram Bot Token:** `BOT1_TOKEN` phải là token của **@HendyBot_Bot**; `BOT2_TOKEN` phải là token của **@hendymono_bot**. BOT2_TOKEN có thể để trống để chỉ chạy bot chính. ADMIN_ID là Telegram user ID dùng chung cho hai bot.

## Những tính năng được giữ lại

- Hai Telegram bot độc lập trong một ứng dụng PHP, cùng dùng một PostgreSQL database; `telegram_updates`, `bot_pending_inputs` và `bot_menu_configs` được phân vùng theo `primary`/`secondary` để tránh chồng lẫn trạng thái.
- Một source Mini App dùng chung, nhưng URL và xác thực tách riêng: `/mini-app?bot=primary` dùng BOT1_TOKEN; `/mini-app?bot=secondary` dùng BOT2_TOKEN. API gửi header `X-Telegram-Bot-Key` để backend xác thực bằng đúng bot token.
- `/start` chỉ gửi một tin giới thiệu HENDY kèm menu so le; menu theo cấu hình riêng từng bot.
- Reply Keyboard dạng lưới cố định phía dưới khung nhập và Inline Keyboard nằm dưới tin nhắn; từng bot có thể chọn loại menu, số cột, phần giới thiệu, tên/mô tả nút, màu semantic, resize và persistent.
- Dashboard tiếng Việt: tổng quan, sản phẩm, đơn hàng, thành viên, điều chỉnh số dư, ticket CSKH, giftcode, thiết lập VietQR/liên kết cộng đồng và cấu hình menu của hai bot.
- Màn hình `/start` giới thiệu `⩤ HENDY DIGITAL ECOSYSTEM ⩥`, lời chào theo giờ Việt Nam, phân biệt BOT 1/BOT 2, Telegram ID, Ví Chính, Ví NYC, Ví Voucher, tổng nạp, VIP, website và nhóm hỗ trợ; số dư lấy từ PostgreSQL.
- Mini App HENDY có layout so le, 7 màu, icon chuyển động CSS, hỗ trợ cài đặt giảm chuyển động; giao diện dành cho thành viên được phục vụ tại `/mini-app` và xác thực `initData` bằng token đúng bot.
- Web `/admin` chỉ dành cho Admin, cũng xác thực initData bằng bot token của luồng đang mở.
- Sản phẩm/dịch vụ: Mini Game Live, thuê tool, code, OTP, proxy, VIP.
- Trung tâm khách hàng chạy trực tiếp trong Telegram: Ví Chính, Ví NYC, Ví Voucher, tổng nạp, VIP, thống kê đơn hàng, lịch sử giao dịch, tài khoản liên kết, ticket CSKH và yêu cầu Voucher 72 giờ; không mở Mini App thành viên.
- Trung tâm VIP với thông tin các cấp, giao diện thẻ VIP PRO neon/gold, thanh tiến trình lên hạng, điểm danh mỗi ngày theo múi giờ Việt Nam, chống cộng điểm trùng và phần thưởng điểm nội bộ có thể cấu hình trong Admin; điểm không phải tiền.
- Kho liên kết tài khoản Mini Game dùng `linked_accounts`, hỗ trợ QQ88, HI88, 78WIN, RR88, MM88, GG88, ABCVIP và các thương hiệu được cấu hình; chỉ lưu username gắn với Telegram ID, tuyệt đối không lưu PIN/mật khẩu/OTP. Quy trình nhập liệu Telegram dùng `bot_pending_inputs` có TTL theo từng BOT1/BOT2; không tạo bảng session MySQL trùng lặp.
- OTP và Proxy hiển thị kho sản phẩm/tồn kho từ `products`; đơn mua lưu ở `orders`. OTP/Proxy trừ Ví NYC; Tool/Code và dịch vụ khác trừ Ví Chính. Có chuyển nội bộ Ví Chính sang Ví NYC, ghi kép `wallet_ledger`/`transactions`. Proxy Free yêu cầu VIP 1+ và phải có sản phẩm miễn phí do Admin tạo. Ticket Auto đặt lại/Trúng không phát chỉ ghi nhận yêu cầu CSKH, không tự sinh giao dịch hoặc tự đặt lại đơn.
- Ví, lịch sử giao dịch, VietQR, nhận callback ngân hàng qua secret riêng và idempotency theo mã giao dịch.
- Đơn hàng, trừ ví trong transaction, hoàn tiền khi Admin từ chối/hủy, bàn giao khi hoàn tất.
- Giftcode có số lượt, hạn dùng, khóa/mở và giới hạn mỗi thành viên một lần.
- Referral, phần trăm hoa hồng, ghi nhận thưởng khi nạp tiền.
- Ticket hỗ trợ, phản hồi từ Admin qua Telegram.
- Web Service Platform tách khỏi vòng đời Telegram: route allow-list, hợp đồng service, System Health/Capabilities và thư mục `app/WebServices/` để phát triển API nghiệp vụ khác. Không tự thực thi tệp upload hoặc tên class do client gửi.
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
    ├── apps/
    │   ├── mini-app/index.html
    │   ├── telegram-landing/index.html
    │   └── admin-dashboard/index.html
    ├── app/WebServiceContract.php
    ├── app/WebServiceKernel.php
    ├── app/WebServices/SystemWebService.php
    └── services/README.md
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

JavaScript trong `apps/mini-app/index.html` và `apps/admin-dashboard/index.html` chạy ở trình duyệt để dựng UI và gọi API. Mini App thành viên dùng CSS 7 màu, layout so le, hoạt ảnh icon (rocket, gem, gift, bell...) và tôn trọng `prefers-reduced-motion`. Hai bot dùng cùng file Mini App, nhưng token, botKey, menu và phiên xác thực tách riêng. Telegram keyboard không chạy CSS animation; chỉ nhận các style semantic được Telegram hỗ trợ, còn hiệu ứng nhiều màu/chuyển động được thể hiện trong Mini App.

## Cấu hình môi trường

Tạo file .env từ .env.example. Hai biến token bot duy nhất:

    BOT1_TOKEN=TOKEN_CUA_HendyBot_Bot
    BOT2_TOKEN=TOKEN_CUA_hendymono_bot
    ADMIN_ID=YOUR_TELEGRAM_NUMERIC_ADMIN_ID

    BOT1_USERNAME=HendyBot_Bot
    BOT2_USERNAME=hendymono_bot
    BOT1_DISPLAY_NAME=HENDY BOT CHÍNH
    BOT2_DISPLAY_NAME=HENDY BOT THỬ NGHIỆM

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
| BOT1_TOKEN | Có | Token của bot chính @HendyBot_Bot |
| BOT2_TOKEN | Không | Token bot thử nghiệm @hendymono_bot; để trống để tắt bot thử nghiệm |
| BOT1_USERNAME / BOT2_USERNAME | Không | Username hiển thị của bot chính / bot thử nghiệm |
| BOT1_DISPLAY_NAME / BOT2_DISPLAY_NAME | Không | Tên hiển thị trong lời chào của từng bot |
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

## Điều hướng Mini Game Live

Từ nút **🚀 MINIGAME LIVE**, luồng inline keyboard đi theo ba cấp: chọn nhóm nhà cái → chọn game → chọn gói hoặc kho ACC. Ba nhóm được cấu hình trong `app/BotService.php`: QQ88/HI88/78WIN; RR88/MM88/GG88; [ABCVIP]/XX8/U888/J88/ABC8/88CLB. Từng màn hình có nút quay lại cấp trước và trở về menu chính.

Gói RR88 mặc định hiển thị `RR88 MINI | 88 ĐIỂM - 9,000đ`. Các gói khác được lấy từ `products` có `category=minigame` và brand khớp trong `metadata.brand`, slug hoặc tên. Màn hình kho hiển thị số dư Ví Chính và số username của thành viên đã lưu trong `linked_accounts`; mở màn hình không tự mua gói hoặc trừ ví. Chỉ lưu username, không lưu mật khẩu, PIN, OTP hoặc mã xác minh. Dữ liệu ví và tài khoản thành viên đang dùng chung giữa hai bot; trạng thái hội thoại và menu vẫn tách theo bot.

Trước khi triển khai, gán đúng token trong Render:
- `BOT1_TOKEN` = token lấy từ BotFather của **@HendyBot_Bot**.
- `BOT2_TOKEN` = token lấy từ BotFather của **@hendymono_bot**.
- `BOT1_USERNAME` / `BOT2_USERNAME` và tên hiển thị cần đồng nhất như trên.

Không thể suy ra token từ username. Sau khi đổi vai trò, cập nhật hai secret trong Render Environment rồi redeploy; không commit token vào repo.

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

## Website chính thức và tích hợp URL

Website chính thức HENDY dùng `https://hendy-bot-tele.onrender.com`. Trang thành viên được tích hợp trực tiếp tại `/` và `/KHACH-HANG/TRUNG-TAM-KHACH-HANG/index.php`; không cần triển khai Mini App riêng. Đăng nhập từ Telegram dùng link một lần, hết hạn sau 5 phút, sau đó server cấp cookie `HttpOnly` và chuyển đến Trung tâm khách hàng. Tham số `token=` trống không được dùng làm thông tin xác thực.

## Chuyển dữ liệu MySQL cũ

Ứng dụng runtime hiện dùng PostgreSQL trên Render làm nguồn dữ liệu chính; không tạo MySQL `telegram_bot_db` thay thế. SQL `CREATE DATABASE ... CHARACTER SET utf8mb4` trong ví dụ cũ chỉ dành cho MySQL, không chạy trên PostgreSQL. Chỉ nhập dữ liệu MySQL legacy khi có file export `telegram_bot_db.sql(.gz)` và bản sao lưu PostgreSQL đầy đủ trước migration. Xem [runbook nhập MySQL sang PostgreSQL](docs/IMPORT-MYSQL-TO-POSTGRES.md) để export, backup, staging, dry-run và đối soát. Không tự cộng transactions vào số dư đã chuyển từ `main_wallet`.

## Trung tâm quản lý tài khoản ngay trong Telegram

Nút **🔐 QUẢN LÝ TÀI KHOẢN** hiện phục vụ thao tác thành viên trực tiếp bằng Telegram. Trang `/` không còn render Mini App khách hàng; trang `/admin` chỉ dành cho Admin.

Đăng nhập truyền thống qua ticket vẫn là luồng tương thích tùy chọn: bot sinh ticket ngẫu nhiên 256-bit, chỉ lưu SHA-256 trong PostgreSQL, hết hạn sau 5 phút và chỉ exchange được một lần. Ticket được đổi qua `POST /api/legacy/session/exchange` thành JWT HS256 có audience `hendy-legacy-account`, thời hạn 5 phút. Secret ký JWT cần `LEGACY_SESSION_SECRET` (tối thiểu 32 ký tự). Nếu không sử dụng cổng đăng nhập truyền thống, không cần bật luồng này. Không nhập mật khẩu hoặc OTP bên thứ ba vào Telegram.

### Kho tài khoản ABCVIP

Menu có nút **🏦 KHO TÀI KHOẢN ABCVIP**. Admin có thể khai báo `ABCVIP_CONNECT_URL` trong **Quản trị → Cấu hình**; URL phải là HTTPS và phải do nhà cung cấp ABCVIP chính thức cấp, có hỗ trợ OAuth/SSO hoặc callback xác thực. Nếu chưa cấu hình, bot hiển thị trạng thái tạm khóa và không thu thập mật khẩu/OTP/PIN trong chat. Chỉ có URL trang đăng nhập thông thường không đủ để xác minh tài khoản đã liên kết; cần tài liệu API/OAuth và callback chính thức của nhà cung cấp trước khi bật luồng xác thực thực tế.

## Tích hợp Telegram Bot với Dashboard Admin

Telegram BOT1 và BOT2 sử dụng cùng backend PHP/PostgreSQL và cùng nguồn dữ liệu nghiệp vụ. Các thao tác thành viên chạy trực tiếp bằng Reply Keyboard/Inline Keyboard. Mỗi bot có cấu hình menu riêng trong `bot_menu_configs`; trang `/admin` có thể đổi Reply Keyboard/Inline Keyboard, số cột, nội dung giới thiệu, nhãn và mô tả. Telegram Bot API không hỗ trợ màu tùy chỉnh cho từng nút; các giá trị style trong Dashboard chỉ là metadata giao diện, không được gửi như thuộc tính không hợp lệ tới Telegram.

### Lệnh Telegram

| Lệnh | Tác vụ |
|---|---|
| `/start`, `/menu` | Đăng ký/cập nhật thành viên và mở menu chính |
| `/balance` | Xem số dư ví |
| `/deposit [số tiền]` | Tạo yêu cầu nạp và mã QR VietQR |
| `/history` | Xem biến động ví, yêu cầu nạp và video jobs |
| `/vietsub` | Gửi URL video HTTPS vào hàng đợi Vietsub |
| `/jobs` | Xem trạng thái video jobs của tài khoản |
| `/orders` | Xem đơn hàng và bàn giao |
| `/admin` | Mở tổng quan quản trị |
| `/admin_payments` | Liệt kê yêu cầu nạp chờ duyệt (Admin) |
| `/admin_jobs` | Xem video jobs và đưa job lỗi về hàng chờ (Admin) |
| `/addmoney <telegram_id> <amount>` | Điều chỉnh số dư thủ công, ghi ledger và audit (Admin) |

Bot hỗ trợ một hoặc nhiều Admin: đặt `ADMIN_ID` và/hoặc `ADMIN_IDS` (Telegram ID dạng số, phân tách bằng dấu phẩy). Dùng ID được cấp bởi Telegram; không dùng username thay cho ID. Bot thứ hai cần `BOT2_TOKEN` và webhook secret riêng.

### Nạp tiền và đối soát

1. Thành viên chọn NẠP TIỀN hoặc gửi `/deposit 50000`.
2. Backend kiểm tra cấu hình VietQR và tạo bản ghi `payment_requests` với mã HD riêng cùng nội dung chuyển khoản duy nhất.
3. Bot gửi QR/thông tin chuyển khoản cho thành viên và thông báo tất cả Admin đã cấu hình.
4. Admin mở `/admin_payments` hoặc Dashboard → **Duyệt nạp**. Chỉ sau khi đối chiếu sao kê ngân hàng, Admin mới chọn Duyệt/Từ chối.
5. Duyệt ghi một ledger reference duy nhất `payment:<payment_id>`, cập nhật balance/total_deposited/VIP và thông báo thành viên. Unique reference + transaction giúp chặn cộng ví hai lần. Từ chối không đổi số dư.

Tạo QR/yêu cầu nạp không phải bằng chứng ngân hàng đã nhận tiền. `payment_requests` lưu snapshot ngân hàng, số tài khoản, chủ tài khoản, nội dung chuyển khoản và trạng thái tại thời điểm tạo; lịch sử Telegram tra cứu lại đúng snapshot đó. `VIETQR_BANK_ID`, `VIETQR_ACCOUNT_NO`, `VIETQR_ACCOUNT_NAME` được lưu private (`is_public=false`), không trả về bootstrap công khai. Giao dịch thực ghi vào `transactions` và `wallet_ledger` kèm `wallet_type`. Không cộng ví từ ảnh chụp màn hình hoặc nội dung người dùng tự khai. `/webhook/bank` vẫn trả HTTP 503 vì chưa có adapter chữ ký callback theo nhà cung cấp; không bật `BANK_WEBHOOK_ENABLED` trong bản hiện tại.

### Vietsub và video jobs

- `/vietsub` hoặc nút DỊCH VỤ VIETSUB ghi job vào bảng PostgreSQL `video_jobs` với trạng thái `queued`.
- Link đầu vào phải HTTPS và host nằm trong `MEDIA_ALLOWED_HOSTS`. Mặc định cho phép YouTube, TikTok, Facebook và fb.watch; có thể điều chỉnh allowlist.
- Thành viên xem tiến độ bằng `/jobs`; Admin dùng `/admin_jobs` hoặc Dashboard → **Video Jobs**.
- Job lỗi có thể được Admin đưa lại vào hàng chờ. Giới hạn mặc định: tối đa 3 job đang hoạt động và 10 job mới mỗi giờ cho một user.
- Có CLI worker `php bin/video-worker.php` (hoặc `composer video-worker`). Worker chỉ dispatch khi đã cấu hình một nhà xử lý video thực tế và callback HTTPS. Nếu thiếu cấu hình, worker fail-fast; jobs vẫn được giữ trong PostgreSQL, không tự đánh dấu hoàn tất.

**Hợp đồng tích hợp nhà xử lý video:** cấu hình `VIDEO_PROCESSOR_URL` (HTTPS) và `VIDEO_PROCESSOR_SECRET` (tối thiểu 32 ký tự) ở Render Environment. Worker gửi POST JSON gồm `jobId`, `telegramId`, `sourceUrl`, `callbackUrl`; header `X-Hendy-Video-Signature: sha256=<HMAC-SHA256 của raw request body>`. Nhà xử lý gọi callback `POST /webhook/video` với cùng header và payload JSON: `jobId`, `status` (`processing`, `completed`, `failed`), `progress`, `outputUrl`, `error`, `providerJobId`. Callback hoàn tất phải cung cấp `outputUrl` HTTPS. Backend xác minh chữ ký HMAC trước khi cập nhật job; khi completed/failed sẽ gửi thông báo Telegram cho thành viên.

Nếu worker/container bị restart khi job ở trạng thái `processing`, bộ recovery sẽ đưa job quá hạn (mặc định 90 phút, chỉnh bằng `VIDEO_PROCESSING_STALE_MINUTES`) trở lại hàng chờ tối đa 2 lần; sau 3 lần dispatch mà vẫn timeout thì job thành `failed`. Admin retry sẽ reset bộ đếm. Nhà xử lý cần dùng UUID `jobId` làm idempotency key để tránh xử lý trùng khi worker gửi lại.

Repository chưa được cung cấp một nhà cung cấp phụ đề, API endpoint hay credential thực tế. Vì vậy worker và callback contract đã có trong source, nhưng chức năng tải video/chuyển giọng/tạo phụ đề thực tế **chưa thể chạy** cho đến khi kết nối provider hợp lệ. Có thể chạy worker bằng Render Background Worker với Start Command `php bin/video-worker.php`; tạo thêm Background Worker có thể phát sinh chi phí Render. Không bật service worker khi chưa có provider.

### Phát triển Web Service độc lập

Backend có một lớp điều phối Web Service mở rộng:

- `app/WebServiceContract.php`: hợp đồng xử lý HTTP cho service.
- `app/WebServiceKernel.php`: router allow-list; không cho phép client chọn tên class hoặc chạy file upload.
- `app/WebServices/SystemWebService.php`: service mẫu an toàn cung cấp health/capabilities.
- `services/README.md`: hướng dẫn tạo service mới.

Endpoint có sẵn (đều yêu cầu Telegram Mini App `initData` hợp lệ và `X-Telegram-Bot-Key`):
- `GET /api/services/system/health`
- `GET /api/services/system/capabilities`

Để bổ sung service, tạo class PHP trong `app/WebServices/`, implement `WebServiceContract`, validate input/quyền, viết test, đăng ký key trong allow-list `WebServiceKernel::SERVICES`, sau đó chạy CI và deploy. Web Service không phụ thuộc vòng đời polling/webhook bot nên có thể phục vụ API hoặc tiện ích web khác nhưng vẫn dùng chung PostgreSQL và quyền truy cập tập trung.
## Trung tâm khách hàng HENDY

- Mở trực tiếp tại `/KHACH-HANG/TRUNG-TAM-KHACH-HANG/index.php?bot=primary&view=customer` hoặc `?bot=secondary&view=customer`.
- Hai bot tạo link đăng nhập website bằng `/start web_login` hoặc nút **MỞ WEBSITE · TRUNG TÂM KHÁCH HÀNG** trong menu khách hàng. Ticket ngẫu nhiên chỉ dùng một lần, hết hạn sau 5 phút và được đổi lấy cookie `HttpOnly; Secure; SameSite=Lax`.
- Website đọc hồ sơ, ba loại ví, VIP, điểm cũ, thống kê đơn, danh sách tài khoản đã liên kết, yêu cầu nạp, lịch sử giao dịch và đơn hàng từ PostgreSQL qua API đã xác thực.
- Các khu vực hỗ trợ gồm Dính lạm dụng (60%), Ticket CSKH và Voucher 72H. Voucher chỉ tạo yêu cầu; tiền không được cộng cho tới khi Admin đối soát và duyệt.
- Replay Live là mục tùy chọn. Chỉ mở liên kết khi Admin cấu hình `replay_live_url` bằng URL HTTPS của nguồn replay thực tế; khi chưa có nguồn thì giao diện báo rõ chưa được kết nối.

## API mới

- `GET /api/wallet/payment-requests`, `POST /api/wallet/payment-requests`: xem/tạo yêu cầu nạp dùng chung với Telegram.
- `GET /api/admin/payments`, `POST /api/admin/payments/{paymentId}/approve|reject`: admin đối soát yêu cầu nạp.
- `GET /api/admin/video-jobs`, `POST /api/admin/video-jobs/{uuid}/retry`: quản lý hàng đợi video.
- `POST /webhook/video`: callback có xác minh HMAC từ video processor.

Các API quản trị yêu cầu Telegram WebApp initData hợp lệ và tài khoản nằm trong `ADMIN_ID` hoặc `ADMIN_IDS`.

## Realtime, module và kho ứng dụng

Dashboard Admin có tab Tệp & Module. Tệp tải lên (JSON/MD/TXT/CSS/HTML/ảnh, tối đa 2 MB) được lưu ở vùng cách ly riêng trong persistent disk, kiểm tra MIME và SHA-256, trạng thái ban đầu pending_review; không có chức năng thực thi code hoặc tự ghi đè production. Mọi thay đổi mã nguồn phải qua Pull Request và CI.

Realtime dùng PHP Ratchet trong service Render riêng. Sau khi Blueprint tạo service `hendy-bot-tele-realtime`, đặt `WEBSOCKET_URL` ở service API thành URL `wss://...` của service realtime.

Các adapter App Store Connect, Google Play và Microsoft Store mới là khung tích hợp. Chưa có credential/API permissions hoặc luồng upload/submit/publish hoạt động; cần thiết lập riêng theo tài khoản nhà phát hành. Xem `docs/REALTIME-MODULES-AND-STORES.md`.

## Endpoint chính

- GET /: Landing page giới thiệu HENDY mới.
- GET /mini-app?bot=primary|secondary: Mini App dùng chung giao diện, xác thực riêng theo bot.
- GET /healthz: Health check cho Render.
- GET /api/health: trạng thái database và cấu hình hai bot.
- POST /webhook/telegram: webhook BOT1.
- POST /webhook/telegram/secondary: webhook BOT2 (khi bật).
- POST /webhook/bank: callback ngân hàng (đang khóa an toàn, HTTP 503 cho đến khi có adapter chữ ký nhà cung cấp).
- POST /webhook/video: callback HMAC từ nhà xử lý video đã cấu hình.
- /api/bootstrap, /api/products, /api/me, /api/orders, /api/wallet, /api/wallet/payment-requests, /api/referrals, /api/giftcodes/redeem, /api/support/tickets, /api/vip.
- /api/admin/*: Dashboard quản trị; mọi yêu cầu phải có Telegram WebApp initData hợp lệ và thuộc `ADMIN_ID` hoặc `ADMIN_IDS`.
- GET /api/admin/payments và POST /api/admin/payments/{paymentId}/approve|reject: đối soát yêu cầu nạp.
- GET /api/admin/video-jobs và POST /api/admin/video-jobs/{uuid}/retry: quản lý hàng đợi Vietsub.
- POST /api/admin/realtime-ticket: cấp ticket WebSocket ngắn hạn.
- GET /api/services/system/health và GET /api/services/system/capabilities: Web Service API dùng chung, xác thực Telegram.
- GET /api/admin/modules và POST /api/admin/modules/upload: quản lý tệp chờ kiểm duyệt.

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
- Có thể đặt trước hoặc để workflow tự sinh: `TELEGRAM_WEBHOOK_SECRET`, `TELEGRAM_SECOND_WEBHOOK_SECRET`, `LEGACY_SESSION_SECRET`. Nếu secret của BOT2 bị trùng BOT1, chế độ apply tự tạo secret riêng và lưu lại. `BANK_WEBHOOK_SECRET` không tự sinh/đồng bộ khi chưa có adapter ngân hàng chính thức.
- Khi dùng VietQR: `VIETQR_ACCOUNT_NO`, `VIETQR_ACCOUNT_NAME`.
- Hạ tầng CI: `RENDER_API_KEY`, `GH_CONFIG_SYNC_TOKEN`.
- `DATABASE_URL` không được workflow ghi đè. Giữ biến này do Render PostgreSQL/Blueprint quản lý để tránh thay nhầm database.

**Environment Variables**
- Bắt buộc: `APP_URL`, `RENDER_SERVICE_ID` và ít nhất một trong `ADMIN_ID` hoặc `ADMIN_IDS`.
- Có thể thêm: `APP_ENV`, `PORT`, `WEBAPP_URL`, `ALLOWED_ORIGINS`, `BOT_USERNAME`, `DATABASE_SSL`, `DB_STARTUP_WAIT_SECONDS`, `RUN_MIGRATIONS_ON_START`, `WEBHOOK_AUTO_REGISTER`, `ADMIN_IDS`, `MEDIA_ALLOWED_HOSTS`, `VIDEO_PROCESSOR_URL`, `VIDEO_WORKER_POLL_SECONDS`, `BANK_WEBHOOK_ENABLED` (mặc định `false` và không được bật ở bản hiện tại), `VIETQR_BANK_ID`, `GROUP_URL`, `CHANNEL_URL`, `TELEGRAM_INIT_DATA_MAX_AGE_SECONDS`, `WEBSOCKET_URL`, `WEBSOCKET_BIND`, `WEBSOCKET_PORT`.

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
- Bank webhook hiện bị khóa an toàn (`BANK_WEBHOOK_ENABLED=false`; endpoint trả HTTP 503). Chưa có adapter chữ ký theo nhà cung cấp; shared secret đơn thuần không đủ để xác minh giao dịch nạp tiền. Chỉ bật sau khi triển khai/verifiy đúng chữ ký, timestamp, chống replay, idempotency và test sandbox của nhà cung cấp.
- App Store Connect, Google Play và Microsoft Store cần quyền nhà phát hành, issuer/key hoặc service account, package/bundle ID và cấu hình upload theo từng store.
- Chức năng GHÉP ĐÔI đã được gỡ khỏi menu, Mini App, API và Dashboard. Các bảng cũ được giữ lại trong PostgreSQL để không tự ý xóa dữ liệu lịch sử; không còn route/runtime nào sử dụng tính năng này.

Không tự bật login, nạp tiền thật hoặc publish store chỉ bằng cách điền placeholder. Những API/credential bên ngoài chưa được cung cấp phải giữ trạng thái disabled đến khi có cấu hình chính thức và bộ kiểm thử sandbox.

## Lưu ý nghiệp vụ và bảo mật

- Liên kết Mini Game Live hiện chỉ lưu thương hiệu và username do thành viên nhập; không tự đăng nhập vào dịch vụ bên thứ ba.
- BOT1 và BOT2 dùng chung PostgreSQL cho hồ sơ/ví/dịch vụ nhưng có danh tính Telegram/WebApp riêng. `bot_menu_configs` lưu tùy chỉnh menu và custom emoji ID riêng theo bot. Username bot có thể đặt bằng `BOT1_USERNAME` và `BOT2_USERNAME`.
- VietQR tạo yêu cầu có mã riêng và được Admin đối soát/duyệt thủ công qua Telegram hoặc Dashboard. Tạo QR không tự cộng ví. Endpoint POST /webhook/bank đang bị khóa và không ghi có ví. Chỉ bật nạp tự động sau khi adapter chữ ký callback theo ngân hàng được cài đặt và kiểm thử.
- Các token bot và webhook secrets không hiển thị hoặc lưu trong Dashboard. Chúng được quản lý tại Render Environment.
- Migration chỉ tạo bảng/index/setting còn thiếu. Trước khi deploy với dữ liệu hiện hữu, hãy backup PostgreSQL và thử trên staging.
- Thay đổi code không tự chuyển dữ liệu đang ở Cloudflare D1 hoặc database khác. Hãy export, mapping, import, đối soát số dư/ledger/đơn hàng/referral trước khi ngừng nguồn dữ liệu cũ.
