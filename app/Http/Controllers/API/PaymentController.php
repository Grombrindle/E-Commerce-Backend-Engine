<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\ProcessPaymentRequest;
use App\Services\PaymentService;
use Illuminate\Http\Request;

/**
 * PaymentController
 *
 * @POST /api/v1/payments          → process()
 * @GET  /api/v1/payments/{id}     → show()
 * @POST /api/v1/payments/webhook  → webhook() [public]
 */
class PaymentController extends Controller
{
    public function __construct(protected PaymentService $paymentService) {}

    /** Process payment for an order. */
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

    /** Get payment details. */
    public function show(Request $request, int $id)
    {
        $payment = $request->user()
            ->payments()
            ->with('order:id,order_number,status,total')
            ->findOrFail($id);

        return $this->success($payment);
    }

    /** Handle payment gateway webhook. */
    public function webhook(Request $request)
    {
        $result = $this->paymentService->handleWebhook(
            $request->all(),
            $request->header('Stripe-Signature', '')
        );
        return response()->json($result);
    }
}
