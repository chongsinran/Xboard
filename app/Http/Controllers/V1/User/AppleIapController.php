<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AppleIapService;
use Illuminate\Http\Request;

class AppleIapController extends Controller
{
    public function config(Request $request, AppleIapService $service)
    {
        return $this->success($service->getClientConfiguration($request));
    }

    public function verify(Request $request, AppleIapService $service)
    {
        $validated = $request->validate([
            'transaction_id' => 'required|string|max:128',
            'product_id' => 'required|string|max:191',
            'verification_data' => 'nullable|string',
        ]);
        $user = User::findOrFail($request->user()->id);

        return $this->success($service->verifyAndGrant(
            $user,
            $validated['transaction_id'],
            $validated['product_id'],
        ));
    }
}
