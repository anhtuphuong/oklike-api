# oklike.shop — Middleware API v2 (adapter sang SMM-TG)

## Kiến trúc

```
VieSMM --(POST form: key, action, ...)--> index.php
                                              |
                                              v
                                     SmmTgClient (X-API-Key, JSON)
                                              |
                                              v
                                     https://api.smm-tg.net
```

## File

| File | Vai trò |
|---|---|
| `config.php` | API key SMM-TG, DB config, danh sách `client_keys` (key của VieSMM), mapping `service id -> time_leave + markup_pct` |
| `Db.php` | Kết nối MySQL (PDO) — **tự tạo bảng `orders` nếu chưa tồn tại**, không cần chạy `schema.sql` thủ công |
| `SmmTgClient.php` | Wrapper gọi API SMM-TG, ném `SmmTgException` khi lỗi |
| `V2Mapper.php` | Dịch status/error/giá SMM-TG -> chuẩn v2 |
| `index.php` | Entry point — nhận request v2, xử lý từng `action` |
| `schema.sql` | (tham khảo) Schema bảng `orders` — không bắt buộc chạy, `Db.php` tự tạo |

## Cài đặt lên hosting

1. Upload toàn bộ các file `.php` (và `schema.sql` nếu muốn) lên 1 thư mục trên hosting, ví dụ `public_html/api/v2/`.
2. Tạo 1 database MySQL trống trên hosting (qua cPanel/DirectAdmin...). **Không cần import gì** — lần đầu gọi API, `Db.php` sẽ tự `CREATE TABLE IF NOT EXISTS orders`.
3. Sửa `config.php`:
   - `smm_tg.api_key`: API key SMM-TG cấp cho bạn.
   - `db`: host/user/pass/tên database MySQL trên hosting.
   - `client_keys`: API key bạn cấp cho VieSMM (key này VieSMM gửi trong field `key`).
   - `services`: mỗi service id (số) map sang 1 `time_leave` (4/30/60/90) cố định + `markup_pct`.
4. Trỏ `https://oklike.shop/api/v2` (hoặc URL bạn đặt) tới `index.php` — đa số hosting chỉ cần truy cập trực tiếp `https://oklike.shop/api/v2/index.php`, hoặc dùng `.htaccess` rewrite nếu muốn URL gọn (`/api/v2` -> `index.php`).

## Action hỗ trợ

| action | Mô tả | Cách dịch |
|---|---|---|
| `services` | Liệt kê dịch vụ | Lấy `GET /pricing` từ SMM-TG, áp markup theo từng service trong config |
| `add` | Đặt order | Validate service/min/max -> `POST /orders` (1 link, time_leave theo service) -> lưu mapping `v2_order_id <-> smm_order_id` vào MySQL -> trả `{order: <id>}` |
| `status` | Trạng thái order | `GET /orders/{smm_order_id}`, gộp `links[]` -> tính `remains`, map `status` (`processing/done` + `sub_status` từng link -> `Pending/In progress/Completed/Partial/Canceled`) |
| `balance` | Số dư | `GET /account`, lấy `balance` (USDT) x tỉ giá -> `{balance, currency}` |

Không hỗ trợ: `refill`, `refill_status`, `cancel` (services trả `refill: false, cancel: false`).

## Lưu ý

1. **Giá bán** (`rate` trong `services`, `charge` trong `add`):
   - Lấy `price_per_1000` từ `GET /pricing` (đã trừ discount của bạn) rồi cộng `markup_pct` trong config.
   - Hoặc đặt `rate_per_1000` cố định trong config (bỏ qua `/pricing`) nếu muốn giá cố định không phụ thuộc SMM-TG.
   - `usdt_to_display_currency`: tỉ giá quy đổi USDT -> đơn vị hiển thị (mặc định 1.0, coi USDT=USD).
2. **`status`** hiện hỗ trợ order 1 link (đúng với cách `add` đặt order). Nếu sau này muốn nhận nhiều link/order, cần mở rộng schema (`order_links` table) và logic `add`/`status`.
3. **Validate**: service không tồn tại hoặc `quantity` ngoài `min/max` -> trả lỗi v2 chuẩn `{"error": "..."}`.
4. `status`, hỗ trợ cả `order=N` (1 order) và `orders=1,2,3` (nhiều order, trả object key=order_id).
