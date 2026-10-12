<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class PropertyCard extends Model
{
    protected $fillable = [
        'card_id',
        'user_id',
        'owner_email',
        'property_image',
        'property_name',
        'category',
        'property_location',
        'currency',
        'target_budget',
        'total_expense',
        'monthly_expense',
        'today_expenses',
        'progress',
    ];

    protected function casts(): array
    {
        return [
            'target_budget' => 'decimal:2',
            'total_expense' => 'decimal:2',
            'monthly_expense' => 'decimal:2',
            'today_expenses' => 'decimal:2',
            'progress' => 'decimal:1',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'card_id', 'card_id');
    }

    public function shares(): HasMany
    {
        return $this->hasMany(PropertyShare::class, 'card_id', 'card_id');
    }

    /**
     * Check karta hai ke user is property ka owner hai ya nahi.
     */
    public function isOwner(User $user): bool
    {
        return (int) $this->user_id === (int) $user->id;
    }

    /**
     * Check karta hai ke user ke paas matlooba permission mojood hai ya nahi.
     */
    public function hasPermission(User $user, string $permission): bool
    {
        if ($this->isOwner($user)) {
            return true;
        }

        $share = $this->shares()
            ->where('shared_with_user_id', $user->id)
            ->first();

        return (bool) ($share && $share->{$permission});
    }

    /**
     * Owner ya shared friend is property ko realtime channel par sun sakta hai.
     */
    public function isAccessibleBy(User $user): bool
    {
        if ($this->isOwner($user)) {
            return true;
        }

        return $this->shares()
            ->where('shared_with_user_id', $user->id)
            ->exists();
    }

    /**
     * Property ke tamam expenses se total, monthly, today aur budget progress recalculate karta hai.
     */
    public function recalculateTotals(): void
    {
        $now = Carbon::now();
        $todayStr = $now->format('Y-m-d');
        $monthStart = $now->copy()->startOfMonth()->format('Y-m-d');
        $monthEnd = $now->copy()->endOfMonth()->format('Y-m-d');

        $total = (float) $this->expenses()->sum('amount');
        $monthly = (float) $this->expenses()
            ->whereBetween('date', [$monthStart, $monthEnd])
            ->sum('amount');
        $today = (float) $this->expenses()
            ->whereDate('date', $todayStr)
            ->sum('amount');

        $budget = (float) $this->target_budget;
        $progress = $budget > 0
            ? min(100.0, round(($monthly / $budget) * 100, 1))
            : 0.0;

        $this->forceFill([
            'total_expense' => $total,
            'monthly_expense' => $monthly,
            'today_expenses' => $today,
            'progress' => $progress,
        ])->save();
    }

    /**
     * Property jin members ke sath share ki gayi hai unke emails ki list.
     *
     * @return list<string>
     */
    public function sharedMemberEmails(): array
    {
        return $this->shares()
            ->pluck('shared_with_email')
            ->filter(fn (?string $email): bool => ! empty($email))
            ->map(fn (string $email): string => strtolower(trim($email)))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Broadcast payload — viewer-specific permissions nahi, taake har listener ko same data mile.
     *
     * @return array<string, mixed>
     */
    public function realtimePayload(): array
    {
        return [
            'card_id' => $this->card_id,
            'user_id' => $this->user_id,
            'owner_email' => $this->owner_email,
            'property_image' => $this->property_image
                ? asset('storage/'.$this->property_image)
                : null,
            'property_name' => $this->property_name,
            'category' => $this->category,
            'property_location' => $this->property_location,
            'currency' => $this->currency ?? 'PKR',
            'target_budget' => (float) $this->target_budget,
            'total_expense' => (float) $this->total_expense,
            'monthly_expense' => (float) $this->monthly_expense,
            'today_expenses' => (float) $this->today_expenses,
            'progress' => (float) $this->progress,
            'shared_members' => $this->sharedMemberEmails(),
            'updated_at' => $this->updated_at,
        ];
    }
}

