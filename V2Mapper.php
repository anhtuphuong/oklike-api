<?php

declare(strict_types=1);

final class V2Mapper
{
    public static function applyMarkup(float $baseRatePer1000, float $markupPct): float
    {
        return round($baseRatePer1000 * (1 + ($markupPct / 100)), 6);
    }

    public static function mapOrderStatus(array $smmOrder, int $orderQuantity): array
    {
        $links = [];

        if (isset($smmOrder['links']) && is_array($smmOrder['links'])) {
            $links = $smmOrder['links'];
        } elseif (isset($smmOrder['data']['links']) && is_array($smmOrder['data']['links'])) {
            $links = $smmOrder['data']['links'];
        }

        $overall = strtolower((string)($smmOrder['status'] ?? $smmOrder['data']['status'] ?? 'processing'));

        $linkStatuses = [];
        $startCount = 0;
        $totalDelivered = 0;

        foreach ($links as $link) {
            if (!is_array($link)) {
                continue;
            }

            $linkStatuses[] = self::mapLinkStatus((string)($link['sub_status'] ?? $link['status'] ?? ''));

            if ($startCount === 0) {
                $startCount = (int)($link['start_count'] ?? $link['start'] ?? 0);
            }

            if (isset($link['delivered'])) {
                $totalDelivered += max(0, (int)$link['delivered']);
            } elseif (isset($link['current_count'])) {
                $delivered = (int)$link['current_count'] - (int)($link['start_count'] ?? $link['start'] ?? 0);
                $totalDelivered += max(0, $delivered);
            }
        }

        $status = self::aggregateOrderStatus($overall, $linkStatuses);
        $remains = max(0, $orderQuantity - $totalDelivered);

        if (isset($smmOrder['remains'])) {
            $remains = max(0, (int)$smmOrder['remains']);
        } elseif (isset($smmOrder['data']['remains'])) {
            $remains = max(0, (int)$smmOrder['data']['remains']);
        }

        return [
            'start_count' => $startCount,
            'status' => $status,
            'remains' => $remains,
        ];
    }

    private static function mapLinkStatus(string $status): string
    {
        $normalized = strtolower(trim($status));

        return match ($normalized) {
            'pending', 'queued', 'new' => 'Pending',
            'processing', 'in progress', 'in_progress', 'running', 'working' => 'In progress',
            'done', 'completed', 'success' => 'Completed',
            'partial' => 'Partial',
            'canceled', 'cancelled', 'failed', 'error' => 'Canceled',
            default => 'In progress',
        };
    }

    private static function aggregateOrderStatus(string $overallStatus, array $linkStatuses): string
    {
        if ($linkStatuses === []) {
            return match ($overallStatus) {
                'done', 'completed', 'success' => 'Completed',
                'canceled', 'cancelled', 'failed', 'error' => 'Canceled',
                default => 'In progress',
            };
        }

        $counts = array_count_values($linkStatuses);

        $completed = (int)($counts['Completed'] ?? 0);
        $partial = (int)($counts['Partial'] ?? 0);
        $canceled = (int)($counts['Canceled'] ?? 0);
        $inProgress = (int)($counts['In progress'] ?? 0);
        $pending = (int)($counts['Pending'] ?? 0);
        $total = count($linkStatuses);

        if ($completed === $total) {
            return 'Completed';
        }

        if ($canceled === $total) {
            return 'Canceled';
        }

        if ($inProgress > 0) {
            return 'In progress';
        }

        if ($pending === $total) {
            return 'Pending';
        }

        if ($partial > 0 || ($completed > 0 && $canceled > 0)) {
            return 'Partial';
        }

        if ($pending > 0) {
            return 'In progress';
        }

        return 'In progress';
    }
}
