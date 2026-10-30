<?php

namespace App\Http\Controllers;

use App\Http\Requests\QueryRequest;
use App\Rag\Retrieval\RagQueryService;
use Illuminate\Http\JsonResponse;
use Throwable;

class QueryController extends Controller
{
    public function __invoke(QueryRequest $request, RagQueryService $queryService): JsonResponse
    {
        try {
            return response()->json(
                $queryService->ask($request->validated('question')),
            );
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Unable to answer the question right now. Please try again.',
            ], 502);
        }
    }
}
