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

        $filename = 'transaction_report_' . $sheet->filename . '_' . now()->format('Y-m-d_H-i-s') . '.csv';

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        $callback = function () use ($sheet) {
            $file = fopen('php://output', 'w');

            // Summary
            fputcsv($file, ['Transaction Sheet Report']);
            fputcsv($file, ['Filename', $sheet->filename]);
            fputcsv($file, ['Generated', now()->format('Y-m-d H:i:s')]);
            fputcsv($file, ['Total Transactions', $sheet->summary['total_transactions'] ?? 0]);
            fputcsv($file, ['Matched Orders', $sheet->summary['matched_orders'] ?? 0]);
            fputcsv($file, ['Unmatched Transactions', $sheet->summary['unmatched_transactions'] ?? 0]);
            fputcsv($file, ['Total Revenue', '£' . number_format($sheet->summary['total_revenue'] ?? 0, 2)]);
            fputcsv($file, ['Total Credit Used', '£' . number_format($sheet->summary['credit_used_total'] ?? 0, 2)]);
            fputcsv($file, ['Paid with Credit Only', $sheet->summary['paid_with_credit'] ?? 0]);
            fputcsv($file, ['Paid with Gateway', $sheet->summary['paid_with_gateway'] ?? 0]);
            fputcsv($file, ['Mixed Payments', $sheet->summary['mixed_payments'] ?? 0]);
            fputcsv($file, []); // Empty row

            // Details
            if (!empty($sheet->details)) {
                fputcsv($file, array_keys($sheet->details[0]));
                foreach ($sheet->details as $row) {
                    fputcsv($file, $row);
                }
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
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