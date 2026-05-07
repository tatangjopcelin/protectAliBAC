<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $suppliers = Supplier::orderBy('name')->get();
        return response()->json($suppliers);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'contact_name' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string',
            'notes' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);

        $email = strtolower(trim($validated['email']));
        $name = trim($validated['name']);

        $supplier = Supplier::whereRaw('LOWER(email) = ?', [$email])->first();
        if (!$supplier) {
            $supplier = Supplier::whereRaw('LOWER(name) = ?', [strtolower($name)])->first();
        }

        if ($supplier) {
            $supplier->update([
                'name' => $supplier->name ?: $name,
                'email' => $supplier->email ?: $email,
                'contact_name' => $validated['contact_name'] ?? $supplier->contact_name,
                'phone' => $validated['phone'] ?? $supplier->phone,
                'address' => $validated['address'] ?? $supplier->address,
            ]);

            return response()->json([
                'message' => 'Fournisseur existant utilisé.',
                'supplier' => $supplier,
            ]);
        }

        $supplier = Supplier::create([
            'name' => $name,
            'email' => $email,
            'contact_name' => $validated['contact_name'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'address' => $validated['address'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json([
            'message' => 'Fournisseur créé avec succès.',
            'supplier' => $supplier,
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
