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
use Laravel\Nova\Fields\MultiSelect;
use Laravel\Nova\Http\Requests\NovaRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RemoveTickets extends Action
{
    use InteractsWithQueue, Queueable;

    /**
     * The displayable name of the action.
     *
     * @var string
     */
    public $name = 'Remove Specific Tickets';

    /**
     * The text to be used for the action's confirm button.
     *
     * @var string
     */
    public $confirmButtonText = 'Remove Tickets';

    /**
     * The text to be used for the action's confirmation text.
     *
     * @var string
     */
    public $confirmText = 'Are you sure you want to remove the selected ticket numbers? This will make them available for other users.';

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
        // Only allow if order has giveaways with tickets assigned
        return $model->giveaways()->count() > 0;
    }

    /**
     * Get the fields available on the action.
     *
     * @return array<int, \Laravel\Nova\Fields\Field>
     */
    public function fields(NovaRequest $request): array
    {
        $ticketOptions = [];

        if (!empty($request->resourceId)) {
            $order = $request->findResourceOrFail();

            foreach ($order->giveaways as $giveaway) {
                $numbers = json_decode($giveaway->pivot->numbers ?? '[]', true);
                if (is_array($numbers)) {
                    foreach ($numbers as $number) {
                        $ticketOptions[$number] = "Giveaway #{$giveaway->id} - Ticket #{$number}";
                    }
                }
            }
        } else {
            $ticketOptions = [];
        }

        return [
            MultiSelect::make('Tickets to Remove', 'tickets_to_remove')
                ->options($ticketOptions)
                ->required()
                ->help('Select the specific ticket numbers you want to remove from this order.'),
        ];
    }

    /**
     * Perform the action on the given models.
     *
     * @return mixed
     */
    public function handle(ActionFields $fields, Collection $models)
    {
        $ticketsToRemove = $fields->get('tickets_to_remove') ?? [];

        if (empty($ticketsToRemove)) {
            return ActionResponse::danger('No tickets selected for removal.');
        }

        $processedOrders = [];
        $totalTicketsRemoved = 0;

        foreach ($models as $order) {
            try {
                $removedCount = $this->removeTicketsFromOrder($order, $ticketsToRemove);
                $processedOrders[] = "Order #{$order->id}";
                $totalTicketsRemoved += $removedCount;
            } catch (\Exception $e) {
                return ActionResponse::danger($e->getMessage());
            }
        }

        $message = sprintf(
            'Successfully removed %d ticket(s) from %d order(s): %s',
            $totalTicketsRemoved,
            count($processedOrders),
            implode(', ', $processedOrders)
        );

        return ActionResponse::message($message);
    }

    /**
     * Remove specific tickets from an order.
     *
     * @param  \App\Models\Order  $order
     * @param  array  $ticketsToRemove
     * @return int Number of tickets removed
     */
    protected function removeTicketsFromOrder($order, array $ticketsToRemove): int
    {
        $totalRemoved = 0;

        DB::transaction(function () use ($order, $ticketsToRemove, &$totalRemoved) {
            // Get fresh giveaways relationship
            $order->load(['giveaways' => function($query) {
                $query->withPivot('numbers', 'amount');
            }]);

            foreach ($order->giveaways as $giveaway) {
                $currentNumbers = json_decode($giveaway->pivot->numbers ?? '[]', true);

                if (!is_array($currentNumbers)) {
                    continue;
                }

                // Remove the specified tickets
                $remainingNumbers = array_diff($currentNumbers, $ticketsToRemove);

                $removedFromThisGiveaway = count($currentNumbers) - count($remainingNumbers);
                $totalRemoved += $removedFromThisGiveaway;

                if (count($remainingNumbers) === 0) {
                    // No tickets left for this giveaway, detach it entirely
                    $order->giveaways()->detach($giveaway->id);
                    Log::info('Giveaway completely detached from order due to ticket removal', [
                        'order_id' => $order->id,
                        'giveaway_id' => $giveaway->id,
                        'removed_tickets' => $currentNumbers
                    ]);
                } else {
                    // Update with remaining tickets
                    $order->giveaways()->updateExistingPivot($giveaway->id, [
                        'numbers' => json_encode(array_values($remainingNumbers))
                    ]);
                    Log::info('Tickets removed from order giveaway', [
                        'order_id' => $order->id,
                        'giveaway_id' => $giveaway->id,
                        'removed_tickets' => array_intersect($currentNumbers, $ticketsToRemove),
                        'remaining_tickets' => $remainingNumbers
                    ]);
                }
            }

            // Log the overall action
            Log::info('Tickets manually removed from order via Nova action', [
                'order_id' => $order->id,
                'user_id' => $order->user_id,
                'tickets_removed' => $ticketsToRemove,
                'total_removed' => $totalRemoved,
                'admin_user' => auth()->user()?->name ?? 'Unknown'
            ]);
        });

        return $totalRemoved;
    }
}