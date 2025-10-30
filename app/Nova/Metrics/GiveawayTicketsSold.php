<?php

namespace App\Nova\Metrics;

use App\Models\Giveaway;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Metrics\Value;

class GiveawayTicketsSold extends Value
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

        $giveaway = Giveaway::find($giveawayId);
        
        if (!$giveaway) {
            return $this->result(0);
        }

        $sold = $giveaway->ticketsSold ?? 0;
        $total = $giveaway->ticketsTotal ?? 0;
        $percentage = $total > 0 ? round(($sold / $total) * 100, 1) : 0;

        return $this->result($sold)
            ->suffix('/ ' . number_format($total) . ' (' . $percentage . '%)');
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
        return 'giveaway-tickets-sold';
    }

    /**
     * Get the displayable name of the metric.
     */
    public function name()
    {
        return 'Tickets Sold';
    }
}
