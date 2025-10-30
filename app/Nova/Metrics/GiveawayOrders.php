<?php

namespace App\Nova\Metrics;

use App\Models\Order;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Metrics\Value;

class GiveawayOrders extends Value
{
    /**
     * Calculate the value of the metric.
     */
    public function calculate(NovaRequest $request)
    {
        $giveawayId = $request->resourceId;
        
        if (!$giveawayId) {
            return $this->result(0);
        }

        // Count completed orders for this giveaway
        $count = Order::whereHas('giveaways', function ($query) use ($giveawayId) {
                $query->where('giveaway_id', $giveawayId);
            })
            ->where('status', 'completed')
            ->count();

        return $this->result($count)->suffix('Orders');
    }

    /**
     * Get the ranges available for the metric.
     */
    public function ranges()
    {
        return [];
    }

    /**
     * Determine the amount of time the results of the metric should be cached.
     */
    public function cacheFor()
    {
        return now()->addMinutes(5);
    }

    /**
     * Get the URI key for the metric.
     */
    public function uriKey()
    {
        return 'giveaway-orders';
    }

    /**
     * Get the displayable name of the metric.
     */
    public function name()
    {
        return 'Completed Orders';
    }
}
