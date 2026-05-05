<?php

namespace App\Http\Controllers;

use App\Models\ResultCheckerOrder;
use App\Services\ResultCheckerService;
use Illuminate\Http\Request;

class ResultCheckerStatusController extends Controller
{
    public function __construct(
        protected ResultCheckerService $resultCheckerService,
    ) {}

    public function index()
    {
        if (view()->exists('result_checkers.status')) {
            return view('result_checkers.status');
        }

        return response()->json([
            'success' => true,
            'message' => 'Result checker status endpoint is available',
        ]);
    }

    public function check(Request $request)
    {
        $validated = $request->validate([
            'query' => ['required', 'string', 'max:255'],
        ]);

        $status = $this->resultCheckerService->getOrderStatus($validated['query']);
        if (!$status) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $status,
        ]);
    }

    public function show(ResultCheckerOrder $order)
    {
        $status = $this->resultCheckerService->getOrderStatus((string) $order->id);
        if (!$status) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found',
            ], 404);
        }

        if (view()->exists('result_checkers.status_show')) {
            return view('result_checkers.status_show', [
                'order' => $order->load('service'),
                'status' => $status,
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => $status,
        ]);
    }
}
