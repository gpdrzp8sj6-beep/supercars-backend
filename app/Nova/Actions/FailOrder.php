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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Mail\OrderCompleted;

class FailOrder extends Action
{
    use InteractsWithQueue, Queueable;

    /**
     * The displayable name of the action.
     *
     * @var string
     */
    public $name = 'Fail Order & Revoke Tickets';

    /**
     * The text to be used for the action's confirm button.
     *
     * @var string
     */
    public $confirmButtonText = 'Fail Order';

    /**
     * The text to be used for the action's confirmation text.
     *
     * @var string
     */
    public $confirmText = 'Are you sure you want to mark this order as failed? This will revoke all assigned tickets and make them available for other users. Credits used will be refunded.';

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
        // Only allow failing orders that are not already failed or completed
        $isAuthorized = !in_array($model->status, ['failed', 'completed']);

        // Log authorization check for debugging
        \Illuminate\Support\Facades\Log::info('FailOrder authorization check', [
            'order_id' => $model->id,
            'order_status' => $model->status,
            'giveaways_count' => $model->giveaways()->count(),
            'is_authorized' => $isAuthorized
        ]);

        return $isAuthorized;
    }

    /**
     * Perform the action on the given models.
     *
     * @return mixed
     */
    public function handle(ActionFields $fields, Collection $models)
    {
        $processedOrders = [];

        foreach ($models as $order) {
            try {
                $this->failOrderAndRevokeTickets($order);
                $processedOrders[] = "Order #{$order->id}";
            } catch (\Exception $e) {
                // Return error message to user
                return ActionResponse::danger($e->getMessage());
            }
        }

        $message = sprintf(
            'Successfully failed %d order(s) and revoked tickets: %s',
            count($processedOrders),
            implode(', ', $processedOrders)
        );

        return ActionResponse::message($message);
    }

    /**
     * Fail the order and revoke tickets.
     *
     * @param  \App\Models\Order  $order
     * @return void
     */
    protected function failOrderAndRevokeTickets($order)
    {
        DB::transaction(function () use ($order) {
            // Update order status to failed
            $originalStatus = $order->status;
            $order->status = 'failed';
            $order->save();

            // Log the action
            Log::info('Order manually failed via Nova action', [
                'order_id' => $order->id,
                'user_id' => $order->user_id,
                'previous_status' => $originalStatus,
                'giveaways_count' => $order->giveaways()->count(),
                'admin_user' => auth()->user()?->name ?? 'Unknown'
            ]);

            // The Order model observer will handle:
            // 1. Revoking tickets (detaching giveaways)
            // 2. Refunding credits if any were used
            // 3. Sending confirmation email
        });
    }
}