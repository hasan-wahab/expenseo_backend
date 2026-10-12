<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PropertyCard;
use App\Models\PropertyShare;
use App\Models\User;
use App\Services\Realtime\PropertyRealtimeBroadcaster;
use App\Support\Realtime\PropertyRealtimeAction;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PropertyShareController extends Controller
{
    public function __construct(private readonly PropertyRealtimeBroadcaster $realtime) {}
    /**
     * Friend ki email search karta hai taake property share ki ja sakay.
     */
    public function searchUser(Request $request)
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
        ]);

        $searchEmail = strtolower(trim($validated['email']));

        // Owner apni hi email search nahi kar sakta
        if ($searchEmail === strtolower($user->email)) {
            return response()->json([
                'message' => 'You cannot share a property with yourself.',
            ], 422);
        }

        $friend = User::where('email', $searchEmail)->first();

        if (! $friend) {
            return response()->json([
                'message' => 'No user found with this email.',
            ], 404);
        }

        return response()->json([
            'message' => 'User found successfully',
            'data' => [
                'id' => $friend->id,
                'name' => $friend->name,
                'email' => $friend->email,
                'profile_image' => $friend->profile_image
                    ? asset('storage/'.$friend->profile_image)
                    : '',
            ],
        ], 200);
    }

    /**
     * Property Card ko friend ke sath specific permissions ke sath share karta hai.
     */
    public function share(Request $request, string $cardId)
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        // Property dhoondte hain (sirf owner share kar sakta hai)
        $propertyCard = $user->propertyCards()
            ->where(function ($query) use ($cardId) {
                $query->where('card_id', $cardId)
                    ->orWhere('id', $cardId);
            })
            ->first();

        if (! $propertyCard) {
            return response()->json([
                'message' => 'Property card not found or you are not the owner.',
            ], 404);
        }

        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'exists:users,email'],
            'can_add_expense' => ['sometimes', 'boolean'],
            'can_view_expense' => ['sometimes', 'boolean'],
        ]);

        $friendEmail = strtolower(trim($validated['email']));

        if ($friendEmail === strtolower($user->email)) {
            throw ValidationException::withMessages([
                'email' => 'You cannot share a property with yourself.',
            ]);
        }

        $friend = User::where('email', $friendEmail)->firstOrFail();

        // Agar pehle se share hai to permissions update karega, warna naya share banayega
        $share = PropertyShare::updateOrCreate(
            [
                'card_id' => $propertyCard->card_id,
                'shared_with_user_id' => $friend->id,
            ],
            [
                'owner_id' => $user->id,
                'shared_with_email' => $friend->email,
                'can_add_expense' => $validated['can_add_expense'] ?? false,
                'can_view_expense' => $validated['can_view_expense'] ?? true,
                'can_update_property' => false,
                'can_delete_property' => false,
            ]
        );

        $sharePayload = $this->shareData($share, $friend);
        $this->realtime->notify(
            $propertyCard,
            $user,
            PropertyRealtimeAction::PROPERTY_SHARED,
            share: $sharePayload,
            extraRecipientIds: [(int) $friend->id],
        );

        return response()->json([
            'message' => 'Property card shared successfully with '.$friend->name,
            'data' => $sharePayload,
        ], 200);
    }

    /**
     * Property ke tamam shared members aur unki permissions list karta hai.
     */
    public function index(Request $request, string $cardId)
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $propertyCard = $user->propertyCards()
            ->where(function ($query) use ($cardId) {
                $query->where('card_id', $cardId)
                    ->orWhere('id', $cardId);
            })
            ->first();

        if (! $propertyCard) {
            return response()->json([
                'message' => 'Property card not found or you are not the owner.',
            ], 404);
        }

        $shares = $propertyCard->shares()->with('sharedUser')->latest()->get();

        return response()->json([
            'message' => 'Shared members retrieved successfully',
            'data' => $shares->map(fn (PropertyShare $share) => $this->shareData($share, $share->sharedUser)),
        ], 200);
    }

    /**
     * Kisi friend ki permissions update karta hai.
     */
    public function update(Request $request, string $cardId, string $shareId)
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $propertyCard = $user->propertyCards()
            ->where(function ($query) use ($cardId) {
                $query->where('card_id', $cardId)
                    ->orWhere('id', $cardId);
            })
            ->first();

        if (! $propertyCard) {
            return response()->json([
                'message' => 'Property card not found or you are not the owner.',
            ], 404);
        }

        $share = $propertyCard->shares()->where('id', $shareId)->first();

        if (! $share) {
            return response()->json([
                'message' => 'Share record not found.',
            ], 404);
        }

        $validated = $request->validate([
            'can_add_expense' => ['sometimes', 'boolean'],
            'can_view_expense' => ['sometimes', 'boolean'],
        ]);

        $share->update([
            ...$validated,
            'can_update_property' => false,
            'can_delete_property' => false,
        ]);

        $sharePayload = $this->shareData($share->fresh(), $share->sharedUser);
        $this->realtime->notify(
            $propertyCard,
            $user,
            PropertyRealtimeAction::SHARE_UPDATED,
            share: $sharePayload,
            extraRecipientIds: [(int) $share->shared_with_user_id],
        );

        return response()->json([
            'message' => 'Permissions updated successfully',
            'data' => $sharePayload,
        ], 200);
    }

    /**
     * Property share revoke (khatam) karta hai.
     */
    public function destroy(Request $request, string $cardId, string $shareId)
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        // Owner bhi revoke kar sakta hai aur friend khud bhi leave kar sakta hai
        $share = PropertyShare::where('id', $shareId)
            ->where(function ($query) use ($user) {
                $query->where('owner_id', $user->id)
                    ->orWhere('shared_with_user_id', $user->id);
            })
            ->first();

        if (! $share) {
            return response()->json([
                'message' => 'Share record not found or unauthorized.',
            ], 404);
        }

        $propertyCard = $share->propertyCard;
        $share->loadMissing('sharedUser');
        $sharePayload = $this->shareData($share, $share->sharedUser);
        $friendId = (int) $share->shared_with_user_id;
        $share->delete();

        if ($propertyCard) {
            $this->realtime->notify(
                $propertyCard,
                $user,
                PropertyRealtimeAction::SHARE_REVOKED,
                share: $sharePayload,
                extraRecipientIds: [$friendId],
            );
        }

        return response()->json([
            'message' => 'Property share revoked successfully',
        ], 200);
    }

    private function shareData(PropertyShare $share, ?User $friend): array
    {
        return [
            'id' => $share->id,
            'card_id' => $share->card_id,
            'owner_id' => $share->owner_id,
            'shared_with_user_id' => $share->shared_with_user_id,
            'shared_with_email' => $share->shared_with_email,
            'user' => $friend ? [
                'id' => $friend->id,
                'name' => $friend->name,
                'email' => $friend->email,
                'profile_image' => $friend->profile_image
                    ? asset('storage/'.$friend->profile_image)
                    : '',
            ] : null,
            'permissions' => [
                'can_add_expense' => (bool) $share->can_add_expense,
                'can_view_expense' => (bool) $share->can_view_expense,
                'can_update_property' => false,
                'can_delete_property' => false,
            ],
            'created_at' => $share->created_at,
            'updated_at' => $share->updated_at,
        ];
    }
}
