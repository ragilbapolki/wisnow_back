<?php
// app/Http/Controllers/Api/Admin/UserController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use App\Models\Article;

class UserController extends Controller
{

    public function show($id)
    {
        try {
            $user = User::with(['department', 'division'])
                ->findOrFail($id);

            if ($user->avatar && !str_starts_with($user->avatar, 'http')) {
                $user->avatar_url = url('storage/' . $user->avatar);
            } else {
                $user->avatar_url = $user->avatar;
            }

            return response()->json([
                'success' => true,
                'data' => $user
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }
    }

    public function showProfile(User $user)
    {
            $user = User::with(['department', 'division'])
            ->where('id', auth()->id())
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => $user
        ]);
    }

    public function articles($id)
    {
        try {
            $user = User::findOrFail($id);

            $articles = Article::with(['category', 'user'])
                ->where('user_id', $id)
                ->where('status', 'published')
                ->orderBy('created_at', 'desc')
                ->get();

            $articles->each(function ($article) {
                $ratings = $article->ratings()->get();

                if ($ratings->count() > 0) {
                    $article->rating = $ratings->avg('rating');
                    $article->rating_count = $ratings->count();
                } else {
                    $article->rating = 0;
                    $article->rating_count = 0;
                }
            });

            return response()->json([
                'success' => true,
                'data' => $articles
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load articles'
            ], 500);
        }
    }

    public function update(Request $request, User $user)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email,' . $user->id,
            'position' => 'nullable|string|max:255',
            'moto' => 'nullable|string|max:500',
            'departemen_id' => 'nullable|exists:m_division,id',
            'divisi_id' => 'nullable|exists:m_division,id',
            'password' => 'nullable|string|min:8'
        ]);

        $data = $request->only([
            'name', 'email', 'position', 'moto', 'departemen_id', 'divisi_id'
        ]);

        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->password);
        }

        $user->update($data);
        $user->load(['department', 'division']);

        return response()->json([
            'success' => true,
            'message' => 'User updated successfully',
            'data' => $user
        ]);
    }

    public function uploadAvatar(Request $request)
    {
        $request->validate([
            'avatar' => 'required|image|mimes:jpg,jpeg,png|max:2048'
        ]);

        $user = auth()->user();

        if ($user->avatar) {
            Storage::disk('public')->delete($user->avatar);
        }

        $path = $request->file('avatar')->store('avatars', 'public');
        $user->update(['avatar' => $path]);

        return response()->json([
            'success' => true,
            'message' => 'Avatar uploaded successfully',
            'data' => [
                'avatar' => Storage::url($path),
                'user' => $user
            ]
        ]);
    }

    public function deleteAvatar()
    {
        $user = auth()->user();

        if ($user->avatar) {
            Storage::disk('public')->delete($user->avatar);
            $user->update(['avatar' => null]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Avatar deleted successfully'
        ]);
    }

    public function updateMoto(Request $request)
    {
        $request->validate([
            'moto' => 'nullable|string|max:500'
        ]);

        $user = auth()->user();
        $user->update(['moto' => $request->moto]);

        return response()->json([
            'success' => true,
            'message' => 'Moto updated successfully',
            'data' => [
                'moto' => $user->moto
            ]
        ]);
    }
}
