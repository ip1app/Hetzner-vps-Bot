<?php
declare(strict_types=1);

namespace App\Services;

use App\Database\Database;

final class Finance
{
    private static function monthKey(\DateTimeInterface $d): string
    {
        return $d->format('Y-m');
    }

    /** @param list<array<string, mixed>> $invoices */
    private static function sumPaidInMonth(array $invoices, string $key): float
    {
        $sum = 0.0;
        foreach ($invoices as $i) {
            if (empty($i['paid'])) {
                continue;
            }
            $t = $i['paid_at'] ?? $i['created_at'] ?? '';
            if ($t && self::monthKey(new \DateTime($t)) === $key) {
                $sum += (float) ($i['amount'] ?? 0);
            }
        }
        return $sum;
    }

    /** @return array<string, mixed> */
    public static function buildReport(?array $invoices = null): array
    {
        $invoices = $invoices ?? Database::listInvoices();
        $paid = array_values(array_filter($invoices, fn($i) => !empty($i['paid'])));
        $now = new \DateTime();
        $thisKey = self::monthKey($now);
        $lastKey = self::monthKey((clone $now)->modify('-1 month'));

        $months = [];
        for ($i = 5; $i >= 0; $i--) {
            $d = (clone $now)->modify("-$i month");
            $key = self::monthKey($d);
            $months[] = ['key' => $key, 'label' => $d->format('M y'), 'sum' => self::sumPaidInMonth($paid, $key)];
        }

        $totalRevenue = array_sum(array_map(fn($i) => (float) ($i['amount'] ?? 0), $paid));
        $storeRevenue = array_sum(array_map(fn($i) => (float) ($i['amount'] ?? 0), array_filter($paid, fn($i) => ($i['source'] ?? '') === 'store')));

        usort($paid, fn($a, $b) => strcmp($b['paid_at'] ?? $b['created_at'] ?? '', $a['paid_at'] ?? $a['created_at'] ?? ''));

        return [
            'totalRevenue' => $totalRevenue,
            'thisMonth' => self::sumPaidInMonth($paid, $thisKey),
            'lastMonth' => self::sumPaidInMonth($paid, $lastKey),
            'paidCount' => count($paid),
            'unpaidCount' => count(array_filter($invoices, fn($i) => empty($i['paid']))),
            'storeRevenue' => $storeRevenue,
            'adminRevenue' => $totalRevenue - $storeRevenue,
            'months' => $months,
            'maxSum' => max(array_map(fn($m) => $m['sum'], $months) ?: [1], 1),
            'recentPaid' => array_slice($paid, 0, 10),
        ];
    }
}
