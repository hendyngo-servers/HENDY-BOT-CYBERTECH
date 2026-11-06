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

## Menu Telegram và đồng bộ username

Menu cố định dùng Reply Keyboard hai cột theo ảnh mẫu: MiniGame/OTP & Proxy; Nạp tiền/Trung tâm VIP; Mua Lì Xì - Code Màn - Code Tele; Trung tâm khách hàng; Nhóm chat/Kênh thông báo; VIP dịch vụ sắp triển khai. Các nút đều có action định danh; nhóm và kênh sẽ mở liên kết đã cấu hình, còn nút VIP sắp triển khai chỉ hiển thị thông báo và nút quay lại.

Không cần khai báo BOT_USERNAME, BOT1_USERNAME, BOT2_USERNAME hoặc DISPLAY_NAME. Khi Render khởi động, backend gọi Telegram getMe() bằng BOT1_TOKEN (và BOT2_TOKEN nếu được cấu hình), lưu username cùng fingerprint SHA-256 của token vào PostgreSQL. Khi token đổi, username cache cũ bị xem là không hợp lệ và lần khởi động sẽ lấy username mới. Token gốc không được lưu trong bảng danh tính.

## Chạy local

Yêu cầu PHP 8.3+, Composer, PostgreSQL và extensions curl, mbstring, pdo_pgsql.

    cp .env.example .env
    composer install
    php bin/validate-config.php
    php bin/migrate.php
    php -S 127.0.0.1:8080 -t public

Điền .env bằng credential riêng của bạn. BOT1_TOKEN, ADMIN_ID/ADMIN_IDS, DATABASE_URL và TELEGRAM_WEBHOOK_SECRET là các giá trị cần thiết cho triển khai webhook. Có thể để BOT2_TOKEN trống nếu không chạy bot thử nghiệm.

Tạo secret ngẫu nhiên:

    php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"

Mỗi bot phải có token và webhook secret riêng. Không sử dụng cùng một secret cho hai endpoint.

## Triển khai Render

1. Kết nối repository này với Render và chọn Blueprint từ render.yaml.
2. Tạo PostgreSQL cùng region với Web Service để có thể dùng Internal Database URL.
3. Cấu hình Environment: BOT1_TOKEN, ADMIN_ID hoặc ADMIN_IDS, APP_URL (URL HTTPS công khai), ALLOWED_ORIGINS và các thông tin thanh toán/CSKH cần dùng.
4. Nếu bật bot thử nghiệm, thêm BOT2_TOKEN và TELEGRAM_SECOND_WEBHOOK_SECRET. Nếu chỉ chạy một bot, để BOT2_TOKEN trống.
5. Giữ BANK_WEBHOOK_ENABLED=false cho đến khi đã triển khai adapter xác minh chữ ký đúng theo nhà cung cấp ngân hàng.
6. Deploy. Container sẽ kiểm tra cấu hình, chờ PostgreSQL, chạy migration và đăng ký webhook nếu WEBHOOK_AUTO_REGISTER=true.

Với Render PostgreSQL nội bộ, đặt DATABASE_SSL=false nếu connection string nội bộ của Render yêu cầu. Với Neon hoặc database ngoài, bật TLS (DATABASE_SSL=true) và dùng URL do nhà cung cấp cấp.

## Các tuyến chính

| Mục đích | Đường dẫn |
|---|---|
| Website / Mini App | / |
| Dashboard quản trị | /admin |
| Trung tâm khách hàng | /KHACH-HANG/TRUNG-TAM-KHACH-HANG/index.php |
| Health check | /healthz |
| Webhook bot chính | /webhook/telegram |
| Webhook bot thử nghiệm | /webhook/telegram/secondary |
| Video provider callback | /webhook/video |
| Bank callback | /webhook/bank (đang khóa mặc định) |

## Kiểm tra trước khi deploy

    composer lint
    composer test
    php scripts/audit-env.php --check --self-test
    php scripts/secret-scan.php
    php bin/validate-config.php

GitHub Actions kiểm tra cú pháp PHP, test logic, quét credential bị commit và xác minh cấu hình build. Không đưa credential thật vào biến môi trường CI của pull request từ fork.

## Bảo mật và vận hành

- Token Telegram chỉ được đọc từ environment; .env bị ignore.
- Telegram webhook kiểm tra X-Telegram-Bot-Api-Secret-Token bằng so sánh hằng thời gian và giới hạn kích thước payload.
- Telegram Mini App dùng chữ ký HMAC của Telegram; admin được xác định từ allowlist ADMIN_ID/ADMIN_IDS phía máy chủ.
- SQL dùng PDO prepared statements; thay đổi số dư cần transaction và idempotency key phù hợp.
- Bank callback vẫn bị khóa nếu chưa có xác minh chữ ký đặc thù nhà cung cấp.
- Module upload chỉ đưa file vào vùng chờ kiểm duyệt, không tự thực thi.
- Không expose thư mục storage, file môi trường, file SQL hoặc log qua web root.

### Nếu token từng bị commit

1. Mở @BotFather, thu hồi token cũ và tạo token mới cho từng bot.
2. Cập nhật BOT1_TOKEN/BOT2_TOKEN trong Render Environment và GitHub Secrets (nếu workflow cần).
3. Tạo lại hai webhook secret và cập nhật tương ứng.
4. Xem xét làm sạch lịch sử Git bằng git-filter-repo/BFG sau khi phối hợp với mọi collaborator; force-push có thể ảnh hưởng các clone đang tồn tại.
5. Kiểm tra lại repo và log; không coi việc xóa token khỏi file mới là đã xóa khỏi Git history.

## Phát triển

Giữ các thay đổi schema tương thích bằng CREATE TABLE IF NOT EXISTS / ALTER TABLE ADD COLUMN IF NOT EXISTS hoặc migration có phiên bản. Không sửa dữ liệu production trực tiếp khi chưa có backup. Hãy kiểm tra docs/ trước khi thay đổi hợp đồng API của Mini App hoặc dashboard.
