<?php

namespace App\Nova\Filters;

use Illuminate\Http\Request;
use Laravel\Nova\Filters\BooleanFilter;

class WinnerFilter extends BooleanFilter
{
    /**
     * Apply the filter to the given query.
     */
    public function apply(Request $request, $query, $value)
    {
        // $value is an array of toggled options
        if (!empty($value['winner']) && empty($value['not_winner'])) {
            return $query->where('is_winner', true);
        }
        if (empty($value['winner']) && !empty($value['not_winner'])) {
            return $query->where('is_winner', false);
        }
        // if both or none selected, don't constrain
        return $query;
    }

    /**
     * The filter's available options.
     */
    public function options(Request $request)
    {
        return [
            'Winner' => 'winner',
            'Not Winner' => 'not_winner',
        ];
    }
}
