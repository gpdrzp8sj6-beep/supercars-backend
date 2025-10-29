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
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Http\Requests\NovaRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Mail\OrderCompleted;
use App\Models\Order;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GenerateUnpaidTicketsReport extends Action
{
    use InteractsWithQueue, Queueable;

    /**
     * The displayable name of the action.
     *
     * @var string
     */
    public $name = 'Generate Unpaid Tickets Report';

    /**
     * The text to be used for the action's confirm button.
     *
     * @var string
     */
    public $confirmButtonText = 'Generate Report';

    /**
     * The text to be used for the action's confirmation text.
     *
     * @var string
     */
    public $confirmText = 'Generate a CSV report of all unpaid tickets?';

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
        // Get all unpaid orders with tickets
        $unpaidOrders = Order::whereIn('status', ['pending', 'failed', 'cancelled'])
            ->whereHas('giveaways')
            ->with(['giveaways', 'user'])
            ->orderBy('created_at', 'desc')
            ->get();

        $reportData = [];
        $totalValue = 0;
        $totalTickets = 0;

        foreach ($unpaidOrders as $order) {
            foreach ($order->giveaways as $giveaway) {
                $ticketNumbers = json_decode($giveaway->pivot->numbers ?? '[]', true) ?: [];
                $ticketCount = count($ticketNumbers);
                $ticketValue = $ticketCount * $giveaway->price;

                $totalTickets += $ticketCount;
                $totalValue += $ticketValue;

                $reportData[] = [
                    'Order ID' => $order->id,
                    'User ID' => $order->user_id,
                    'User Email' => $order->user?->email ?? 'N/A',
                    'User Name' => trim(($order->forenames ?? '') . ' ' . ($order->surname ?? '')),
                    'Order Status' => $order->status,
                    'Giveaway ID' => $giveaway->id,
                    'Giveaway Title' => $giveaway->title,
                    'Ticket Count' => $ticketCount,
                    'Ticket Numbers' => implode(', ', $ticketNumbers),
                    'Price Per Ticket' => number_format($giveaway->price, 2),
                    'Total Value' => number_format($ticketValue, 2),
                    'Order Created' => $order->created_at->format('Y-m-d H:i:s'),
                    'Credit Used' => number_format($order->credit_used, 2),
                    'Amount Paid' => number_format($order->total, 2),
                ];
            }
        }

        // Generate CSV
        $filename = 'unpaid_tickets_report_' . now()->format('Y-m-d_H-i-s') . '.csv';

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        $callback = function () use ($reportData, $totalTickets, $totalValue) {
            $file = fopen('php://output', 'w');

            // Write summary
            fputcsv($file, ['Unpaid Tickets Report']);
            fputcsv($file, ['Generated', now()->format('Y-m-d H:i:s')]);
            fputcsv($file, ['Total Unpaid Tickets', $totalTickets]);
            fputcsv($file, ['Total Unpaid Value', '£' . number_format($totalValue, 2)]);
            fputcsv($file, []); // Empty row

            // Write headers
            if (!empty($reportData)) {
                fputcsv($file, array_keys($reportData[0]));
            }

            // Write data
            foreach ($reportData as $row) {
                fputcsv($file, $row);
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