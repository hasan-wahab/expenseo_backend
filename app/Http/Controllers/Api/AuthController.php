<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Base64ImageStorage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private readonly Base64ImageStorage $imageStorage) {}

    public function register(Request $request)
    {
        // Registration ke liye aane wale data ki zaroori jaanch hoti hai.
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'min:8'],
            'profile_image' => ['nullable', 'string'],
        ]);

        // Tasveer di gayi ho to usay check karke storage mein save karta hai.
        $imagePath = $this->imageStorage->store(
            $validated['profile_image'] ?? null,
            'profile_images',
            'profile_image',
            'profile image'
        );

        // Naya user database mein banata hai aur password ko hash karke rakhta hai.
        User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'profile_image' => $imagePath,
        ]);

        // Kamyab registration par mobile app ko JSON jawab bhejta hai.
        return response()->json([
            'message' => 'User registered successfully',
        ], 201);
    }

    public function login(Request $request)
    {
        // Login ke liye email aur password ka format check hota hai.
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Ghalat email ya password par unauthorized jawab bhejta hai.
        if (! Auth::attempt($validated)) {
            return response()->json([
                'message' => 'Invalid credentials',
            ], 401);
        }

        // Sahi login par user ke liye Sanctum API token banata hai.
        $user = Auth::user();
        $token = $user->createToken('auth-token')->plainTextToken;

        // Token aur user ki zaroori maloomat mobile app ko bhejta hai.
        return response()->json([
            'message' => 'Login successful',
            'token' => $token,
            'user' => $this->userData($user),
        ], 200);
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $input = $request->all();
        if (! array_intersect(['name', 'email', 'profile_image'], array_keys($input))) {
            throw ValidationException::withMessages([
                'profile' => 'Kam az kam aik profile field bhejna zaroori hai.',
            ]);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'profile_image' => ['sometimes', 'nullable', 'string'],
        ]);

        $previousImagePath = $user->profile_image;
        $updates = array_intersect_key($validated, array_flip(['name', 'email']));

        if (array_key_exists('profile_image', $validated)) {
            $updates['profile_image'] = $this->imageStorage->store(
                $validated['profile_image'],
                'profile_images',
                'profile_image',
                'profile image'
            );
        }

        $user->fill($updates);
        $user->save();

        if (
            $previousImagePath
            && array_key_exists('profile_image', $updates)
            && $previousImagePath !== $updates['profile_image']
            && ! Storage::disk('public')->delete($previousImagePath)
        ) {
            throw new \RuntimeException('Failed to remove the previous user profile image.');
        }

        return response()->json([
            'message' => 'Profile updated successfully',
            'user' => $this->userData($user),
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logout successful',
        ]);
    }

    private function userData(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'profile_image' => $user->profile_image ? asset('storage/'.$user->profile_image) : '',
        ];
    }
}
