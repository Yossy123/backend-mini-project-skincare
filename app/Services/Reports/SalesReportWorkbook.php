<?php

namespace App\Services\Reports;

use Carbon\CarbonInterface;

/**
 * Lays a sales report out as an Excel workbook: a summary, the orders and the products sold.
 *
 * @phpstan-type Report array{orders: array<int, array<string, mixed>>, products: array<int, array{name: string, quantity: int, revenue: int}>, totals: array<string, int>}
 */
class SalesReportWorkbook
{
    private const ORDER_HEADERS = [
        'ID Pesanan', 'Tanggal Pesanan', 'Tanggal Bayar', 'Nama Customer', 'Email Customer', 'Produk',
        'Jumlah Item', 'Subtotal (IDR)', 'Ongkir (IDR)', 'Total (IDR)', 'Status', 'Metode Pembayaran',
        'Kurir', 'Layanan', 'No. Resi', 'Kota Tujuan',
    ];

    private const ORDER_COLUMN_WIDTHS = [12, 18, 18, 24, 30, 46, 11, 16, 14, 16, 12, 20, 12, 14, 22, 20];

    public function __construct(
        private readonly string $periodLabel,
        private readonly CarbonInterface $start,
        private readonly CarbonInterface $end
    ) {}

    /**
     * @param  array<string, mixed>  $report
     */
    public function toXlsx(array $report): string
    {
        $workbook = new XlsxWorkbook;
        $this->summarySheet($workbook, $report);
        $this->ordersSheet($workbook, $report);
        $this->productsSheet($workbook, $report);

        return $workbook->toBinary();
    }

    /** @param  array<string, mixed>  $report */
    private function summarySheet(XlsxWorkbook $workbook, array $report): void
    {
        $totals = $report['totals'];
        $sheet = $workbook->addSheet('Ringkasan')->columnWidths([34, 22, 40])->hideGridLines()->landscapeFit();
        $label = XlsxWorkbook::STYLE_TOTAL_LABEL;
        $rupiah = XlsxWorkbook::STYLE_TOTAL_RUPIAH;
        $integer = XlsxWorkbook::STYLE_TOTAL_INTEGER;

        $sheet->row([['value' => 'Laporan Penjualan NOBYDERM', 'style' => XlsxWorkbook::STYLE_TITLE]], 26)
            ->row([['value' => $this->periodLabel.': '.$this->start->locale('id')->translatedFormat('d F Y').' - '.$this->end->locale('id')->translatedFormat('d F Y'), 'style' => XlsxWorkbook::STYLE_DEFAULT]])
            ->row([['value' => 'Dibuat pada '.now('Asia/Jakarta')->locale('id')->translatedFormat('d F Y H:i').' WIB', 'style' => XlsxWorkbook::STYLE_NOTE]])
            ->row([])
            ->row([['value' => 'Ringkasan', 'style' => XlsxWorkbook::STYLE_HEADER], ['value' => 'Nilai', 'style' => XlsxWorkbook::STYLE_HEADER]])
            ->row([['value' => 'Jumlah pesanan', 'style' => $label], ['value' => $totals['orders'], 'style' => $integer]])
            ->row([['value' => 'Jumlah item terjual', 'style' => $label], ['value' => $totals['items'], 'style' => $integer]])
            ->row([['value' => 'Subtotal produk', 'style' => $label], ['value' => $totals['subtotal'], 'style' => $rupiah]])
            ->row([['value' => 'Ongkos kirim', 'style' => $label], ['value' => $totals['shipping'], 'style' => $rupiah]])
            ->row([['value' => 'Total penjualan', 'style' => $label], ['value' => $totals['total'], 'style' => $rupiah]])
            ->row([['value' => 'Rata-rata nilai pesanan', 'style' => $label], ['value' => $totals['average'], 'style' => $rupiah]])
            ->row([])
            ->row([['value' => 'Catatan: laporan memuat pesanan berstatus Dibayar sampai Selesai menurut tanggal pesanan. Pesanan batal atau kedaluwarsa tidak dihitung, dan refund belum dikurangkan dari total.', 'style' => XlsxWorkbook::STYLE_NOTE]], 34);
        $sheet->merge('A'.$sheet->currentRow().':C'.$sheet->currentRow());
    }

    /** @param  array<string, mixed>  $report */
    private function ordersSheet(XlsxWorkbook $workbook, array $report): void
    {
        $sheet = $workbook->addSheet('Pesanan')->columnWidths(self::ORDER_COLUMN_WIDTHS)->freezeRows(1)->landscapeFit();
        $header = array_map(fn (string $title): array => ['value' => $title, 'style' => XlsxWorkbook::STYLE_HEADER], self::ORDER_HEADERS);
        $sheet->row($header, 30);

        $text = XlsxWorkbook::STYLE_TEXT;
        foreach ($report['orders'] as $order) {
            $sheet->row([
                ['value' => $order['id'], 'style' => XlsxWorkbook::STYLE_CENTER],
                ['value' => $order['ordered_at'], 'style' => XlsxWorkbook::STYLE_DATETIME],
                ['value' => $order['paid_at'], 'style' => XlsxWorkbook::STYLE_DATETIME],
                ['value' => $order['customer'], 'style' => $text],
                ['value' => $order['email'], 'style' => $text],
                ['value' => str_replace(' | ', "\n", $order['products']), 'style' => XlsxWorkbook::STYLE_WRAP],
                ['value' => $order['items'], 'style' => XlsxWorkbook::STYLE_INTEGER],
                ['value' => $order['subtotal'], 'style' => XlsxWorkbook::STYLE_RUPIAH],
                ['value' => $order['shipping'], 'style' => XlsxWorkbook::STYLE_RUPIAH],
                ['value' => $order['total'], 'style' => XlsxWorkbook::STYLE_RUPIAH],
                ['value' => $order['status'], 'style' => $text],
                ['value' => $order['payment_method'], 'style' => $text],
                ['value' => $order['courier'], 'style' => $text],
                ['value' => $order['service'], 'style' => $text],
                ['value' => $order['tracking_number'], 'style' => $text],
                ['value' => $order['city'], 'style' => $text],
            ]);
        }

        $last = $sheet->currentRow();
        $sheet->autoFilterHeader(count(self::ORDER_HEADERS));

        if ($last > 1) {
            $totals = $report['totals'];
            $sum = fn (string $column): string => 'SUBTOTAL(109,'.$column.'2:'.$column.$last.')';
            $label = XlsxWorkbook::STYLE_TOTAL_LABEL;
            $sheet->row([
                ['value' => 'TOTAL', 'style' => $label], ['value' => '', 'style' => $label], ['value' => '', 'style' => $label],
                ['value' => '', 'style' => $label], ['value' => '', 'style' => $label], ['value' => '', 'style' => $label],
                ['value' => $totals['items'], 'formula' => $sum('G'), 'style' => XlsxWorkbook::STYLE_TOTAL_INTEGER],
                ['value' => $totals['subtotal'], 'formula' => $sum('H'), 'style' => XlsxWorkbook::STYLE_TOTAL_RUPIAH],
                ['value' => $totals['shipping'], 'formula' => $sum('I'), 'style' => XlsxWorkbook::STYLE_TOTAL_RUPIAH],
                ['value' => $totals['total'], 'formula' => $sum('J'), 'style' => XlsxWorkbook::STYLE_TOTAL_RUPIAH],
            ]);
        }
    }

    /** @param  array<string, mixed>  $report */
    private function productsSheet(XlsxWorkbook $workbook, array $report): void
    {
        $sheet = $workbook->addSheet('Produk Terjual')->columnWidths([44, 16, 20])->freezeRows(1)->landscapeFit();
        $sheet->row([
            ['value' => 'Produk', 'style' => XlsxWorkbook::STYLE_HEADER],
            ['value' => 'Jumlah Terjual', 'style' => XlsxWorkbook::STYLE_HEADER],
            ['value' => 'Pendapatan (IDR)', 'style' => XlsxWorkbook::STYLE_HEADER],
        ], 30);

        foreach ($report['products'] as $product) {
            $sheet->row([
                ['value' => $product['name'], 'style' => XlsxWorkbook::STYLE_TEXT],
                ['value' => $product['quantity'], 'style' => XlsxWorkbook::STYLE_INTEGER],
                ['value' => $product['revenue'], 'style' => XlsxWorkbook::STYLE_RUPIAH],
            ]);
        }
    }
}
