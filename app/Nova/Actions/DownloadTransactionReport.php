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

class DownloadTransactionReport extends Action
{
    use InteractsWithQueue, Queueable;

    /**
     * The displayable name of the action.
     *
     * @var string
     */
    public $name = 'Download Report';

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
    public $confirmText = 'Download the detailed transaction report as CSV?';

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

        $filename = 'transaction_report_' . str_replace('.csv', '', $sheet->filename) . '_' . now()->format('Y-m-d_H-i-s') . '.csv';

        // Generate CSV content
        $csvContent = $this->generateCsvContent($sheet);

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
     * Generate CSV content for transaction report
     */
    private function generateCsvContent($sheet): string
    {
        $csv = fopen('php://temp', 'r+');

        // Summary
        fputcsv($csv, ['Transaction Sheet Report']);
        fputcsv($csv, ['Filename', $sheet->filename]);
        fputcsv($csv, ['Giveaway', $sheet->giveaway ? $sheet->giveaway->title : 'N/A']);
        fputcsv($csv, ['Generated', now()->format('Y-m-d H:i:s')]);
        fputcsv($csv, ['Total Transactions', $sheet->summary['total_transactions'] ?? 0]);
        fputcsv($csv, ['Matched Orders', $sheet->summary['matched_orders'] ?? 0]);
        fputcsv($csv, ['Unmatched Orders', $sheet->summary['unmatched_orders'] ?? 0]);
        fputcsv($csv, ['Total Revenue', '£' . number_format($sheet->summary['total_revenue'] ?? 0, 2)]);
        fputcsv($csv, ['Total Credit Used', '£' . number_format($sheet->summary['credit_used_total'] ?? 0, 2)]);
        fputcsv($csv, ['Paid with Credit Only', $sheet->summary['paid_with_credit'] ?? 0]);
        fputcsv($csv, ['Paid with Gateway', $sheet->summary['paid_with_gateway'] ?? 0]);
        fputcsv($csv, ['Mixed Payments', $sheet->summary['mixed_payments'] ?? 0]);
        fputcsv($csv, []); // Empty row

        // Transaction Details
        $transactions = $sheet->details['transactions'] ?? [];
        
        if (!empty($transactions)) {
            fputcsv($csv, array_keys($transactions[0]));
            foreach ($transactions as $row) {
                fputcsv($csv, $row);
            }
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