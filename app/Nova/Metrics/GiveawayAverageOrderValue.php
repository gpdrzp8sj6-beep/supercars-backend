<?php

namespace App\Nova\Metrics;

use App\Models\Order;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Metrics\Value;

class GiveawayAverageOrderValue extends Value
{
    /**
     * Calculate the value of the metric.
     */
    public function calculate(NovaRequest $request)
    {
        $giveawayId = $request->resourceId;
        
        if (!$giveawayId) {
            return $this->result(0)->currency('GBP');
        }

        // Calculate average order value (gateway + credits)
        $result = Order::whereHas('giveaways', function ($query) use ($giveawayId) {
                $query->where('giveaway_id', $giveawayId);
            })
            ->where('status', 'completed')
            ->selectRaw('AVG(total + credit_used) as avg_value')
            ->value('avg_value') ?? 0;

        return $this->result($result)->currency('GBP');
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
        return 'giveaway-average-order-value';
    }

    /**
     * Get the displayable name of the metric.
     */
    public function name()
    {
        return 'Average Order Value';
    }
}
