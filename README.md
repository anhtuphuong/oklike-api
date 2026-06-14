# oklike.shop — Middleware API v2 (adapter sang SMM-TG)

## Kiến trúc

```text
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
| `Db.php` | Kết nối MySQL (PDO) — tự tạo bảng `orders` nếu chưa tồn tại |
| `SmmTgClient.php` | Wrapper gọi API SMM-TG, ném `SmmTgException` khi lỗi |
| `V2Mapper.php` | Dịch status/error/giá SMM-TG -> chuẩn v2 |
| `index.php` | Entry point — nhận request v2, xử lý từng `action` |
| `schema.sql` | Schema tham khảo cho bảng `orders` |

## Cài đặt lên hosting

1. Upload toàn bộ các file `.php` (và `schema.sql` nếu muốn) lên hosting.
2. Tạo database MySQL trống. Không cần import schema thủ công.
3. Sửa `config.php`:
   - `smm_tg.api_key`
   - `db`
   - `client_keys`
   - `services`
4. Trỏ endpoint API vào `index.php`.

## Action hỗ trợ

- `services`
- `add`
- `status` (`order=N` và `orders=1,2,3`)
- `balance`

Không hỗ trợ: `refill`, `refill_status`, `cancel`.
