<?php

namespace App\Nova\Actions;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Illuminate\Http\Request;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Actions\ActionResponse;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Http\Requests\NovaRequest;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DownloadUnmatchedOrdersReport extends Action
{
    use InteractsWithQueue, Queueable;

    /**
     * The displayable name of the action.
     *
     * @var string
     */
    public $name = 'Download Unmatched Orders';

    /**
     * The text to be used for the action's confirm button.
     *
     * @var string
     */
    public $confirmButtonText = 'Download';

    /**
     * The text to be used for the action's confirmation text.
     *
     * @var string
     */
    public $confirmText = 'Download unmatched orders report as CSV?';

    /**
     * Determine if the action should be available for the given request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return bool
     */
    public function authorizedToSee(Request $request)
    {
        return true;
    }

    /**
     * Determine if the action should be available for the given request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return bool
     */
    public function authorizedToRun(Request $request, $model)
    {
        return true;
    }

    /**
     * Perform the action on the given models.
     *
     * @return mixed
     */
    public function handle(ActionFields $fields, Collection $models)
    {
        $sheet = $models->first();

        if (!$sheet) {
            return ActionResponse::danger('No transaction sheet selected.');
        }

        $unmatchedOrders = $sheet->details['unmatched_orders'] ?? [];

        if (empty($unmatchedOrders)) {
            return ActionResponse::message('No unmatched orders found for this transaction sheet.');
        }

        $filename = 'unmatched_orders_' . str_replace('.csv', '', $sheet->filename) . '_' . now()->format('Y-m-d_H-i-s') . '.csv';

        // Generate CSV content
        $csvContent = $this->generateCsvContent($sheet, $unmatchedOrders);

        // Store temporary file
        $tempPath = storage_path('app/temp/' . $filename);
        
        // Ensure temp directory exists
        $tempDir = dirname($tempPath);
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        // Write CSV to temp file
        file_put_contents($tempPath, $csvContent);

        // Return downloadable response with full URL
        return ActionResponse::download(
            $filename,
            route('nova.download.temp', ['filename' => $filename])
        );
    }

    /**
     * Generate CSV content for unmatched orders
     */
    private function generateCsvContent($sheet, array $unmatchedOrders): string
    {
        $csv = fopen('php://temp', 'r+');

        // Transaction Sheet Information
        fputcsv($csv, ['Unmatched Orders Report']);
        fputcsv($csv, ['Transaction Sheet', $sheet->filename]);
        fputcsv($csv, ['Giveaway', $sheet->giveaway ? $sheet->giveaway->title : 'N/A']);
        fputcsv($csv, ['Generated', now()->format('Y-m-d H:i:s')]);
        fputcsv($csv, ['Total Transactions in Sheet', $sheet->summary['total_transactions'] ?? 0]);
        fputcsv($csv, ['Matched Orders', $sheet->summary['matched_orders'] ?? 0]);
        fputcsv($csv, ['Unmatched Orders Count', $sheet->summary['unmatched_orders'] ?? 0]);
        fputcsv($csv, []); // Empty row

        // CSV Headers
        fputcsv($csv, [
            'Order ID',
            'User ID',
            'User Email',
            'Order Total (£)',
            'Credit Used (£)',
            'Original Total (£)',
            'Payment Method',
            'Ticket Numbers',
            'Ticket Amount (£)',
            'Order Status',
            'Created At'
        ]);

        // Load order details for additional information
        $orderIds = collect($unmatchedOrders)->pluck('order_id')->toArray();
        $orders = \App\Models\Order::whereIn('id', $orderIds)->with('user')->get()->keyBy('id');

        foreach ($unmatchedOrders as $unmatchedOrder) {
            $order = $orders->get($unmatchedOrder['order_id']);
            
            // Get credit info from actual order model (for backward compatibility with old sheets)
            $creditUsed = $order ? ($order->credit_used ?? 0) : ($unmatchedOrder['credit_used'] ?? 0);
            $orderTotal = $order ? $order->total : ($unmatchedOrder['order_total'] ?? 0);
            $originalTotal = $order ? $order->original_total : ($unmatchedOrder['original_total'] ?? $orderTotal);
            
            // Determine payment method
            if ($creditUsed > 0 && $orderTotal == 0) {
                $paymentMethod = 'Credit Only';
            } elseif ($creditUsed > 0 && $orderTotal > 0) {
                $paymentMethod = 'Credit + Gateway';
            } else {
                $paymentMethod = 'Gateway Only';
            }

            fputcsv($csv, [
                $unmatchedOrder['order_id'],
                $unmatchedOrder['user_id'],
                $unmatchedOrder['user_email'],
                number_format($orderTotal, 2),
                number_format($creditUsed, 2),
                number_format($originalTotal, 2),
                $paymentMethod,
                is_array($unmatchedOrder['ticket_numbers']) ? implode(', ', $unmatchedOrder['ticket_numbers']) : $unmatchedOrder['ticket_numbers'],
                number_format($unmatchedOrder['ticket_amount'] ?? 0, 2),
                $order ? $order->status : 'Unknown',
                $order ? $order->created_at->format('Y-m-d H:i:s') : 'Unknown'
            ]);
        }

        rewind($csv);
        $content = stream_get_contents($csv);
        fclose($csv);

        return $content;
    }

    /**
     * Get the fields available on the action.
     *
     * @return array<int, \Laravel\Nova\Fields\Field>
     */
    public function fields(NovaRequest $request): array
    {
        return [];
    }
}