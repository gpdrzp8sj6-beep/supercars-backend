<?php

namespace App\Nova\Filters;

use Illuminate\Http\Request;
use Laravel\Nova\Filters\TextFilter;

class UserSearchFilter extends TextFilter
{
    /**
     * Apply the filter to the given query.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  mixed  $value
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function apply(Request $request, $query, $value)
    {
        $term = trim((string) $value);
        if ($term === '') {
            return $query;
        }

        return $query->whereHas('order.user', function ($q) use ($term) {
            $q->where('forenames', 'like', "%{$term}%")
              ->orWhere('surname', 'like', "%{$term}%")
              ->orWhereRaw("CONCAT(forenames, ' ', surname) LIKE ?", ["%{$term}%"]) // full name
              ->orWhere('email', 'like', "%{$term}%")
              ->orWhere('phone', 'like', "%{$term}%");
        });
    }

    /**
     * The filter's name.
     */
    public function name()
    {
        return 'User Search';
    }
}
