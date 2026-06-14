<?php

/**
 * oklike.shop API v2 endpoint.
 * VieSMM gọi POST tới đây với form params: key, action, ...
 *
 * Hỗ trợ: services, add, status, balance
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/Db.php';
require __DIR__ . '/SmmTgClient.php';
require __DIR__ . '/V2Mapper.php';

$config = require __DIR__ . '/config.php';

function v2_error(string $message, int $httpCode = 400): void
{
    http_response_code($httpCode);
    echo json_encode(['error' => $message]);
    exit;
}

// ---------- Auth ----------
$key = $_POST['key'] ?? $_GET['key'] ?? '';
if ($key === '' || !isset($config['client_keys'][$key])) {
    v2_error('Incorrect API key', 401);
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
if ($action === '') {
    v2_error('Incorrect action');
}

$params = $_POST + $_GET;

$smm = new SmmTgClient($config['smm_tg']['base_url'], $config['smm_tg']['api_key']);

try {
    switch ($action) {
        case 'services':
            handleServices($config, $smm);
            break;

        case 'add':
            handleAdd($config, $smm, $params);
            break;

        case 'status':
            handleStatus($config, $smm, $params);
            break;

        case 'balance':
            handleBalance($config, $smm);
            break;

        default:
            v2_error('Incorrect action');
    }
} catch (SmmTgException $e) {
    v2_error(V2Mapper::errorMessage($e));
} catch (Throwable $e) {
    error_log('[oklike v2] ' . $e->getMessage());
    v2_error('Internal error', 500);
}


// =====================================================================
//  Handlers
// =====================================================================

function handleServices(array $config, SmmTgClient $smm): void
{
    // Lấy giá gốc từ SMM-TG /pricing để tính rate hiện hành
    $pricing = $smm->pricing(); // price_per_1000: {"4":..,"30":..,"60":..,"90":..}
    $priceMap = $pricing['price_per_1000'] ?? [];

    $usdtToDisplay = (float)$config['usdt_to_display_currency'];

    $out = [];
    foreach ($config['services'] as $id => $svc) {
        $tl = (string)$svc['time_leave'];

        if ($svc['rate_per_1000'] !== null) {
            $rate = (float)$svc['rate_per_1000'];
        } else {
            $basePrice = (float)($priceMap[$tl] ?? 0);
            $rate = V2Mapper::rateFor1000($basePrice, (float)$svc['markup_pct'], $usdtToDisplay);
        }

        $out[] = [
            'service'  => $id,
            'name'     => $svc['name'],
            'type'     => 'Default',
            'category' => $svc['category'],
            'rate'     => number_format($rate, 4, '.', ''),
            'min'      => (string)$svc['min'],
            'max'      => (string)$svc['max'],
            'refill'   => false,
            'cancel'   => false,
        ];
    }

    echo json_encode($out);
}

function handleAdd(array $config, SmmTgClient $smm, array $params): void
{
    $serviceId = (int)($params['service'] ?? 0);
    $link      = trim((string)($params['link'] ?? ''));
    $quantity  = (int)($params['quantity'] ?? 0);

    if (!isset($config['services'][$serviceId])) {
        v2_error('Incorrect service ID');
    }
    if ($link === '') {
        v2_error('Incorrect link');
    }

    $svc = $config['services'][$serviceId];

    if ($quantity < $svc['min'] || $quantity > $svc['max']) {
        v2_error("Quantity must be between {$svc['min']} and {$svc['max']}");
    }

    // Gọi SMM-TG đặt order
    $resp = $smm->placeOrder([
        'link'       => $link,
        'qty'        => $quantity,
        'time_leave' => $svc['time_leave'],
    ]);

    // Nếu link bị reject hoàn toàn (accepted rỗng)
    if (empty($resp['accepted'])) {
        $reason = 'Invalid link';
        if (!empty($resp['rejected'][0]['reason'])) {
            $reason = V2Mapper::rejectReason($resp['rejected'][0]['reason']);
        }
        v2_error($reason);
    }

    $smmOrderId = $resp['order_id'];
    $costUsdt   = (float)($resp['cost'] ?? 0);
    $usdtToDisplay = (float)$config['usdt_to_display_currency'];
    $charge = V2Mapper::applyMarkup($costUsdt, (float)$svc['markup_pct'], $usdtToDisplay);

    // Lưu mapping vào DB. v2_order_id dùng chính AUTO_INCREMENT id của bảng,
    // nên insert trước với 0 rồi update lại bằng id thật.
    $db = Db::conn();
    $stmt = $db->prepare(
        'INSERT INTO orders (v2_order_id, smm_order_id, service_id, link, quantity, time_leave, charge, currency, status, start_count)
         VALUES (0, :smm_id, :service_id, :link, :qty, :tl, :charge, :currency, :status, 0)'
    );
    $stmt->execute([
        'smm_id'     => $smmOrderId,
        'service_id' => $serviceId,
        'link'       => $link,
        'qty'        => $quantity,
        'tl'         => $svc['time_leave'],
        'charge'     => $charge,
        'currency'   => $config['display_currency'],
        'status'     => 'Pending',
    ]);
    $newId = (int)$db->lastInsertId();
    $db->prepare('UPDATE orders SET v2_order_id = :id WHERE id = :id2')
       ->execute(['id' => $newId, 'id2' => $newId]);

    echo json_encode(['order' => $newId]);
}

function handleStatus(array $config, SmmTgClient $smm, array $params): void
{
    $db = Db::conn();

    // status hỗ trợ 1 order hoặc nhiều order (orders=1,2,3)
    if (isset($params['orders']) && $params['orders'] !== '') {
        $ids = array_map('intval', explode(',', (string)$params['orders']));
        $out = [];
        foreach ($ids as $id) {
            $out[$id] = statusForOrder($config, $smm, $db, $id);
        }
        echo json_encode($out);
        return;
    }

    $id = (int)($params['order'] ?? 0);
    if ($id <= 0) {
        v2_error('Incorrect order ID');
    }

    $result = statusForOrder($config, $smm, $db, $id);
    if (isset($result['error'])) {
        v2_error($result['error']);
    }
    echo json_encode($result);
}

/**
 * @return array Trả về 1 trong 2 dạng:
 *  - thành công: {charge, start_count, status, remains, currency}
 *  - lỗi: {error: "..."}
 */
function statusForOrder(array $config, SmmTgClient $smm, PDO $db, int $v2OrderId): array
{
    $stmt = $db->prepare('SELECT * FROM orders WHERE v2_order_id = :id');
    $stmt->execute(['id' => $v2OrderId]);
    $row = $stmt->fetch();

    if (!$row) {
        return ['error' => 'Incorrect order ID'];
    }

    try {
        $smmResp = $smm->orderStatus($row['smm_order_id']);
    } catch (SmmTgException $e) {
        // Nếu SMM-TG báo not found / lỗi tạm thời, fallback trả status đã lưu trong DB
        return [
            'charge'      => number_format((float)$row['charge'], 4, '.', ''),
            'start_count' => (string)$row['start_count'],
            'status'      => $row['status'],
            'remains'     => (string)$row['quantity'],
            'currency'    => $row['currency'],
        ];
    }

    $links = $smmResp['links'] ?? [];
    $agg   = V2Mapper::aggregateLinks($links);
    $status = V2Mapper::orderStatus($smmResp['status'] ?? 'processing', $links);

    // cập nhật DB
    $db->prepare('UPDATE orders SET status = :status WHERE v2_order_id = :id')
       ->execute(['status' => $status, 'id' => $v2OrderId]);

    return [
        'charge'      => number_format((float)$row['charge'], 4, '.', ''),
        'start_count' => (string)$row['start_count'],
        'status'      => $status,
        'remains'     => (string)$agg['remains'],
        'currency'    => $row['currency'],
    ];
}

function handleBalance(array $config, SmmTgClient $smm): void
{
    $account = $smm->account();
    $usdtToDisplay = (float)$config['usdt_to_display_currency'];

    $balance = (float)($account['balance'] ?? 0) * $usdtToDisplay;

    echo json_encode([
        'balance'  => number_format($balance, 4, '.', ''),
        'currency' => $config['display_currency'],
    ]);
}
