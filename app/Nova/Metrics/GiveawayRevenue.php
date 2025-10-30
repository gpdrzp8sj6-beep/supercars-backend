<?php

namespace App\Nova\Metrics;

use App\Models\Order;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Metrics\Value;
use Laravel\Nova\Nova;

class GiveawayRevenue extends Value
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

        // Calculate total revenue for this giveaway (gateway + credits)
        $revenue = Order::whereHas('giveaways', function ($query) use ($giveawayId) {
                $query->where('giveaway_id', $giveawayId);
            })
            ->where('status', 'completed')
            ->selectRaw('SUM(total + credit_used) as total_revenue')
            ->value('total_revenue') ?? 0;

        return $this->result($revenue)->currency('GBP');
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
        return 'giveaway-revenue';
    }

    /**
     * Get the displayable name of the metric.
     */
    public function name()
    {
        return 'Total Revenue';
    }
}
