<?php

namespace App\Http\Controllers;

/**
 * Base Controller with JSON response helpers.
 */
abstract class Controller
{
    protected function success($data = null, string $message = 'Success', int $status = 200)
    {
        return response()->json(array_filter([
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ], fn($v) => $v !== null), $status);
    }

    protected function created($data = null, string $message = 'Created successfully')
    {
        return $this->success($data, $message, 201);
    }

    protected function error(string $message, int $status = 400, array $errors = [])
    {
        return response()->json(array_filter([
            'success' => false,
            'message' => $message,
            'errors'  => $errors ?: null,
        ], fn($v) => $v !== null), $status);
    }

    protected function paginated($paginatedData, string $message = 'Success')
    {
        return response()->json([
            'success'    => true,
            'message'    => $message,
            'data'       => $paginatedData->items(),
            'pagination' => [
                'total'        => $paginatedData->total(),
                'per_page'     => $paginatedData->perPage(),
                'current_page' => $paginatedData->currentPage(),
                'last_page'    => $paginatedData->lastPage(),
                'from'         => $paginatedData->firstItem(),
                'to'           => $paginatedData->lastItem(),
            ],
        ]);
    }
}
