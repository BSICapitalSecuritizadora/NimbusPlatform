<?php

namespace App\Http\Controllers\Nimbus;

use App\Actions\Nimbus\LookupNimbusCnpj;
use App\Http\Controllers\Controller;
use App\Http\Requests\LookupNimbusCnpjRequest;
use Illuminate\Http\JsonResponse;

class CnpjLookupController extends Controller
{
    public function __invoke(LookupNimbusCnpjRequest $request, LookupNimbusCnpj $lookupNimbusCnpj): JsonResponse
    {
        $result = $lookupNimbusCnpj->handle((string) $request->validated('cnpj'));

        return response()->json($result['payload'], $result['status']);
    }
}
