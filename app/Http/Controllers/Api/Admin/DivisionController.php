<?php
// app/Http/Controllers/Api/Admin/DivisionController.php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\MDivision;
use Illuminate\Http\Request;

class DivisionController extends Controller
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
        $query = MDivision::query();

        // Filter berdasarkan type (department atau division)
        if ($request->type) {
            $query->where('type', $request->type);
        }

        // Search
        if ($request->search) {
            $query->where('name', 'LIKE', '%' . $request->search . '%');
        }

        // Jika request all, return semua data tanpa pagination
        if ($request->all === 'true') {
            return response()->json([
                'success' => true,
                'data' => $query->orderBy('name', 'asc')->get()
            ]);
        }

        $divisions = $query->orderBy('created_at', 'desc')
            ->paginate($request->per_page ?? 15);

        return response()->json($divisions);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:categories,name',
            'type' => 'required|in:department,division',
            'description' => 'nullable|string|max:500',
        ]);

        $division = MDivision::create([
            'name' => $request->name,
            'slug' => \Illuminate\Support\Str::slug($request->name),
            'type' => $request->type,
            'description' => $request->description,
        ]);

        return response()->json([
            'success' => true,
            'message' => ucfirst($request->type) . ' created successfully',
            'data' => $division
        ], 201);
    }

    public function show(MDivision $division)
    {
        return response()->json([
            'success' => true,
            'data' => $division
        ]);
    }

    public function update(Request $request, MDivision $division)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:categories,name,' . $division->id,
            'type' => 'required|in:department,division',
            'description' => 'nullable|string|max:500',
        ]);

        $division->update([
            'name' => $request->name,
            'slug' => \Illuminate\Support\Str::slug($request->name),
            'type' => $request->type,
            'description' => $request->description,
        ]);

        return response()->json([
            'success' => true,
            'message' => ucfirst($request->type) . ' updated successfully',
            'data' => $division
        ]);
    }

    public function destroy(MDivision $division)
    {
        // Cek apakah ada user yang masih menggunakan division/department ini
        $userCount = \App\Models\User::where('departemen_id', $division->id)
            ->orWhere('divisi_id', $division->id)
            ->count();

        if ($userCount > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete. This ' . $division->type . ' is still assigned to ' . $userCount . ' user(s)'
            ], 400);
        }

        $type = $division->type;
        $division->delete();

        return response()->json([
            'success' => true,
            'message' => ucfirst($type) . ' deleted successfully'
        ]);
    }
}
