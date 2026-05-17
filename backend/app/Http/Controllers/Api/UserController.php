<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Domain\AssignableUserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function assignable(Request $request, AssignableUserService $assignableUserService): JsonResponse
    {
        $users = $assignableUserService->listForCompany((int) $request->user()->company_id);

        return response()->json([
            'data' => $users,
        ]);
    }
}
