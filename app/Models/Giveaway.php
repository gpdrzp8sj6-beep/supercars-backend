<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Collection;

class Giveaway extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'description',
        'images',
        'closes_at',
        'price',
        'ticketsTotal',
        'ticketsTotalHidden',
        'ticketsPerUser',
        'alternative_prize',
    ];

    protected $hidden = [
        'ticketsTotalHidden',
    ];

    protected $appends = ['ticketsSold', 'ticketsTotalDisplay'];


    protected function casts () {
         return [
            'images' => 'array',
            'closes_at' => 'datetime',
            'price' => 'decimal:2',
            'alternative_prize' => 'decimal:2',
        ];
    }


public function setImage0Attribute($file)
{
    $this->uploadImageToArray($file, 0);
}

public function setImage1Attribute($file)
{
    $this->uploadImageToArray($file, 1);
}
public function setImage2Attribute($file)
{
    $this->uploadImageToArray($file, 2);
}
public function setImage3Attribute($file)
{
    $this->uploadImageToArray($file, 3);
}
public function setImage4Attribute($file)
{
    $this->uploadImageToArray($file, 4);
}
public function setImage5Attribute($file)
{
    $this->uploadImageToArray($file, 5);
}
public function setImage6Attribute($file)
{
    $this->uploadImageToArray($file, 6);
}
public function setImage7Attribute($file)
{
    $this->uploadImageToArray($file, 7);
}
public function setImage8Attribute($file)
{
    $this->uploadImageToArray($file, 8);
}
public function setImage9Attribute($file)
{
    $this->uploadImageToArray($file, 9);
}

// ... repeat or dynamically handle them via __call or another approach

protected function uploadImageToArray($file, $index)
{
    if (!$file) {
        return;
    }

    // Use storage disk to save file
    $path = $file;

    // Retrieve existing images or empty array
    $images = $this->images ?? [];

    $images[$index] = $path;

    // Save back the images array
    $this->attributes['images'] = json_encode(array_values($images));
}

    public function winningOrders()
    {
        return $this->belongsToMany(Order::class)
                       ->withPivot('numbers', 'is_winner', 'winning_ticket')
                       ->with('user') // include user relation on Order
                       ->wherePivot('is_winner', true);
    }


    public function getTicketsSoldAttribute()
    {
        $orders = $this->orders()->get();

        $totalAssigned = 0;

        foreach ($orders as $order) {
            $numbers = $order->pivot->numbers;

            if ($numbers) {
                $decoded = json_decode($numbers, true);

                if (is_array($decoded)) {
                    $totalAssigned += count($decoded);
                }
            }
        }

        return $totalAssigned;
    }

public function getTicketsTotalDisplayAttribute()
{
    if ($this->ticketsTotalHidden) {
        return 0;
    }

    return $this->attributes['ticketsTotal'] ?? null;
}

   public static function closestToClosing(int $limit = 6): Collection
   {
       return self::query()
           ->where('closes_at', '>=', now())
           ->orderBy('closes_at', 'asc') // soonest first
           ->limit($limit)
           ->get();
   }

    public static function justLaunched(int $limit = 6): Collection
      {
          return self::query()
              ->where('closes_at', '>=', now())
              ->orderBy('created_at', 'asc')
              ->limit($limit)
              ->get();
      }

    public function orders()
      {
          return $this->belongsToMany(Order::class)
                            ->withPivot('numbers')
                            ->withTimestamps();
      }
}
