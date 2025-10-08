<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use App\Mail\OrderCompleted;
use App\Mail\OrderReceived;

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
        // On create: always send order received email
        static::created(function (Order $order) {
            try {
                $email = $order->user?->email;
                if ($email) {
                    Mail::to($email)->send(new OrderReceived($order));
                    Log::info('Order received email sent.', ['order_id' => $order->id]);
                } else {
                    Log::warning('Order created but user email missing.', ['order_id' => $order->id]);
                }
            } catch (\Throwable $ex) {
                Log::error('Failed to send order received email: ' . $ex->getMessage(), [
                    'order_id' => $order->id,
                    'exception' => $ex,
                ]);
            }
        });

        // On update: when status transitions to completed or failed, send payment confirmation email
        static::updated(function (Order $order) {
            if ($order->wasChanged('status')) {
                $original = $order->getOriginal('status');
                $newStatus = $order->status;
                
                // Send payment confirmation email when payment is resolved (completed or failed)
                if ($original !== $newStatus && in_array($newStatus, ['completed', 'failed'])) {
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
                            Log::info('Sending payment confirmation email', [
                                'order_id' => $order->id,
                                'user_email' => $email,
                                'payment_status' => $newStatus,
                                'giveaways_count' => $order->giveaways->count(),
                                'ticket_numbers' => $ticketInfo
                            ]);
                            
                            Mail::to($email)->send(new OrderCompleted($order));
                            Log::info('Payment confirmation email sent successfully.', [
                                'order_id' => $order->id,
                                'status' => $newStatus
                            ]);
                        } else {
                            Log::warning('Payment status updated but user email missing.', [
                                'order_id' => $order->id,
                                'status' => $newStatus
                            ]);
                        }
                    } catch (\Throwable $ex) {
                        Log::error('Failed to send payment confirmation email: ' . $ex->getMessage(), [
                            'order_id' => $order->id,
                            'status' => $newStatus,
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
