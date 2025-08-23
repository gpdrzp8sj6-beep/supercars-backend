<?php

namespace App\Nova\Filters;

use Illuminate\Http\Request;
use Laravel\Nova\Filters\Filter;
use App\Models\Giveaway;

class GiveawayFilter extends Filter
{
    /**
     * The filter's component.
     *
     * @var string
     */
    public $component = 'select-filter';

    /**
     * Apply the filter to the given query.
     */
    public function apply(Request $request, $query, $value)
    {
        if (!$value) {
            return $query;
        }
        return $query->where('giveaway_id', $value);
    }

    /**
     * Get the filter's available options.
     */
    public function options(Request $request)
    {
        // Return as [label => id]
        return Giveaway::query()
            ->orderBy('title')
            ->get()
            ->pluck('id', 'title')
            ->toArray();
    }
}
