<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use App\Mail\OrderCompleted;

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
          'checkoutId',
          'cart',
          'credit_used',
      ];

    protected function casts() {
        return [
                'total' => 'float',
                'status' => 'string',
                'cart' => 'array',
                'credit_used' => 'decimal:2',
            ];
    }

    protected $appends = ['original_total'];

    /**
     * Get the original order total before credit deduction.
     */
    public function getOriginalTotalAttribute(): float
    {
        return (float) $this->total + (float) $this->credit_used;
    }

    protected static function booted(): void
    {
        // On create: if status already completed, send email
        static::created(function (Order $order) {
            if (($order->status ?? null) === 'completed') {
                try {
                    $email = $order->user?->email;
                    if ($email) {
                        Mail::to($email)->send(new OrderCompleted($order));
                        Log::info('Order completed email sent (on create).', ['order_id' => $order->id]);
                    } else {
                        Log::warning('Order completed (on create) but user email missing.', ['order_id' => $order->id]);
                    }
                } catch (\Throwable $ex) {
                    Log::error('Failed to send order completed email (on create): ' . $ex->getMessage(), [
                        'order_id' => $order->id,
                        'exception' => $ex,
                    ]);
                }
            }
        });

        // On update: when status transitions to completed, send email
        static::updated(function (Order $order) {
            if ($order->wasChanged('status')) {
                $original = $order->getOriginal('status');
                if ($original !== 'completed' && $order->status === 'completed') {
                    try {
                        $email = $order->user?->email;
                        if ($email) {
                            // Ensure giveaways relationship is fresh loaded for email
                            $order->load('giveaways');
                            
                            // Log ticket information before sending email
                            $ticketInfo = [];
                            foreach ($order->giveaways as $giveaway) {
                                $numbers = json_decode($giveaway->pivot->numbers ?? '[]', true);
                                $ticketInfo[] = "Giveaway {$giveaway->id}: " . implode(', ', $numbers);
                            }
                            Log::info('Sending order completed email with tickets', [
                                'order_id' => $order->id,
                                'user_email' => $email,
                                'giveaways_count' => $order->giveaways->count(),
                                'ticket_numbers' => $ticketInfo
                            ]);
                            
                            Mail::to($email)->send(new OrderCompleted($order));
                            Log::info('Order completed email sent successfully.', ['order_id' => $order->id]);
                        } else {
                            Log::warning('Order status updated to completed but user email missing.', ['order_id' => $order->id]);
                        }
                    } catch (\Throwable $ex) {
                        Log::error('Failed to send order completed email (on update): ' . $ex->getMessage(), [
                            'order_id' => $order->id,
                            'exception' => $ex,
                        ]);
                    }
                }
            }
        });
    }

    public function giveaways(): BelongsToMany
    {
        return $this->belongsToMany(Giveaway::class)
                    ->withPivot('numbers', 'amount')
                    ->withTimestamps();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
