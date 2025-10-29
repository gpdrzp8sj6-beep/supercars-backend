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

class ShowMissingOrdersAnalytics extends Action
{
    use InteractsWithQueue;
    use Queueable;

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

        $message = "Found " . count($unmatchedOrders) . " completed orders without matching transactions:\n\n";

        foreach ($unmatchedOrders as $order) {
            $ticketNumbers = is_array($order['ticket_numbers']) ? implode(', ', $order['ticket_numbers']) : $order['ticket_numbers'];
            $message .= "• Order #{$order['order_id']} - User: {$order['user_email']} - Amount: £{$order['order_total']} - Tickets: {$ticketNumbers}\n";
        }

        return ActionResponse::message($message);
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
