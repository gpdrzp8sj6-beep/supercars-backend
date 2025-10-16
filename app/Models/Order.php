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
use App\Models\CreditTransaction;

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
        // Log when checkoutId is set
        static::updated(function (Order $order) {
            if ($order->wasChanged('checkoutId')) {
                Log::info('Order checkoutId changed', [
                    'order_id' => $order->id,
                    'user_id' => $order->user_id,
                    'old_checkout_id' => $order->getOriginal('checkoutId'),
                    'new_checkout_id' => $order->checkoutId,
                    'status' => $order->status,
                    'timestamp' => now()->toISOString()
                ]);
            }
        });

        // On update: when status transitions to completed or failed, send payment confirmation email
        static::updated(function (Order $order) {
            if ($order->wasChanged('status')) {
                $original = $order->getOriginal('status');
                $newStatus = $order->status;
                
                Log::info('Order status changed', [
                    'order_id' => $order->id,
                    'user_id' => $order->user_id,
                    'from_status' => $original,
                    'to_status' => $newStatus,
                    'checkout_id' => $order->checkoutId,
                    'has_tickets_assigned' => $order->giveaways()->count() > 0,
                    'timestamp' => now()->toISOString()
                ]);
                
                // Refund credits if order fails and credits were used
                if ($newStatus === 'failed' && $order->credit_used > 0) {
                    $order->user->credit += $order->credit_used;
                    $order->user->save();
                    
                    CreditTransaction::create([
                        'user_id' => $order->user_id,
                        'amount' => $order->credit_used,
                        'type' => 'add',
                        'description' => 'Refund for failed order ' . $order->id,
                    ]);
                    
                    Log::info('Credits refunded for failed order', [
                        'order_id' => $order->id,
                        'amount' => $order->credit_used,
                        'user_id' => $order->user_id
                    ]);
                }

                // Revoke tickets if order fails and tickets were assigned
                if ($newStatus === 'failed' && in_array($original, ['pending', 'completed'])) {
                    $order->giveaways()->detach();
                    Log::info('Tickets revoked for failed order', [
                        'order_id' => $order->id,
                        'previous_status' => $original
                    ]);
                }
                
                // Send order received email when status changes to pending
                if ($newStatus === 'pending' && $original === 'created') {
                    try {
                        $email = $order->user?->email;
                        if ($email) {
                            Mail::to($email)->send(new OrderReceived($order));
                            Log::info('Order received email sent for pending status.', ['order_id' => $order->id]);
                        } else {
                            Log::warning('Order status changed to pending but user email missing.', ['order_id' => $order->id]);
                        }
                    } catch (\Throwable $ex) {
                        Log::error('Failed to send order received email for pending status: ' . $ex->getMessage(), [
                            'order_id' => $order->id,
                            'exception' => $ex,
                        ]);
                    }
                }
                
                // Send payment confirmation email when payment is resolved (completed or failed)
                if ($original !== $newStatus && in_array($newStatus, ['completed', 'failed']) && !app()->bound('webhook_processing')) {
                    try {
                        $email = $order->user?->email;
                        if ($email) {
                            // Ensure giveaways relationship is fresh loaded with pivot data for email
                            $order->load(['giveaways' => function($query) {
                                $query->withPivot(['numbers', 'amount']);
                            }]);
                            
                            // Log ticket information before sending email
                            $ticketInfo = [];
                            foreach ($order->giveaways as $giveaway) {
                                $numbers = json_decode($giveaway->pivot->numbers ?? '[]', true);
                                $ticketInfo[] = "Giveaway {$giveaway->id}: " . implode(', ', $numbers);
                            }
                            Log::info('Sending payment confirmation email from model', [
                                'order_id' => $order->id,
                                'user_email' => $email,
                                'payment_status' => $newStatus,
                                'giveaways_count' => $order->giveaways->count(),
                                'ticket_numbers' => $ticketInfo,
                                'has_pivot_numbers' => $order->giveaways->first()?->pivot?->numbers ? 'yes' : 'no'
                            ]);
                            
                            Mail::to($email)->send(new OrderCompleted($order));
                            Log::info('Payment confirmation email sent successfully from model.', [
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
                        Log::error('Failed to send payment confirmation email from model: ' . $ex->getMessage(), [
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
