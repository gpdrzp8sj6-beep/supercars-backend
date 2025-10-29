<?php

namespace App\Nova\Metrics;

use App\Models\Order;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Metrics\Value;
use Illuminate\Support\Facades\DB;

class CreditPaidOrdersCount extends Value
{
    /**
     * Calculate the value of the metric.
     *
     * @param  \Laravel\Nova\Http\Requests\NovaRequest  $request
     * @return mixed
     */
    public function calculate(NovaRequest $request)
    {
        $query = Order::where('credit_used', '>', 0);

        // Check if OrderGiveawayFilter is applied
        $filtersParam = $request->query('orders_filter');
        if ($filtersParam) {
            $decodedFilters = json_decode(base64_decode($filtersParam), true);
            if (is_array($decodedFilters) && isset($decodedFilters[0]['App\\Nova\\Filters\\OrderGiveawayFilter'])) {
                $giveawayId = $decodedFilters[0]['App\\Nova\\Filters\\OrderGiveawayFilter'];
                $query->whereHas('giveaways', function ($q) use ($giveawayId) {
                    $q->where('giveaways.id', $giveawayId);
                });
            }
        }

        $count = $query->count();

        return $this->result($count)->format('0,0');
    }

    /**
     * Get the ranges available for the metric.
     *
     * @return array
     */
    public function ranges()
    {
        return [];
    }

    /**
     * Determine the amount of time the results of the metric should be cached.
     *
     * @return \DateTimeInterface|null
     */
    public function cacheFor()
    {
        return now()->addMinutes(5);
    }

    /**
     * Get the URI key for the metric.
     *
     * @return string
     */
    public function uriKey()
    {
        return 'credit-paid-orders-count';
    }

    /**
     * Get the displayable name of the metric.
     *
     * @return string
     */
    public function name()
    {
        return 'Credit Paid Orders Count';
    }
}