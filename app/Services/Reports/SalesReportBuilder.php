<?php

namespace App\Services\Reports;

use App\Models\Order;
use App\Services\Analytics\SalesAnalyticsService;
use Carbon\CarbonInterface;

/**
 * Collects the paid orders of a period into the figures every sales-report format shows.
 */
class SalesReportBuilder
{
    /** Customer-facing wording of the statuses a sales report can contain. */
    private const STATUS_LABELS = [
        'PAID' => 'Dibayar',
        'PROCESSING' => 'Diproses',
        'SHIPPED' => 'Dikirim',
        'DELIVERED' => 'Terkirim',
        'COMPLETED' => 'Selesai',
    ];

    /**
     * @return array{
     *     orders: array<int, array<string, mixed>>,
     *     products: array<int, array{name: string, quantity: int, revenue: int}>,
     *     totals: array{orders: int, items: int, subtotal: int, shipping: int, total: int, average: int}
     * }
     */
    public function build(CarbonInterface $start, CarbonInterface $end): array
    {
        $orders = [];
        $products = [];
        $totals = ['orders' => 0, 'items' => 0, 'subtotal' => 0, 'shipping' => 0, 'total' => 0, 'average' => 0];

        Order::with([
            'user:id,name,email',
            'orderItems:id,order_id,product_name,quantity,subtotal',
            'payment:id,order_id,payment_type,paid_at',
            'shipment:id,order_id,courier,service,tracking_number',
        ])
            ->whereIn('status', SalesAnalyticsService::VALID_PAID_STATUSES)
            ->whereBetween('created_at', [$start, $end])
            ->orderBy('id')
            ->chunkById(500, function ($chunk) use (&$orders, &$products, &$totals): void {
                foreach ($chunk as $order) {
                    $items = (int) $order->orderItems->sum('quantity');
                    $row = [
                        'id' => $order->id,
                        'ordered_at' => $order->created_at,
                        'paid_at' => $order->payment?->paid_at,
                        'customer' => (string) $order->user?->name,
                        'email' => (string) $order->user?->email,
                        'products' => $order->orderItems->map(fn ($item): string => $item->product_name.' x '.$item->quantity)->implode(' | '),
                        'items' => $items,
                        'subtotal' => $this->rupiah($order->subtotal),
                        'shipping' => $this->rupiah($order->shipping_cost),
                        'total' => $this->rupiah($order->total),
                        'status' => self::STATUS_LABELS[strtoupper($order->status)] ?? $order->status,
                        'payment_method' => $this->paymentMethodLabel($order->payment?->payment_type),
                        'courier' => (string) ($order->shipment?->courier ?? $order->shipping_courier),
                        'service' => (string) ($order->shipment?->service ?? $order->shipping_service),
                        'tracking_number' => (string) $order->shipment?->tracking_number,
                        'city' => (string) data_get($order->shipping_address, 'city', ''),
                    ];
                    $orders[] = $row;

                    $totals['orders']++;
                    $totals['items'] += $items;
                    $totals['subtotal'] += $row['subtotal'];
                    $totals['shipping'] += $row['shipping'];
                    $totals['total'] += $row['total'];

                    foreach ($order->orderItems as $item) {
                        $name = (string) $item->product_name;
                        $products[$name] ??= ['name' => $name, 'quantity' => 0, 'revenue' => 0];
                        $products[$name]['quantity'] += (int) $item->quantity;
                        $products[$name]['revenue'] += $this->rupiah($item->subtotal);
                    }
                }
            });

        $totals['average'] = $totals['orders'] > 0 ? (int) round($totals['total'] / $totals['orders']) : 0;

        $products = array_values($products);
        usort($products, fn (array $a, array $b): int => [$b['revenue'], $b['quantity']] <=> [$a['revenue'], $a['quantity']]);

        return ['orders' => $orders, 'products' => $products, 'totals' => $totals];
    }

    /** Human wording of a Midtrans payment type, e.g. `bank_transfer` becomes "Transfer Bank". */
    private function paymentMethodLabel(?string $type): string
    {
        if ($type === null || $type === '') {
            return '';
        }

        return match (strtolower($type)) {
            'bank_transfer' => 'Transfer Bank',
            'echannel' => 'Mandiri Bill',
            'qris' => 'QRIS',
            'gopay' => 'GoPay',
            'shopeepay' => 'ShopeePay',
            'credit_card' => 'Kartu Kredit',
            'cstore' => 'Gerai Retail',
            default => ucwords(str_replace('_', ' ', $type)),
        };
    }

    /** Rupiah has no decimals; whole numbers read correctly in every spreadsheet locale. */
    private function rupiah(mixed $amount): int
    {
        return (int) round((float) $amount);
    }

    /** Indonesian name of a report period, e.g. "Bulan ini". */
    public static function periodLabel(string $period): string
    {
        return match ($period) {
            'week' => 'Minggu ini',
            'month' => 'Bulan ini',
            'year' => 'Tahun ini',
            default => $period,
        };
    }
}
