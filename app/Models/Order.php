<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class Order extends Model
{
      protected $fillable = [
          'user_id',
          'status',
          'total',
          'forenames',
          'surname',
          'phone',
          'address_line_1',
          'address_line_2',
          'city',
          'post_code',
          'country',
          'checkoutId'
      ];

    protected function casts() {
        return [
                'total' => 'float',
                'status' => 'string',
            ];
    }


    public function giveaways(): BelongsToMany
    {
        return $this->belongsToMany(Giveaway::class)
                    ->withPivot('numbers')
                    ->withTimestamps();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
