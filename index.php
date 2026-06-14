<?php

declare(strict_types=1);

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/SmmTgClient.php';
require_once __DIR__ . '/V2Mapper.php';

$config = require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

function respond(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function errorResponse(string $message): void
{
    respond(['error' => $message]);
}

function requestParams(): array
{
    $params = $_POST;

    if ($params === [] && isset($_SERVER['QUERY_STRING'])) {
        parse_str((string)$_SERVER['QUERY_STRING'], $query);
        if (is_array($query)) {
            $params = $query;
        }
    }

    return $params;
}

function normalizePricingRows(array $pricing): array
{
    $rows = $pricing;

    if (isset($pricing['data']) && is_array($pricing['data'])) {
        $rows = $pricing['data'];
    }

    if (isset($rows['services']) && is_array($rows['services'])) {
        $rows = $rows['services'];
    }

    $indexed = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $serviceId = $row['service_id'] ?? $row['service'] ?? $row['id'] ?? null;
        if ($serviceId === null || $serviceId === '') {
            continue;
        }

        $indexed[(int)$serviceId] = $row;
    }

    return $indexed;
}

function buildServiceCatalog(array $config, SmmTgClient $client): array
{
    $serviceConfig = $config['services'] ?? [];

    if (!is_array($serviceConfig) || $serviceConfig === []) {
        return [];
    }

    $needsPricing = false;
    foreach ($serviceConfig as $serviceId => $svc) {
        if (!is_array($svc)) {
            continue;
        }

        if (!array_key_exists('rate_per_1000', $svc) || $svc['rate_per_1000'] === null) {
            $needsPricing = true;
            break;
        }
    }

    $pricingByService = [];
    if ($needsPricing) {
        $pricingByService = normalizePricingRows($client->getPricing());
    }

    $catalog = [];

    foreach ($serviceConfig as $serviceIdRaw => $svc) {
        if (!is_array($svc)) {
            continue;
        }

        $serviceId = (int)$serviceIdRaw;
        if ($serviceId <= 0) {
            continue;
        }

        $pricingRow = $pricingByService[$serviceId] ?? [];

        $baseRate = $svc['rate_per_1000'] ?? $pricingRow['price_per_1000'] ?? $pricingRow['rate_per_1000'] ?? $pricingRow['rate'] ?? null;
        if ($baseRate === null) {
            continue;
        }

        $markup = (float)($svc['markup_pct'] ?? 0);
        $ratePer1000 = V2Mapper::applyMarkup((float)$baseRate, $markup);

        $catalog[$serviceId] = [
            'service' => $serviceId,
            'name' => (string)($svc['name'] ?? $pricingRow['name'] ?? ('Service ' . $serviceId)),
            'type' => (string)($svc['type'] ?? $pricingRow['type'] ?? 'Default'),
            'rate_per_1000' => $ratePer1000,
            'time_leave' => (int)($svc['time_leave'] ?? 30),
            'min' => (int)($svc['min'] ?? $pricingRow['min'] ?? 1),
            'max' => (int)($svc['max'] ?? $pricingRow['max'] ?? 1000000000),
        ];
    }

    return $catalog;
}

$params = requestParams();
$action = (string)($params['action'] ?? '');
$key = (string)($params['key'] ?? '');

$clientKeys = $config['client_keys'] ?? [];
if (!is_array($clientKeys) || $key === '' || !in_array($key, $clientKeys, true)) {
    errorResponse('Invalid API key.');
}

try {
    $client = new SmmTgClient($config['smm_tg'] ?? []);

    switch ($action) {
        case 'services': {
            $catalog = buildServiceCatalog($config, $client);
            $services = [];

            foreach ($catalog as $svc) {
                $services[] = [
                    'service' => $svc['service'],
                    'name' => $svc['name'],
                    'type' => $svc['type'],
                    'rate' => number_format((float)$svc['rate_per_1000'], 6, '.', ''),
                    'min' => $svc['min'],
                    'max' => $svc['max'],
                    'refill' => false,
                    'cancel' => false,
                ];
            }

            respond($services);
            break;
        }

        case 'add': {
            $serviceId = (int)($params['service'] ?? 0);
            $link = trim((string)($params['link'] ?? ''));
            $quantity = (int)($params['quantity'] ?? 0);

            if ($serviceId <= 0 || $link === '' || $quantity <= 0) {
                errorResponse('Missing or invalid add params.');
            }

            $catalog = buildServiceCatalog($config, $client);
            $service = $catalog[$serviceId] ?? null;

            if ($service === null) {
                errorResponse('Service not found.');
            }

            if ($quantity < (int)$service['min'] || $quantity > (int)$service['max']) {
                errorResponse('Quantity is out of allowed range.');
            }

            $smmOrderId = $client->createOrder($serviceId, $link, $quantity, (int)$service['time_leave']);
            $charge = round(((float)$service['rate_per_1000'] * $quantity) / 1000, 6);

            $db = new Db($config['db'] ?? []);
            $localOrderId = $db->insertOrder($serviceId, $link, $quantity, $smmOrderId, $charge);

            respond(['order' => $localOrderId]);
            break;
        }

        case 'status': {
            $singleOrder = trim((string)($params['order'] ?? ''));
            $orders = trim((string)($params['orders'] ?? ''));

            if ($singleOrder === '' && $orders === '') {
                errorResponse('Missing order or orders parameter.');
            }

            $db = new Db($config['db'] ?? []);

            if ($singleOrder !== '') {
                $v2OrderId = (int)$singleOrder;
                if ($v2OrderId <= 0) {
                    errorResponse('Invalid order id.');
                }

                $row = $db->findByV2OrderId($v2OrderId);
                if ($row === null) {
                    errorResponse('Order not found.');
                }

                $smmOrder = $client->getOrder((string)$row['smm_order_id']);
                $mapped = V2Mapper::mapOrderStatus($smmOrder, (int)$row['quantity']);
                $mapped['charge'] = number_format((float)$row['charge'], 6, '.', '');

                respond($mapped);
            }

            $result = [];
            $orderIds = array_filter(array_map('trim', explode(',', $orders)));

            foreach ($orderIds as $orderIdRaw) {
                $v2OrderId = (int)$orderIdRaw;

                if ($v2OrderId <= 0) {
                    $result[$orderIdRaw] = ['error' => 'Invalid order id.'];
                    continue;
                }

                $row = $db->findByV2OrderId($v2OrderId);
                if ($row === null) {
                    $result[(string)$v2OrderId] = ['error' => 'Order not found.'];
                    continue;
                }

                try {
                    $smmOrder = $client->getOrder((string)$row['smm_order_id']);
                    $mapped = V2Mapper::mapOrderStatus($smmOrder, (int)$row['quantity']);
                    $mapped['charge'] = number_format((float)$row['charge'], 6, '.', '');
                    $result[(string)$v2OrderId] = $mapped;
                } catch (SmmTgException $exception) {
                    $result[(string)$v2OrderId] = ['error' => $exception->getMessage()];
                }
            }

            respond($result);
            break;
        }

        case 'balance': {
            $account = $client->getAccount();
            $balanceUsdt = (float)($account['balance'] ?? $account['data']['balance'] ?? 0);

            $currencyConfig = is_array($config['currency'] ?? null) ? $config['currency'] : [];
            $displayCurrency = (string)($currencyConfig['display_currency'] ?? 'USD');
            $exchangeRate = (float)($currencyConfig['usdt_to_display_currency'] ?? 1.0);

            $displayBalance = round($balanceUsdt * $exchangeRate, 6);

            respond([
                'balance' => number_format($displayBalance, 6, '.', ''),
                'currency' => $displayCurrency,
            ]);
            break;
        }

        default:
            errorResponse('Unsupported action.');
    }
} catch (SmmTgException $exception) {
    errorResponse($exception->getMessage());
} catch (Throwable $exception) {
    errorResponse('Internal error: ' . $exception->getMessage());
}
