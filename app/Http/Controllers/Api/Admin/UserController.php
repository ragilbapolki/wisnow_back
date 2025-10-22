<?php
// app/Http/Controllers/Api/Admin/UserController.php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class UserController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
        $this->middleware(function ($request, $next) {
            if (!auth()->user()->isAdmin()) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
            return $next($request);
        });
    }

    public function index(Request $request)
    {
        $query = User::with(['department:id,name', 'division:id,name']);

        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'LIKE', '%' . $request->search . '%')
                  ->orWhere('email', 'LIKE', '%' . $request->search . '%')
                  ->orWhere('position', 'LIKE', '%' . $request->search . '%');
            });
        }

        if ($request->role) {
            $query->where('role', $request->role);
        }

        if ($request->departemen_id) {
            $query->where('departemen_id', $request->departemen_id);
        }

        if ($request->divisi_id) {
            $query->where('divisi_id', $request->divisi_id);
        }

        $users = $query->orderBy('created_at', 'desc')
            ->paginate($request->per_page ?? 15);

        return response()->json($users);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
            'role' => 'required|in:admin,user',
            'position' => 'nullable|string|max:255',
            'moto' => 'nullable|string|max:500',
            'departemen_id' => 'nullable|exists:m_division,id',
            'divisi_id' => 'nullable|exists:m_division,id',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => $request->role,
            'position' => $request->position,
            'moto' => $request->moto,
            'departemen_id' => $request->departemen_id,
            'divisi_id' => $request->divisi_id,
        ]);

        $user->load(['department', 'division']);

        return response()->json([
            'success' => true,
            'message' => 'User created successfully',
            'data' => $user
        ], 201);
    }

    public function indexPenulis()
    {
        $penulis = User::select('users.*')
            ->withCount(['articles' => function ($query) {
                $query->where('status', 'published');
            }])
            ->where('role', 'editor')
            ->get()
            ->filter(function ($penulis) {
                return $penulis->articles_count > 0;
            })
            ->sortByDesc('articles_count')
            ->values()
            ->map(function ($penulis) {
                return [
                    'id' => $penulis->id,
                    'name' => $penulis->name,
                    'position' => $penulis->position,
                    'role' => $penulis->role,
                    'avatar' => $penulis->avatar,
                    'moto' => $penulis->moto,
                    'articles_count' => (int) $penulis->articles_count,
                    'created_at' => $penulis->created_at,
                    'updated_at' => $penulis->updated_at,
                ];
            });

        return response()->json($penulis);
    }

    public function show(User $user)
    {
        $user = User::with(['department', 'division'])
            ->where('id', auth()->id())
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => $user
        ]);
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

    public function destroy(User $user)
    {
        if ($user->id === auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete your own account'
            ], 400);
        }

        if ($user->avatar) {
            Storage::disk('public')->delete($user->avatar);
        }

        $user->delete();

        return response()->json([
            'success' => true,
            'message' => 'User deleted successfully'
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
