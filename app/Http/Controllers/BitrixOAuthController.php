<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class BitrixOAuthController extends Controller
{
    /**
     * Redirect the user to Bitrix24 to authorize the application.
     */
    public function connect(Company $company)
    {
        if (!$company->is_active) {
            return redirect()->route('companies.index')
                ->with('error', "Cannot connect: '{$company->name}' is deactivated. Please activate it first.");
        }

        $redirectUrl = "https://{$company->domain}/oauth/authorize/?" . http_build_query([
            'client_id' => $company->client_id,
            'state' => $company->id,
        ]);

        return redirect()->away($redirectUrl);
    }

    /**
     * Handle both OAuth callback (GET) and installation callback (POST) from Bitrix24.
     */
    public function callback(Request $request)
    {
        // Check if this is a POST installation callback
        if ($request->isMethod('post')) {
            return $this->handleInstallationCallback($request);
        }

        // Otherwise, handle GET OAuth callback
        $code = $request->query('code');
        $companyId = $request->query('state');
        $memberId = $request->query('member_id');

        if (!$code || !$companyId) {
            return redirect()->route('companies.index')
                ->with('error', 'OAuth authorization failed: Missing code or state parameters.');
        }

        $company = Company::findOrFail($companyId);

        if (!$company->is_active) {
            return redirect()->route('companies.index')
                ->with('error', "OAuth connection blocked: '{$company->name}' is deactivated.");
        }

        try {
            $response = Http::asForm()->post('https://oauth.bitrix.info/oauth/token/', [
                'grant_type' => 'authorization_code',
                'client_id' => $company->client_id,
                'client_secret' => $company->client_secret,
                'code' => $code,
            ]);

            if ($response->failed()) {
                throw new Exception("OAuth exchange failed: " . $response->body());
            }

            $data = $response->json();

            if (empty($data['access_token']) || empty($data['refresh_token'])) {
                throw new Exception("Response did not contain valid tokens.");
            }

            $company->update([
                'member_id' => $memberId ?? $data['member_id'] ?? null,
                'access_token' => $data['access_token'],
                'refresh_token' => $data['refresh_token'],
                'expires_at' => now()->addSeconds((int) ($data['expires_in'] ?? 3600)),
            ]);

            // Redirect to dashboard after successful connection
            return redirect()->route('report.show', ['company' => $company->id])
                ->with('success', "Successfully connected {$company->name} to Bitrix24!");

        } catch (Exception $e) {
            Log::error("Bitrix24 OAuth Callback Error", [
                'company_id' => $companyId,
                'error' => $e->getMessage()
            ]);

            return redirect()->route('companies.index')
                ->with('error', 'OAuth Error: ' . $e->getMessage());
        }
    }

    /**
     * Handle POST installation callback from Bitrix24.
     */
    private function handleInstallationCallback(Request $request)
    {
        $payload = $request->all();
        $domain = $request->input('DOMAIN') ?? $request->input('auth.domain');
        $memberId = $request->input('member_id') ?? $request->input('auth.member_id');
        $accessToken = $request->input('AUTH_ID') ?? $request->input('auth.access_token');
        $refreshToken = $request->input('REFRESH_ID') ?? $request->input('auth.refresh_token');
        $expiresIn = $request->input('AUTH_EXPIRES') ?? $request->input('auth.expires_in', 3600);

        if (!$domain || !$accessToken || !$refreshToken) {
            Log::warning('Bitrix installation callback missing credentials', ['payload' => $payload]);
            if ($request->wantsJson()) {
                return response()->json(['error' => 'Missing install credentials'], 400);
            }
            abort(400, 'Missing install credentials');
        }

        // Sanitize and match domain
        $sanitizedDomain = preg_replace('/^https?:\/\//i', '', $domain);
        $sanitizedDomain = rtrim($sanitizedDomain, '/');

        $company = Company::where('domain', $sanitizedDomain)->first();

        if ($company) {
            if (!$company->is_active) {
                Log::warning('Bitrix installation callback blocked: Company is deactivated', ['domain' => $domain]);
                if ($request->wantsJson()) {
                    return response()->json(['error' => 'Registered company is currently deactivated'], 403);
                }
                return response()->view('bitrix24.install_wizard_error', [
                    'message' => 'This domain is registered but currently deactivated. Please activate it in the admin panel.'
                ], 403);
            }

            Log::info("Bitrix installation callback success for company", ['name' => $company->name]);

            $company->update([
                'member_id' => $memberId,
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'expires_at' => now()->addSeconds((int) $expiresIn),
            ]);

            if ($request->wantsJson()) {
                return response()->json(['success' => true]);
            }
            // Pass company to the view for redirect
            return view('bitrix24.install_complete', ['company' => $company]);
        }

        // If company doesn't exist, render the wizard to capture App ID and App Secret
        if ($request->wantsJson()) {
            return response()->json(['error' => 'Domain not registered in Dashboard'], 404);
        }

        return view('bitrix24.install_wizard', [
            'domain' => $sanitizedDomain,
            'member_id' => $memberId,
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in' => $expiresIn
        ]);
    }

    /**
     * Handle background initial installation callback POST from Bitrix24.
     */
    public function install(Request $request)
    {
        $payload = $request->all();
        // Use Bitrix24's actual key names
        $domain = $request->input('DOMAIN') ?? $request->input('auth.domain');
        $memberId = $request->input('member_id') ?? $request->input('auth.member_id');
        $accessToken = $request->input('AUTH_ID') ?? $request->input('auth.access_token');
        $refreshToken = $request->input('REFRESH_ID') ?? $request->input('auth.refresh_token');
        $expiresIn = $request->input('AUTH_EXPIRES') ?? $request->input('auth.expires_in', 3600);

        if (!$domain || !$accessToken || !$refreshToken) {
            Log::warning('Bitrix installation callback missing credentials', ['payload' => $payload]);
            if ($request->wantsJson()) {
                return response()->json(['error' => 'Missing install credentials'], 400);
            }
            abort(400, 'Missing install credentials');
        }

        // Sanitize and match domain
        $sanitizedDomain = preg_replace('/^https?:\/\//i', '', $domain);
        $sanitizedDomain = rtrim($sanitizedDomain, '/');

        $company = Company::where('domain', $sanitizedDomain)->first();

        if ($company) {
            if (!$company->is_active) {
                Log::warning('Bitrix installation callback blocked: Company is deactivated', ['domain' => $domain]);
                if ($request->wantsJson()) {
                    return response()->json(['error' => 'Registered company is currently deactivated'], 403);
                }
                return response()->view('bitrix24.install_wizard_error', [
                    'message' => 'This domain is registered but currently deactivated. Please activate it in the admin panel.'
                ], 403);
            }

            Log::info("Bitrix installation callback success for company", ['name' => $company->name]);

            $company->update([
                'member_id' => $memberId,
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'expires_at' => now()->addSeconds((int) $expiresIn),
            ]);

            if ($request->wantsJson()) {
                return response()->json(['success' => true]);
            }
            // Pass company to the view for redirect
            return view('bitrix24.install_complete', ['company' => $company]);
        }

        // If company doesn't exist, we render the wizard to capture App ID and App Secret
        if ($request->wantsJson()) {
            return response()->json(['error' => 'Domain not registered in Dashboard'], 404);
        }

        return view('bitrix24.install_wizard', [
            'domain' => $sanitizedDomain,
            'member_id' => $memberId,
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in' => $expiresIn
        ]);
    }

    /**
     * Handle the completed installation wizard form submission.
     */
    public function completeInstall(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'client_id' => 'required|string|max:255',
            'client_secret' => 'required|string|max:255',
            'domain' => 'required|string|max:255',
            'member_id' => 'required|string|max:255',
            'access_token' => 'required|string',
            'refresh_token' => 'required|string',
            'expires_in' => 'required|integer',
        ]);

        // Validate domain format
        if (!preg_match('/^[a-z0-9\-\.]+\.bitrix24\.com$/i', $validated['domain'])) {
            return redirect()->route('companies.index')
                ->with('error', 'Invalid Bitrix24 domain format');
        }

        $company = Company::create([
            'name' => $validated['name'],
            'domain' => $validated['domain'],
            'client_id' => $validated['client_id'],
            'client_secret' => $validated['client_secret'],
            'member_id' => $validated['member_id'],
            'access_token' => $validated['access_token'],
            'refresh_token' => $validated['refresh_token'],
            'expires_at' => now()->addSeconds((int) $validated['expires_in']),
            'is_active' => true, // Active automatically when installed via wizard
        ]);

        Log::info("Bitrix installation wizard completed successfully for company", ['name' => $company->name]);

        // Pass company to the view for redirect
        return view('bitrix24.install_complete', ['company' => $company]);
    }
}