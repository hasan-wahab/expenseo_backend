<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Expense extends Model
{
    use HasFactory;

    /**
     * Mass assignable attributes jo database mein save honge.
     */
    protected $fillable = [
        'expense_id',
        'user_id',
        'card_id',
        'property_name',
        'user_email',
        'amount',
        'category',
        'date',
        'receipt_image',
        'note',
    ];

    /**
     * Data types ki casting.
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'date' => 'date:Y-m-d',
        ];
    }

    /**
     * Expense kis user ka hai uski relationship.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Expense kis property card se taluq rakhta hai.
     */
    public function propertyCard(): BelongsTo
    {
        return $this->belongsTo(PropertyCard::class, 'card_id', 'card_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function realtimePayload(): array
    {
        return [
            'expense_id' => $this->expense_id,
            'id' => $this->id,
            'user_id' => $this->user_id,
            'card_id' => $this->card_id,
            'property_name' => $this->property_name,
            'user_email' => $this->user_email,
            'amount' => (float) $this->amount,
            'category' => $this->category,
            'date' => $this->date?->format('Y-m-d') ?? (string) $this->date,
            'receipt_image' => $this->receipt_image
                ? asset('storage/'.$this->receipt_image)
                : null,
            'note' => $this->note,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
