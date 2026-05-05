<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class DocsController extends Controller
{
    public function json(): JsonResponse
    {
        $path = storage_path('api-docs/openapi.json');
        $content = json_decode(file_get_contents($path), true);

        return response()->json($content);
    }

    public function ui()
    {
        return view('docs.swagger');
    }
}
