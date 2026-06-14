<?php

/**
 * Các hàm dịch dữ liệu giữa SMM-TG <-> API v2 (chuẩn giống VieSMM/Api.php).
 */
class V2Mapper
{
    /**
     * Map status tổng của order SMM-TG -> status chuẩn v2.
     * v2 status chuẩn: Pending, In progress, Completed, Partial, Canceled, Processing
     */
    public static function orderStatus(string $smmStatus, array $links): string
    {
        if ($smmStatus === 'processing') {
            // còn link pending -> Pending, có link running -> In progress
            foreach ($links as $l) {
                if (($l['sub_status'] ?? '') === 'running') {
                    return 'In progress';
                }
            }
            return 'Pending';
        }

        if ($smmStatus === 'done') {
            $hasPartial = false;
            $hasStalled = false;
            $allStalled = true;

            foreach ($links as $l) {
                $sub = $l['sub_status'] ?? '';
                if ($sub === 'partial') $hasPartial = true;
                if ($sub === 'stalled') $hasStalled = true;
                if ($sub !== 'stalled') $allStalled = false;
            }

            if ($allStalled && count($links) > 0) {
                return 'Canceled';
            }
            if ($hasPartial || $hasStalled) {
                return 'Partial';
            }
            return 'Completed';
        }

        return 'Pending';
    }

    /**
     * Tính tổng start_count, remains, joined cho 1 order (gộp nhiều link).
     */
    public static function aggregateLinks(array $links): array
    {
        $totalQty = 0;
        $totalJoined = 0;
        foreach ($links as $l) {
            $totalQty    += (int)($l['qty'] ?? 0);
            $totalJoined += (int)($l['joined'] ?? 0);
        }
        $remains = max(0, $totalQty - $totalJoined);

        return [
            'qty'     => $totalQty,
            'joined'  => $totalJoined,
            'remains' => $remains,
        ];
    }

    /**
     * Tính giá bán cho VieSMM (USD) từ giá gốc SMM-TG (USDT) + markup.
     * cost theo SMM-TG đã là giá đã chiết khấu cho reseller.
     */
    public static function applyMarkup(float $costUsdt, float $markupPct, float $usdtToDisplay): float
    {
        $price = $costUsdt * (1 + $markupPct / 100) * $usdtToDisplay;
        return round($price, 4);
    }

    /**
     * Tính rate (giá / 1000) hiển thị cho action=services, dựa trên SMM-TG /pricing
     * cho time_leave tương ứng.
     */
    public static function rateFor1000(float $smmPricePer1000, float $markupPct, float $usdtToDisplay): float
    {
        return self::applyMarkup($smmPricePer1000, $markupPct, $usdtToDisplay);
    }

    /**
     * Map error code của SMM-TG -> message lỗi v2 dễ hiểu.
     */
    public static function errorMessage(SmmTgException $e): string
    {
        $map = [
            'no_links'           => 'Invalid link',
            'bad_time_leave'     => 'Invalid service configuration',
            'no_valid_links'     => 'Invalid link',
            'too_many_links'     => 'Too many links in one order',
            'all_links_invalid'  => 'Invalid link',
            'missing_params'     => 'Missing required parameters',
            'insufficient_balance' => 'Not enough funds on provider account',
            'credit_limit_exceeded' => 'Not enough funds on provider account',
            'suspended'          => 'Service temporarily unavailable',
            'order_not_found'    => 'Incorrect order ID',
            'rate_limited'       => 'Server is busy, try again later',
            'order_rate_limited' => 'Server is busy, try again later',
            'placement_failed'   => 'Unable to add order, try again later',
            'feature_disabled'   => 'Service temporarily unavailable',
            'intermediary_unavailable' => 'Service temporarily unavailable',
            'connection_error'   => 'Service temporarily unavailable',
            'bad_response'       => 'Service temporarily unavailable',
            'cannot_resolve_link'  => 'Invalid link',
        ];

        return $map[$e->errorCode] ?? 'Error: ' . $e->errorCode;
    }

    /**
     * Map link "rejected" reason (SMM-TG) -> message.
     */
    public static function rejectReason(string $reason): string
    {
        $map = [
            'invalid_link'   => 'Invalid link',
            'bad_qty'        => 'Invalid quantity',
            'expired'        => 'Link expired',
            'invalid'        => 'Invalid link',
            'not_group'      => 'Link is not a group/channel',
            'join_request'   => 'Link requires join request approval',
            'bad_entry'      => 'Invalid link',
        ];

        if (preg_match('/^below_min\((\d+)<(\d+)\)$/', $reason, $m)) {
            return "Min amount is {$m[2]}";
        }

        return $map[$reason] ?? ('Invalid link: ' . $reason);
    }
}
