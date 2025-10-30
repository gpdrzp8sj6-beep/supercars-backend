<?php

namespace App\Nova\Metrics;

use App\Models\Order;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Metrics\Value;
use Illuminate\Support\Facades\Log;

class TotalRevenue extends Value
{
    /**
     * Calculate the value of the metric.
     *
     * @param  \Laravel\Nova\Http\Requests\NovaRequest  $request
     * @return mixed
     */
    public function calculate(NovaRequest $request)
    {
    // Calculate total revenue including both gateway payments and credits used
    $orders = Order::where('status', 'completed')
        ->selectRaw('SUM(total) as gateway_total, SUM(credit_used) as credit_total')
        ->first();
    
    $sum = ($orders->gateway_total ?? 0) + ($orders->credit_total ?? 0);
    
    Log::info('Nova Metric TotalRevenue computed', [
        'gateway_total' => $orders->gateway_total,
        'credit_total' => $orders->credit_total,
        'total_revenue' => $sum
    ]);
    
    // Nova's Value supports currency via ->currency(), but returning the raw value
    // ensures it's visible. Nova will format the number itself in the UI.
    return $this->result($sum)->currency('GBP');
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
        return 'total-revenue';
    }

    /**
     * Get the displayable name of the metric.
     *
     * @return string
     */
    public function name()
    {
        return 'Total Revenue';
    }
}