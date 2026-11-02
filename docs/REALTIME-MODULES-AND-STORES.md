# Realtime, module lifecycle và cửa hàng ứng dụng

## WebSocket
Backend realtime là PHP Ratchet chạy như một Render Web Service riêng từ cùng Docker image. Dashboard lấy ticket ngắn hạn qua API Telegram-authenticated rồi xác thực qua thông điệp WebSocket; không đưa BOT token hoặc initData vào URL. Ticket được hash trong PostgreSQL, hết hạn sau 60 giây và chỉ dùng một lần. Service realtime cũng phải được cấu hình ALLOWED_ORIGINS bằng origin HTTPS của service API (không phải URL WebSocket) để kiểm tra Origin. Event lưu bền trong PostgreSQL và worker phát mỗi giây. Render cung cấp TLS termination để client dùng WSS. Khi scale nhiều instance, chuyển sang PostgreSQL LISTEN/NOTIFY hoặc Redis pub/sub.

## Vòng đời module/tệp
Upload -> vùng cách ly -> kiểm tra MIME/kích thước -> pending_review -> code review -> CI và phát hành có ký -> deploy -> module.activated event. Upload chỉ nhận JSON, Markdown, TXT, CSS, HTML và ảnh tối đa 2 MB. Từ chối PHP/JS thực thi, shell, archive và secret. Không giải nén archive không tin cậy. Không tự ghi đè mã production từ trình duyệt; thay đổi code phải qua PR và CI.

## App Store, Google Play, Microsoft Store
Các adapter khai báo cho App Store Connect, Google Play Developer API và Microsoft Store submission API. Đây là điểm mở rộng, chưa phải kết nối tài khoản nhà phát hành. Cần developer account, API permissions, key/certificate, bundle/package/product ID và metadata riêng. Secret nằm trong secret manager. Capability mặc định chỉ đọc metadata; upload binary, submit review và publish đều tắt cho tới khi adapter tương ứng được triển khai và kiểm thử theo API/chính sách hiện hành.


The main API service uses a persistent Render disk mounted at /var/www/app/storage for staged module files. Keep the disk attached when changing plans or replacing services; it is not a substitute for off-site backups.
