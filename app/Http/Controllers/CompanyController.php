<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\Request;

class CompanyController extends Controller
{
    /**
     * Display a listing of the companies.
     */
    public function index()
    {
        $companies = Company::orderBy('name')->get();
        return view('companies.index', compact('companies'));
    }

    /**
     * Store a newly created company in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'domain' => 'required|string|max:255',
            'client_id' => 'required|string|max:255',
            'client_secret' => 'required|string|max:255',
        ]);

        // Sanitize domain (remove http://, https://, and trailing slashes)
        $domain = preg_replace('/^https?:\/\//i', '', $validated['domain']);
        $domain = rtrim($domain, '/');
        $validated['domain'] = $domain;

        Company::create($validated);

        return redirect()->route('companies.index')
            ->with('success', 'Company registered successfully. You can now connect it to Bitrix24.');
    }

    /**
     * Remove the specified company from storage.
     */
    public function destroy(Company $company)
    {
        $company->delete();

        return redirect()->route('companies.index')
            ->with('success', 'Company removed successfully.');
    }

    /**
     * Toggle the active status of the company.
     */
    public function toggleActive(Company $company)
    {
        $company->update([
            'is_active' => !$company->is_active,
        ]);

        $status = $company->is_active ? 'activated' : 'deactivated';

        return redirect()->route('companies.index')
            ->with('success', "Company '{$company->name}' was successfully {$status}.");
    }
}
