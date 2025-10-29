<?php

namespace App\Nova\Filters;

use Illuminate\Http\Request;
use Laravel\Nova\Filters\Filter;
use App\Models\Giveaway;

class OrderGiveawayFilter extends Filter
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

        // Filter orders that have giveaways with the specified IDs
        return $query->whereHas('giveaways', function ($q) use ($value) {
            if (is_array($value)) {
                $q->whereIn('giveaways.id', $value);
            } else {
                $q->where('giveaways.id', $value);
            }
        });
    }

    /**
     * Get the filter's available options.
     */
    public function options(Request $request)
    {
        // Return as [title => id] for giveaways
        return Giveaway::query()
            ->orderBy('title')
            ->get()
            ->pluck('id', 'title')
            ->toArray();
    }

    /**
     * Get the displayable name of the filter.
     */
    public function name()
    {
        return 'Giveaway';
    }
}