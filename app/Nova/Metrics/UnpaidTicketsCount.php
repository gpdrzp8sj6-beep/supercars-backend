<?php

namespace App\Nova\Metrics;

use App\Models\Order;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Metrics\Value;
use Illuminate\Support\Facades\DB;

class UnpaidTicketsCount extends Value
{
    /**
     * Calculate the value of the metric.
     *
     * @param  \Laravel\Nova\Http\Requests\NovaRequest  $request
     * @return mixed
     */
    public function calculate(NovaRequest $request)
    {
        // Get orders with unpaid tickets (not completed status)
        $unpaidOrders = Order::whereIn('status', ['pending', 'failed', 'cancelled'])
            ->whereHas('giveaways')
            ->with('giveaways')
            ->get();

        $totalTickets = 0;

        foreach ($unpaidOrders as $order) {
            foreach ($order->giveaways as $giveaway) {
                $ticketCount = count(json_decode($giveaway->pivot->numbers ?? '[]', true) ?: []);
                $totalTickets += $ticketCount;
            }
        }

        return $this->result($totalTickets)->format('0,0');
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
        return 'unpaid-tickets-count';
    }

    /**
     * Get the displayable name of the metric.
     *
     * @return string
     */
    public function name()
    {
        return 'Unpaid Tickets Count';
    }
}