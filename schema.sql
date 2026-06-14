CREATE TABLE IF NOT EXISTS orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    v2_order_id INT NOT NULL UNIQUE,        -- order_id trả về cho VieSMM
    smm_order_id VARCHAR(64) NOT NULL,      -- order_id của SMM-TG
    service_id INT NOT NULL,
    link VARCHAR(255) NOT NULL,
    quantity INT NOT NULL,
    time_leave INT NOT NULL,
    charge DECIMAL(10,4) NOT NULL DEFAULT 0,
    currency VARCHAR(10) NOT NULL DEFAULT 'USD',
    status VARCHAR(20) NOT NULL DEFAULT 'Pending',
    start_count INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_smm_order (smm_order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
