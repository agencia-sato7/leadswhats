<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use Illuminate\Http\JsonResponse;

class AdminPermissionController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => Permission::query()->orderBy('group_name')->orderBy('name')->get(['id', 'code', 'name', 'group_name'])]);
    }
}
