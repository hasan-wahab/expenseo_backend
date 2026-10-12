<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PropertyCard;
use App\Models\PropertyShare;
use App\Models\User;
use App\Services\Base64ImageStorage;
use App\Services\Realtime\PropertyRealtimeBroadcaster;
use App\Support\Realtime\PropertyRealtimeAction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class PropertyCardController extends Controller
{
    public function __construct(
        private readonly Base64ImageStorage $imageStorage,
        private readonly PropertyRealtimeBroadcaster $realtime,
    ) {}

    /**
     * Authenticated user ki properties list fetch karta hai (Pure CRUD - Get).
     */
    public function index(Request $request)
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $sharedCardIds = PropertyShare::where('shared_with_user_id', $user->id)->pluck('card_id');

        $properties = PropertyCard::where('user_id', $user->id)
            ->orWhereIn('card_id', $sharedCardIds)
            ->latest()
            ->paginate($validated['per_page'] ?? 10);

        $properties->setCollection(
            $properties->getCollection()
                ->map(fn (PropertyCard $propertyCard): array => $this->propertyData($propertyCard, $user))
        );

        return response()->json([
            'message' => 'Properties retrieved successfully',
            'data' => $properties->items(),
            'pagination' => [
                'current_page' => $properties->currentPage(),
                'last_page' => $properties->lastPage(),
                'per_page' => $properties->perPage(),
                'total' => $properties->total(),
                'next_page_url' => $properties->nextPageUrl(),
                'previous_page_url' => $properties->previousPageUrl(),
            ],
        ]);
    }

    /**
     * Nayi property create karta hai (Pure CRUD - Save, Idempotent with optional card_id).
     */
    public function store(Request $request)
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $validated = $request->validate([
            'card_id' => ['sometimes', 'nullable', 'uuid'],
            'property_image' => ['sometimes', 'nullable', 'string'],
            'property_name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:100'],
            'property_location' => ['required', 'string', 'max:255'],
            'currency' => ['sometimes', 'nullable', 'string', 'max:10'],
            'target_budget' => ['required', 'numeric', 'min:0'],
            'user_email' => ['required', 'string', 'email', 'max:255'],
        ]);

        if (strtolower(trim($validated['user_email'])) !== strtolower($user->email)) {
            throw ValidationException::withMessages([
                'user_email' => 'The user email must match the authenticated account.',
            ]);
        }

        if (! empty($validated['card_id'])) {
            $existing = PropertyCard::where('card_id', $validated['card_id'])
                ->where('user_id', $user->id)
                ->first();

            if ($existing) {
                return response()->json([
                    'message' => 'Property card retrieved successfully',
                    'data' => $this->propertyData($existing, $user),
                ], 200);
            }
        }

        $imagePath = $this->imageStorage->store(
            $validated['property_image'] ?? null,
            'property_images',
            'property_image',
            'property image'
        );

        $currency = strtoupper(trim($validated['currency'] ?? 'PKR')) ?: 'PKR';

        try {
            $propertyCard = DB::transaction(fn () => PropertyCard::create([
                'card_id' => ! empty($validated['card_id'])
                    ? $validated['card_id']
                    : (string) str()->uuid(),
                'user_id' => $user->id,
                'owner_email' => $user->email,
                'property_image' => $imagePath,
                'property_name' => $validated['property_name'],
                'category' => $validated['category'],
                'property_location' => $validated['property_location'],
                'currency' => $currency,
                'target_budget' => $validated['target_budget'],
                'total_expense' => 0,
                'monthly_expense' => 0,
                'today_expenses' => 0,
                'progress' => 0,
            ]));
        } catch (Throwable $exception) {
            if ($imagePath !== null && ! Storage::disk('public')->delete($imagePath)) {
                throw new \RuntimeException('Failed to clean up the property image after a database error.', 0, $exception);
            }

            throw $exception;
        }

        return response()->json([
            'message' => 'Property card created successfully',
            'data' => $this->propertyData($propertyCard, $user),
        ], 201);
    }

    /**
     * Property update karta hai (Pure CRUD - Update).
     */
    public function update(Request $request, string $cardId)
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $propertyCard = PropertyCard::where(function ($query) use ($cardId) {
                $query->where('card_id', $cardId)
                    ->orWhere('id', $cardId);
            })
            ->first();

        if (! $propertyCard) {
            return response()->json([
                'message' => 'Property card not found',
            ], 404);
        }

        if (! $propertyCard->isAccessibleBy($user)) {
            return response()->json([
                'message' => 'Property card not found',
            ], 404);
        }

        if (! $propertyCard->isOwner($user)) {
            return response()->json([
                'message' => 'You do not have permission to update this property.',
            ], 403);
        }

        $updatableFields = [
            'property_name',
            'category',
            'property_location',
            'currency',
            'target_budget',
            'property_image',
        ];

        if (! array_intersect($updatableFields, array_keys($request->all()))) {
            throw ValidationException::withMessages([
                'property' => 'Kam az kam aik field update karne ke liye bhejna zaroori hai.',
            ]);
        }

        $validated = $request->validate([
            'property_name' => ['sometimes', 'required', 'string', 'max:255'],
            'category' => ['sometimes', 'required', 'string', 'max:100'],
            'property_location' => ['sometimes', 'required', 'string', 'max:255'],
            'currency' => ['sometimes', 'required', 'string', 'max:10'],
            'target_budget' => ['sometimes', 'required', 'numeric', 'min:0'],
            'property_image' => ['sometimes', 'nullable', 'string'],
            'user_email' => ['sometimes', 'string', 'email', 'max:255'],
        ]);

        if (isset($validated['user_email']) && strtolower(trim($validated['user_email'])) !== strtolower($user->email)) {
            throw ValidationException::withMessages([
                'user_email' => 'The user email must match the authenticated account.',
            ]);
        }

        $previousImagePath = $propertyCard->property_image;
        $updates = array_intersect_key($validated, array_flip($updatableFields));

        if (array_key_exists('currency', $updates)) {
            $updates['currency'] = strtoupper(trim((string) $updates['currency'])) ?: 'PKR';
        }

        if (array_key_exists('property_image', $validated)) {
            $updates['property_image'] = $this->imageStorage->store(
                $validated['property_image'],
                'property_images',
                'property_image',
                'property image'
            );
        }

        $propertyCard->fill($updates);
        $propertyCard->save();
        $propertyCard->recalculateTotals();

        if (
            $previousImagePath
            && array_key_exists('property_image', $updates)
            && $previousImagePath !== $updates['property_image']
        ) {
            Storage::disk('public')->delete($previousImagePath);
        }

        $propertyCard = $propertyCard->fresh();
        $this->realtime->notify($propertyCard, $user, PropertyRealtimeAction::PROPERTY_UPDATED);

        return response()->json([
            'message' => 'Property card updated successfully',
            'data' => $this->propertyData($propertyCard, $user),
        ], 200);
    }

    /**
     * Property delete karta hai (Pure CRUD - Delete).
     */
    public function destroy(Request $request, string $cardId)
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $propertyCard = PropertyCard::where(function ($query) use ($cardId) {
                $query->where('card_id', $cardId)
                    ->orWhere('id', $cardId);
            })
            ->first();

        if (! $propertyCard) {
            return response()->json([
                'message' => 'Property card not found',
            ], 404);
        }

        if (! $propertyCard->isAccessibleBy($user)) {
            return response()->json([
                'message' => 'Property card not found',
            ], 404);
        }

        if (! $propertyCard->isOwner($user)) {
            return response()->json([
                'message' => 'You do not have permission to delete this property.',
            ], 403);
        }

        if ($propertyCard->property_image) {
            Storage::disk('public')->delete($propertyCard->property_image);
        }

        $recipientIds = $this->realtime->recipientIds($propertyCard);
        PropertyShare::where('card_id', $propertyCard->card_id)->delete();
        \App\Models\Expense::where('card_id', $propertyCard->card_id)->delete();
        $propertyCard->delete();

        $this->realtime->notifyDeleted($propertyCard, $user, $recipientIds);

        return response()->json([
            'message' => 'Property card deleted successfully',
        ], 200);
    }

    private function propertyData(PropertyCard $propertyCard, User $user): array
    {
        $isOwner = (int) $propertyCard->user_id === (int) $user->id;
        $share = $isOwner ? null : PropertyShare::where('card_id', $propertyCard->card_id)
            ->where('shared_with_user_id', $user->id)
            ->first();

        return [
            'card_id' => $propertyCard->card_id,
            'user_id' => $propertyCard->user_id,
            'owner_email' => $propertyCard->owner_email,
            'property_image' => $propertyCard->property_image
                ? asset('storage/'.$propertyCard->property_image)
                : null,
            'property_name' => $propertyCard->property_name,
            'category' => $propertyCard->category,
            'property_location' => $propertyCard->property_location,
            'currency' => $propertyCard->currency ?? 'PKR',
            'target_budget' => (float) $propertyCard->target_budget,
            'total_expense' => (float) $propertyCard->total_expense,
            'monthly_expense' => (float) $propertyCard->monthly_expense,
            'today_expenses' => (float) $propertyCard->today_expenses,
            'progress' => (float) $propertyCard->progress,
            'is_owner' => $isOwner,
            'role' => $isOwner ? 'owner' : 'friend',
            'permissions' => [
                'can_add_expense' => $isOwner ? true : (bool) ($share?->can_add_expense ?? false),
                'can_view_expense' => $isOwner ? true : (bool) ($share?->can_view_expense ?? false),
                'can_update_property' => $isOwner,
                'can_delete_property' => $isOwner,
            ],
            'shared_members' => $propertyCard->sharedMemberEmails(),
            'created_at' => $propertyCard->created_at,
            'updated_at' => $propertyCard->updated_at,
        ];
    }
}
