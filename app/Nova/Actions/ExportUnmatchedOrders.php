<?php

namespace App\Nova\Actions;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Actions\ActionResponse;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Http\Requests\NovaRequest;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExportUnmatchedOrders extends Action
{
    use InteractsWithQueue;
    use Queueable;

    /**
     * Indicates if this action is only available on the resource index view.
     *
     * @var bool
     */
    public $onlyOnIndex = true;

    /**
     * Indicates if this action is only available on the resource detail view.
     *
     * @var bool
     */
    public $onlyOnDetail = false;

    /**
     * Perform the action on the given models.
     *
     * @return mixed
     */
    public function handle(ActionFields $fields, Collection $models)
    {
        $transactionSheet = $models->first();

        if (!$transactionSheet || !isset($transactionSheet->details['unmatched_orders'])) {
            return ActionResponse::danger('No analytics data available for this transaction sheet.');
        }

        $unmatchedOrders = $transactionSheet->details['unmatched_orders'];

        if (empty($unmatchedOrders)) {
            return ActionResponse::message('All completed orders for this giveaway have matching transactions!');
        }

        // Create CSV content
        $csvContent = $this->generateCsvContent($unmatchedOrders, $transactionSheet);

        // Generate filename
        $filename = 'unmatched_orders_' . $transactionSheet->filename . '_' . now()->format('Y-m-d_H-i-s') . '.csv';

        // Store temporary file
        $tempPath = storage_path('app/temp/' . $filename);

        // Ensure temp directory exists
        $tempDir = dirname($tempPath);
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        // Write CSV to temp file
        file_put_contents($tempPath, $csvContent);

        // Return downloadable response
        return ActionResponse::download(
            url('/nova/download/temp-file/' . $filename),
            $filename
        );
    }

    /**
     * Generate CSV content for unmatched orders
     */
    private function generateCsvContent(array $unmatchedOrders, $transactionSheet): string
    {
        $csv = fopen('php://temp', 'r+');

        // Add header
        fputcsv($csv, [
            'Transaction Sheet',
            'Giveaway ID',
            'Order ID',
            'User ID',
            'User Email',
            'Order Total',
            'Ticket Numbers',
            'Ticket Amount',
            'Order Status',
            'Created At'
        ]);

        // Add data rows
        foreach ($unmatchedOrders as $order) {
            fputcsv($csv, [
                $transactionSheet->filename,
                $transactionSheet->giveaway_id,
                $order['order_id'],
                $order['user_id'],
                $order['user_email'],
                $order['order_total'],
                is_array($order['ticket_numbers']) ? implode(', ', $order['ticket_numbers']) : $order['ticket_numbers'],
                $order['ticket_amount'] ?? '',
                'completed', // These are always completed orders
                now()->format('Y-m-d H:i:s')
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

    /**
     * Get the displayable name of the action.
     *
     * @return string
     */
    public function name()
    {
        return 'Export Unmatched Orders';
    }
}