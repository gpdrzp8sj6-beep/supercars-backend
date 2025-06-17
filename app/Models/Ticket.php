<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Ticket extends Model
{
    protected $table = 'giveaway_order';

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function giveaway()
    {
        return $this->belongsTo(Giveaway::class);
    }

}
