<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

class User extends Authenticatable implements JWTSubject
{
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var string[]
     */
    protected $fillable = [
        'forenames',
        'surname',
        'date_of_birth',
        'came_from',
        'phone',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var string[]
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $appends = ['fullName'];

    /**
     * Casts for attributes.
     * Note: Fixed syntax, added missing semicolon.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Get the identifier that will be stored in the JWT subject claim.
     *
     * @return mixed
     */
    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    /**
     * Return a key-value array, containing any custom claims to be added to the JWT.
     *
     * @return array
     */
    public function getJWTCustomClaims(): array
    {
        return [];
    }

    /**
     * Accessor for full name (optional helper).
     */
    public function getFullNameAttribute(): string
    {
        return "{$this->forenames} {$this->surname}";
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    /**
     * User has many addresses.
     */
    public function addresses()
    {
        return $this->hasMany(Address::class);
    }

    /**
     * Convenience accessor for default address.
     */
    public function defaultAddress()
    {
        return $this->hasOne(Address::class)->where('is_default', true);
    }

    public function tickets(): Collection
    {
        return DB::table('giveaway_order')
            ->join('orders', 'giveaway_order.order_id', '=', 'orders.id')
            ->where('orders.user_id', $this->id)
            ->pluck('giveaway_order.numbers')
            ->filter() // Remove nulls
            ->flatMap(function ($jsonNumbers) {
                return json_decode($jsonNumbers, true) ?: [];
            })
            ->unique()
            ->values();
    }

    public function ticketsBought()
    {
        // Query giveaway_order joined with orders to filter by this user
        $result = DB::table('giveaway_order')
            ->join('orders', 'giveaway_order.order_id', '=', 'orders.id')
            ->where('orders.user_id', $this->id)
            ->select('giveaway_order.giveaway_id', 'giveaway_order.numbers')
            ->get()
            ->groupBy('giveaway_id')
            ->map(function ($items, $giveawayId) {
                // Sum total tickets bought by counting numbers arrays length
                $total = $items->reduce(function ($carry, $item) {
                    $numbers = json_decode($item->numbers, true) ?: [];
                    return $carry + count($numbers);
                }, 0);
                return [
                    'id' => (int)$giveawayId,
                    'bought' => $total,
                ];
            })
            ->values()
            ->toArray();

        return $result;
    }
}
