<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\PropertyCard;
use App\Models\PropertyShare;
use App\Models\User;
use App\Services\Base64ImageStorage;
use App\Services\Realtime\PropertyRealtimeBroadcaster;
use App\Support\Realtime\PropertyRealtimeAction;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class ExpenseController extends Controller
{
    public function __construct(
        private readonly Base64ImageStorage $imageStorage,
        private readonly PropertyRealtimeBroadcaster $realtime,
    ) {}

    /**
     * Authenticated user ke expenses ki list hasil karta hai (Pure CRUD - Get).
     */
    public function index(Request $request)
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $validated = $request->validate([
            'card_id' => ['sometimes', 'string'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);

        if (! empty($validated['card_id'])) {
            $propertyCard = PropertyCard::where(function ($query) use ($validated) {
                $query->where('card_id', $validated['card_id'])
                    ->orWhere('id', $validated['card_id']);
            })->first();

            if (! $propertyCard || ! $propertyCard->isAccessibleBy($user)) {
                return response()->json(['message' => 'Property card not found'], 404);
            }

            if (! $propertyCard->hasPermission($user, 'can_view_expense')) {
                return response()->json([
                    'message' => 'You do not have permission to view expenses for this property.',
                ], 403);
            }

            $query = $propertyCard->expenses()->latest('date')->latest('id');
        } else {
            $ownedCardIds = $user->propertyCards()->pluck('card_id');
            $sharedCardIds = PropertyShare::where('shared_with_user_id', $user->id)
                ->where('can_view_expense', true)
                ->pluck('card_id');

            $allAccessibleCardIds = $ownedCardIds->merge($sharedCardIds)->unique();

            $query = Expense::whereIn('card_id', $allAccessibleCardIds)
                ->latest('date')
                ->latest('id');
        }

        $expenses = $query->paginate($validated['per_page'] ?? 15);

        $expenses->setCollection(
            $expenses->getCollection()->map(fn (Expense $expense): array => $this->expenseData($expense))
        );

        return response()->json([
            'message' => 'Expenses retrieved successfully',
            'data' => $expenses->items(),
            'pagination' => [
                'current_page' => $expenses->currentPage(),
                'last_page' => $expenses->lastPage(),
                'per_page' => $expenses->perPage(),
                'total' => $expenses->total(),
                'next_page_url' => $expenses->nextPageUrl(),
                'previous_page_url' => $expenses->previousPageUrl(),
            ],
        ]);
    }

    /**
     * Naya expense create karta hai (Pure CRUD - Save, Idempotent with optional expense_id).
     */
    public function store(Request $request)
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $validated = $request->validate([
            'expense_id' => ['sometimes', 'nullable', 'uuid'],
            'card_id' => ['required', 'string'],
            'property_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'user_email' => ['sometimes', 'nullable', 'string', 'email', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'category' => ['required', 'string', 'max:100'],
            'date' => ['required', 'date'],
            'receipt_image' => ['sometimes', 'nullable', 'string'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $propertyCard = PropertyCard::where(function ($query) use ($validated) {
                $query->where('card_id', $validated['card_id'])
                    ->orWhere('id', $validated['card_id']);
            })
            ->first();

        if (! $propertyCard || ! $propertyCard->isAccessibleBy($user)) {
            throw ValidationException::withMessages([
                'card_id' => 'The selected property card does not exist.',
            ]);
        }

        if (! $propertyCard->hasPermission($user, 'can_add_expense')) {
            return response()->json([
                'message' => 'You do not have permission to add expenses to this property.',
            ], 403);
        }

        if (
            ! empty($validated['user_email'])
            && strtolower(trim($validated['user_email'])) !== strtolower($user->email)
        ) {
            throw ValidationException::withMessages([
                'user_email' => 'The user email must match the authenticated account.',
            ]);
        }

        if (! empty($validated['expense_id'])) {
            $existing = Expense::where('expense_id', $validated['expense_id'])->first();
            if ($existing) {
                return response()->json([
                    'message' => 'Expense retrieved successfully',
                    'data' => $this->expenseData($existing),
                ], 200);
            }
        }

        $receiptPath = $this->imageStorage->store(
            $validated['receipt_image'] ?? null,
            'receipt_images',
            'receipt_image',
            'receipt image'
        );

        try {
            $expense = DB::transaction(function () use ($user, $propertyCard, $validated, $receiptPath) {
                $created = Expense::create([
                    'expense_id' => ! empty($validated['expense_id'])
                        ? $validated['expense_id']
                        : (string) str()->uuid(),
                    'user_id' => $user->id,
                    'card_id' => $propertyCard->card_id,
                    'property_name' => $validated['property_name'] ?? $propertyCard->property_name,
                    'user_email' => $user->email,
                    'amount' => $validated['amount'],
                    'category' => $validated['category'],
                    'date' => Carbon::parse($validated['date'])->format('Y-m-d'),
                    'receipt_image' => $receiptPath,
                    'note' => $validated['note'] ?? null,
                ]);

                $propertyCard->recalculateTotals();

                return $created;
            });
        } catch (Throwable $exception) {
            if ($receiptPath !== null && ! Storage::disk('public')->delete($receiptPath)) {
                throw new \RuntimeException('Failed to clean up the receipt image after an error.', 0, $exception);
            }

            throw $exception;
        }

        $this->realtime->notify(
            $propertyCard->fresh(),
            $user,
            PropertyRealtimeAction::EXPENSE_CREATED,
            $expense,
        );

        return response()->json([
            'message' => 'Expense created successfully',
            'data' => $this->expenseData($expense),
        ], 201);
    }

    /**
     * Kisi aik expense ki mukammal tafseelat hasil karta hai.
     */
    public function show(Request $request, string $expenseId)
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $expense = Expense::where('expense_id', $expenseId)
            ->orWhere('id', $expenseId)
            ->first();

        if (! $expense) {
            return response()->json([
                'message' => 'Expense not found',
            ], 404);
        }

        $propertyCard = $expense->propertyCard;
        $canView = (int) $expense->user_id === (int) $user->id
            || ($propertyCard && $propertyCard->hasPermission($user, 'can_view_expense'));

        if (! $canView) {
            return response()->json([
                'message' => 'You do not have permission to view this expense.',
            ], 403);
        }

        return response()->json([
            'message' => 'Expense retrieved successfully',
            'data' => $this->expenseData($expense),
        ]);
    }

    /**
     * Mojooda expense ko update karta hai (Pure CRUD - Update).
     */
    public function update(Request $request, string $expenseId)
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $expense = Expense::where('expense_id', $expenseId)
            ->orWhere('id', $expenseId)
            ->first();

        if (! $expense) {
            return response()->json([
                'message' => 'Expense not found',
            ], 404);
        }

        $propertyCard = $expense->propertyCard;
        $canEdit = $propertyCard
            ? $propertyCard->isOwner($user)
            : ((int) $expense->user_id === (int) $user->id);

        if (! $canEdit) {
            return response()->json([
                'message' => 'You do not have permission to update this expense.',
            ], 403);
        }

        $updatableFields = [
            'amount',
            'category',
            'date',
            'receipt_image',
            'note',
            'property_name',
        ];

        if (! array_intersect($updatableFields, array_keys($request->all()))) {
            throw ValidationException::withMessages([
                'expense' => 'Kam az kam aik field update karne ke liye bhejna zaroori hai.',
            ]);
        }

        $validated = $request->validate([
            'amount' => ['sometimes', 'required', 'numeric', 'min:0.01'],
            'category' => ['sometimes', 'required', 'string', 'max:100'],
            'date' => ['sometimes', 'required', 'date'],
            'receipt_image' => ['sometimes', 'nullable', 'string'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'property_name' => ['sometimes', 'required', 'string', 'max:255'],
        ]);

        $previousReceiptPath = $expense->receipt_image;
        $updates = array_intersect_key($validated, array_flip($updatableFields));

        if (isset($updates['date'])) {
            $updates['date'] = Carbon::parse($updates['date'])->format('Y-m-d');
        }

        if (array_key_exists('receipt_image', $validated)) {
            $updates['receipt_image'] = $this->imageStorage->store(
                $validated['receipt_image'],
                'receipt_images',
                'receipt_image',
                'receipt image'
            );
        }

        DB::transaction(function () use ($expense, $updates, $propertyCard) {
            $expense->fill($updates);
            $expense->save();
            $propertyCard?->recalculateTotals();
        });

        if (
            $previousReceiptPath
            && array_key_exists('receipt_image', $updates)
            && $previousReceiptPath !== $updates['receipt_image']
        ) {
            Storage::disk('public')->delete($previousReceiptPath);
        }

        $expense = $expense->fresh();
        $propertyCard = $expense->propertyCard;
        if ($propertyCard) {
            $this->realtime->notify(
                $propertyCard->fresh(),
                $user,
                PropertyRealtimeAction::EXPENSE_UPDATED,
                $expense,
            );
        }

        return response()->json([
            'message' => 'Expense updated successfully',
            'data' => $this->expenseData($expense),
        ], 200);
    }

    /**
     * Expense ko delete karta hai (Pure CRUD - Delete).
     */
    public function destroy(Request $request, string $expenseId)
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $expense = Expense::where('expense_id', $expenseId)
            ->orWhere('id', $expenseId)
            ->first();

        if (! $expense) {
            return response()->json([
                'message' => 'Expense not found',
            ], 404);
        }

        $propertyCard = $expense->propertyCard;
        $canDelete = $propertyCard
            ? $propertyCard->isOwner($user)
            : ((int) $expense->user_id === (int) $user->id);

        if (! $canDelete) {
            return response()->json([
                'message' => 'You do not have permission to delete this expense.',
            ], 403);
        }

        $propertyCard = $expense->propertyCard;
        $receiptPath = $expense->receipt_image;

        DB::transaction(function () use ($expense, $propertyCard) {
            $expense->delete();
            $propertyCard?->recalculateTotals();
        });

        if ($receiptPath) {
            Storage::disk('public')->delete($receiptPath);
        }

        if ($propertyCard) {
            $this->realtime->notify(
                $propertyCard->fresh(),
                $user,
                PropertyRealtimeAction::EXPENSE_DELETED,
                $expense,
            );
        }

        return response()->json([
            'message' => 'Expense deleted successfully',
        ], 200);
    }

    /**
     * Expense ka consistent JSON structure format karta hai.
     */
    private function expenseData(Expense $expense): array
    {
        return [
            'expense_id' => $expense->expense_id,
            'id' => $expense->id,
            'user_id' => $expense->user_id,
            'card_id' => $expense->card_id,
            'property_name' => $expense->property_name,
            'user_email' => $expense->user_email,
            'amount' => (float) $expense->amount,
            'category' => $expense->category,
            'date' => $expense->date?->format('Y-m-d') ?? (string) $expense->date,
            'receipt_image' => $expense->receipt_image
                ? asset('storage/'.$expense->receipt_image)
                : null,
            'note' => $expense->note,
            'created_at' => $expense->created_at,
            'updated_at' => $expense->updated_at,
        ];
    }
}
