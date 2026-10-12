<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PropertyShare extends Model
{
    use HasFactory;

    /**
     * Mass assignable attributes jo property sharing ke liye save honge.
     */
    protected $fillable = [
        'card_id',
        'owner_id',
        'shared_with_user_id',
        'shared_with_email',
        'can_add_expense',
        'can_view_expense',
        'can_update_property',
        'can_delete_property',
    ];

    /**
     * Permissions ke boolean casts.
     */
    protected function casts(): array
    {
        return [
            'can_add_expense' => 'boolean',
            'can_view_expense' => 'boolean',
            'can_update_property' => 'boolean',
            'can_delete_property' => 'boolean',
        ];
    }

    /**
     * Asal owner user ki relationship.
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * Jis friend ke sath share kiya gaya hai uski relationship.
     */
    public function sharedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shared_with_user_id');
    }

    /**
     * Jis property card ko share kiya gaya hai uski relationship.
     */
    public function propertyCard(): BelongsTo
    {
        return $this->belongsTo(PropertyCard::class, 'card_id', 'card_id');
    }
}
