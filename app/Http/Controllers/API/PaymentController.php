<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\ProcessPaymentRequest;
use App\Services\PaymentService;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(protected PaymentService $paymentService) {}

    public function process(ProcessPaymentRequest $request)
    {
        try {
            $payment = $this->paymentService->processPayment(
                $request->user(),
                $request->order_id,
                $request->validated()
            );
            return $this->created($payment, 'Payment processed successfully.');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function show(Request $request, int $id)
    {
        $payment = $request->user()
            ->payments()
            ->with('order:id,order_number,status,total')
            ->findOrFail($id);

        return $this->success($payment);
    }

    public function webhook(Request $request)
    {
        $result = $this->paymentService->handleWebhook(
            $request->all(),
            $request->header('Stripe-Signature', '')
        );
        return response()->json($result);
    }
}
