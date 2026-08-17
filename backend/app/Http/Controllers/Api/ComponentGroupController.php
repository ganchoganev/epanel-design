<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ComponentGroup;
use Illuminate\Http\Request;

class ComponentGroupController extends Controller
{
    public function index()
    {
        return response()->json(ComponentGroup::orderBy('name')->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'items' => 'required|array|min:1',
            'connections' => 'nullable|array',
        ]);

        return response()->json(ComponentGroup::create([
            ...$data,
            'is_system' => false,
        ]), 201);
    }

    public function show(ComponentGroup $group)
    {
        return response()->json($group);
    }

    public function destroy(ComponentGroup $group)
    {
        if ($group->is_system) {
            return response()->json(['message' => 'Системните групи не могат да се изтриват.'], 422);
        }

        $group->delete();

        return response()->json(null, 204);
    }
}
