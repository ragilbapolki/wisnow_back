<?php
// app/Http/Controllers/Api/Admin/DivisionController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MDivision;
use Illuminate\Http\Request;

class DivisionController extends Controller
{

    public function index(Request $request)
    {
        $query = MDivision::query();

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

    public function show(MDivision $division)
    {
        return response()->json([
            'success' => true,
            'data' => $division
        ]);
    }
}
