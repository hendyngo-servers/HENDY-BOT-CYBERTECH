# HENDY BOT CYBERTECH

Nền tảng Telegram Bot bằng PHP 8.3, PostgreSQL và giao diện web quản trị. Mã nguồn duy trì hai cấu hình bot độc lập: **BOT1** cho vận hành chính và **BOT2** tùy chọn cho thử nghiệm.

## Thành phần

- Telegram webhook cho từng bot, menu lưới/reply keyboard và inline keyboard.
- Mini App/website, xác thực Telegram Web App initData, trung tâm khách hàng và Dashboard Admin.
- Ví, lịch sử giao dịch, yêu cầu nạp, sản phẩm/đơn hàng, mã giảm giá, VIP, giới thiệu và ticket CSKH.
- Các khu vực dịch vụ Mini Game, Tool, Code, OTP, Proxy và VIP.
- Vietsub/video job worker, callback HMAC và WebSocket realtime (Render Blueprint có thể bật; đặt WEBSOCKET_ENABLED=false nếu không sử dụng).
- PostgreSQL schema/migration, Docker và Render Blueprint.

> Không có token mẫu được lưu trong repository. Tất cả credential phải được cấu hình bằng Render Environment hoặc GitHub Secrets; không nhập chúng vào source code, log, issue hoặc commit.

## Menu Telegram và liên kết bot công khai

Menu cố định dùng Reply Keyboard hai cột theo ảnh mẫu: MiniGame/OTP & Proxy; Nạp tiền/Trung tâm VIP; Mua Lì Xì - Code Màn - Code Tele; Trung tâm khách hàng; Nhóm chat/Kênh thông báo; VIP dịch vụ sắp triển khai. Các nút đều có action định danh; nhóm và kênh sẽ mở liên kết đã cấu hình, còn nút VIP sắp triển khai chỉ hiển thị thông báo và nút quay lại.

Liên kết Telegram công khai được khai báo tường minh bằng `BOT1_PUBLIC_URL` và tùy chọn `BOT2_PUBLIC_URL`, ví dụ `https://t.me/your_bot_username`. Backend chỉ chấp nhận URL HTTPS thuộc `t.me`/`telegram.me`; không suy đoán username, không gọi Telegram `getMe()` để tạo URL và không gửi Bot Token ra trình duyệt. Nếu chưa khai báo URL, website hiển thị hướng dẫn liên hệ CSKH thay vì thông báo đồng bộ giả.

## URL công khai tự động trên Render

Với Render Web Service, nền tảng tự cấp `RENDER_EXTERNAL_URL`. Có thể để trống `APP_URL` để backend tự dùng URL đó cho Telegram webhook và Mini App; không cần đoán hoặc nhập hostname. Chỉ đặt `APP_URL` khi muốn dùng custom domain HTTPS. Không khai báo URL service khác (ví dụ service thử nghiệm) làm URL chính.

`.env.example` chỉ chứa placeholder rỗng. Không điền token, mật khẩu cơ sở dữ liệu, Telegram admin ID hoặc secret vào file mẫu/commit. Production credential chỉ cấu hình tại Render Environment.

## Chạy local

Yêu cầu PHP 8.3+, Composer, PostgreSQL và extensions curl, mbstring, pdo_pgsql.

    cp .env.example .env
    composer install
    php bin/validate-config.php
    php bin/migrate.php
    php -S 127.0.0.1:8080 -t public

Điền .env bằng credential riêng của bạn. BOT1_TOKEN, ADMIN_ID/ADMIN_IDS và DATABASE_URL là các giá trị cần thiết. `TELEGRAM_WEBHOOK_SECRET` và `TELEGRAM_SECOND_WEBHOOK_SECRET` là override tùy chọn; để trống thì backend tự tạo khóa ngẫu nhiên 256-bit riêng cho từng bot, lưu trong bảng riêng ở PostgreSQL. Giá trị thô không được đưa vào API hoặc log. Khi fingerprint của một bot token thay đổi, backend tự tạo secret mới cho bot đó và đăng ký lại Telegram webhook khi khởi động.

Tạo secret ngẫu nhiên:

    php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"

Mỗi bot phải có token và webhook secret riêng. Secret tự sinh được lưu bền vững để dùng lại sau restart; việc xoay vòng BOT1 không thay đổi secret BOT2. Có thể cấu hình override thủ công trong Render Environment nhưng không bắt buộc.

## Triển khai Render

1. Kết nối repository này với Render Web Service `HENDY-BOT-CYBERTECH`, nhánh `main`, vùng Singapore.
2. Giữ `DATABASE_URL` trỏ tới PostgreSQL riêng của dự án; không dùng database của ứng dụng khác. Đặt `DATABASE_SSL=true` cho Neon/database ngoài hoặc `false` chỉ khi dùng Internal URL của Render.
3. Cấu hình `BOT1_TOKEN`, `ADMIN_ID` hoặc `ADMIN_IDS`; nhập `BOT1_PUBLIC_URL=https://t.me/ten_bot_chinh` trong Render Environment. Để `APP_URL` trống để tự dùng `RENDER_EXTERNAL_URL`, hoặc khai báo custom domain HTTPS.
4. Nếu bật bot thử nghiệm, thêm `BOT2_TOKEN` và `BOT2_PUBLIC_URL=https://t.me/ten_bot_thu_nghiem`; webhook secret có thể để trống vì backend tự tạo, lưu và quản lý khóa ngẫu nhiên riêng cho từng bot.
5. Giữ `BANK_WEBHOOK_ENABLED=false` đến khi tích hợp adapter xác minh chữ ký của ngân hàng.
6. Deploy. Container kiểm tra cấu hình, chờ PostgreSQL, chạy migration và đăng ký webhook nếu `WEBHOOK_AUTO_REGISTER=true`; không lấy username bằng `getMe()`.

Với Render PostgreSQL nội bộ, đặt DATABASE_SSL=false nếu connection string nội bộ của Render yêu cầu. Với Neon hoặc database ngoài, bật TLS (DATABASE_SSL=true) và dùng URL do nhà cung cấp cấp.

## Website, Mini App và API

Trang chủ công khai được tách khỏi ứng dụng thành viên và Admin. Giao diện giới thiệu dùng CSS/JS tự host trong `public/assets/`, không phụ thuộc Tailwind CDN, Font Awesome CDN hoặc Google Fonts CDN. `/customer` tiếp tục phục vụ Trung tâm khách hàng; `/admin` dùng Dashboard Pro mới. Liên kết bot được khai báo thủ công bằng `BOT1_PUBLIC_URL`/`BOT2_PUBLIC_URL`; trình duyệt không nhận Bot Token.

### Trải nghiệm Telegram Mini App

- Trung tâm khách hàng và Dashboard Admin nạp Telegram WebApp SDK, gọi `ready()`/`expand()` và gửi `initData` nguyên bản lên backend để xác minh chữ ký HMAC; không dùng `initDataUnsafe` làm dữ liệu xác thực.
- Giao diện đọc `themeParams`, `safeAreaInset`, `contentSafeAreaInset` và `viewportStableHeight` để thích ứng theme, thiết bị và vùng an toàn; cập nhật khi Telegram phát sự kiện thay đổi theme/viewport.
- Trung tâm khách hàng dùng `BackButton`, `MainButton` (tạo yêu cầu VietQR ở màn nạp tiền) và `HapticFeedback` cho thao tác, trạng thái thành công/thất bại. Trình duyệt ngoài Telegram vẫn có điều hướng web dự phòng.
- Dashboard Admin dùng cùng SDK cho theme, safe-area, BackButton và phản hồi xúc giác. Xác thực Telegram không thay thế quyền quản trị: mọi API `/api/admin/*` vẫn gọi kiểm tra allowlist Admin tại backend.

### Dashboard Admin — các phân hệ hiện có

| Phân hệ | Hành động chính |
|---|---|
| Tổng quan | Đọc thống kê hệ thống và ví |
| Thành viên | Tìm kiếm hồ sơ, điều chỉnh số dư theo sổ cái |
| Đơn hàng | Xem, cập nhật trạng thái và ghi chú |
| Duyệt nạp | Duyệt/từ chối yêu cầu nạp; ghi nhận giao dịch idempotent |
| Sản phẩm | Tạo, sửa, ẩn/hiện và quản lý tồn kho |
| Ticket CSKH | Xem, phản hồi, đổi trạng thái |
| Voucher 72H | Duyệt/từ chối yêu cầu và phần thưởng |
| Giftcode | Tạo và khóa/mở mã |
| Menu Bot | Quản lý Reply/Inline Keyboard riêng từng bot |
| Runtime | Xem trạng thái cấu hình công khai/an toàn, không đọc secret |
| Tệp & Module | Tải tệp vào vùng cách ly để kiểm duyệt |
| Video Jobs | Xem trạng thái và đưa job lỗi trở lại hàng chờ |
| Cấu hình chung | Cập nhật các thiết lập nghiệp vụ được allowlist |
| Nhật ký | Xem audit log |

Các màn hình nạp, đơn hàng, người dùng, menu, sản phẩm, CSKH và hệ thống dùng dữ liệu PostgreSQL/API hiện có; giao diện không dùng số liệu demo. Quyền Admin được kiểm tra phía máy chủ cho từng yêu cầu.

Trang chủ công khai được tách khỏi ứng dụng thành viên và Admin. Giao diện giới thiệu dùng CSS/JS tự host trong `public/assets/`, không phụ thuộc Tailwind CDN, Font Awesome CDN hoặc Google Fonts CDN. `/customer` tiếp tục phục vụ Trung tâm khách hàng; `/admin` dùng Dashboard Pro mới. Liên kết bot được khai báo thủ công bằng `BOT1_PUBLIC_URL`/`BOT2_PUBLIC_URL`; trình duyệt không nhận Bot Token.

| Mục đích | Đường dẫn |
|---|---|
| Trang chủ giới thiệu | `/` |
| Dashboard quản trị (xác thực Admin ở backend) | `/admin` |
| Trung tâm khách hàng | `/customer` |
| Telegram Mini App | `/miniapp` |
| API discovery | `/api/v1` |
| Cấu hình công khai an toàn | `/api/v1/public/config` |
| Health check | `/health` hoặc `/healthz` |
| Chính sách bảo mật | `/privacy` |
| Điều khoản sử dụng | `/terms` |
| Webhook bot chính | `/webhook/telegram` |
| Webhook bot thử nghiệm | `/webhook/telegram/secondary` |
| Video provider callback | `/webhook/video` |
| Bank callback (mặc định khóa) | `/webhook/bank` |

Liên kết bot chính/phụ đọc từ `BOT1_PUBLIC_URL` và `BOT2_PUBLIC_URL`; cộng đồng, kênh và hỗ trợ đọc từ `GROUP_URL`, `CHANNEL_URL`, `SUPPORT_URL`. Backend chỉ công khai URL HTTPS thuộc Telegram (`t.me`/`telegram.me`); URL khác bị loại bỏ. Khai báo các biến này tại Render Environment.

## Kiểm tra trước khi deploy

    composer lint
    composer test
    php scripts/audit-env.php --check --self-test
    php scripts/secret-scan.php
    php bin/validate-config.php

GitHub Actions kiểm tra cú pháp PHP, test logic, quét credential bị commit và xác minh cấu hình build. Không đưa credential thật vào biến môi trường CI của pull request từ fork.

## Bảo mật và vận hành

- Token Telegram chỉ được đọc từ environment; .env bị ignore.
- Telegram webhook kiểm tra X-Telegram-Bot-Api-Secret-Token bằng so sánh hằng thời gian và giới hạn kích thước payload. Mỗi bot có secret ngẫu nhiên 256-bit được lưu trong PostgreSQL; fingerprint token thay đổi sẽ tự xoay vòng và đăng ký lại webhook.
- Telegram Mini App dùng chữ ký HMAC của Telegram; admin được xác định từ allowlist ADMIN_ID/ADMIN_IDS phía máy chủ.
- SQL dùng PDO prepared statements; thay đổi số dư cần transaction và idempotency key phù hợp.
- Bank callback vẫn bị khóa nếu chưa có xác minh chữ ký đặc thù nhà cung cấp.
- Module upload chỉ đưa file vào vùng chờ kiểm duyệt, không tự thực thi.
- Không expose thư mục storage, file môi trường, file SQL hoặc log qua web root.

### Nếu token từng bị commit

1. Mở @BotFather, thu hồi token cũ và tạo token mới cho từng bot.
2. Cập nhật BOT1_TOKEN/BOT2_TOKEN trong Render Environment và GitHub Secrets (nếu workflow cần).
3. Khi token bot bị lộ hoặc thay đổi, xoay vòng token trong BotFather và cập nhật `BOT1_TOKEN`/`BOT2_TOKEN`. Ứng dụng tự tạo secret webhook ngẫu nhiên mới cho bot tương ứng và gọi `setWebhook`; không cần sao chép secret vào source code.
4. Xem xét làm sạch lịch sử Git bằng git-filter-repo/BFG sau khi phối hợp với mọi collaborator; force-push có thể ảnh hưởng các clone đang tồn tại.
5. Kiểm tra lại repo và log; không coi việc xóa token khỏi file mới là đã xóa khỏi Git history.

## Phát triển

Giữ các thay đổi schema tương thích bằng CREATE TABLE IF NOT EXISTS / ALTER TABLE ADD COLUMN IF NOT EXISTS hoặc migration có phiên bản. Không sửa dữ liệu production trực tiếp khi chưa có backup. Hãy kiểm tra docs/ trước khi thay đổi hợp đồng API của Mini App hoặc dashboard.
