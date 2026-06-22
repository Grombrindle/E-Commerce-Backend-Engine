<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentService
{

    public function processPayment(User $user, int $orderId, array $data): Payment
    {
        $order = Order::where('user_id', $user->id)->findOrFail($orderId);

        if ($order->isPaid()) {
            throw new \RuntimeException('Order is already paid.');
        }

        if ($order->status === Order::STATUS_CANCELLED) {
            throw new \RuntimeException('Cannot pay for a cancelled order.');
        }

        $existing = Payment::where('order_id', $orderId)
            ->where('status', Payment::STATUS_PENDING)
            ->first();

        if ($existing) {
            throw new \RuntimeException('A pending payment already exists for this order.');
        }

        return DB::transaction(function () use ($user, $order, $data) {
            $payment = Payment::create([
                'order_id'  => $order->id,
                'user_id'   => $user->id,
                'method'    => $data['method'],
                'status'    => Payment::STATUS_PENDING,
                'amount'    => $order->total,
                'currency'  => $data['currency'] ?? 'USD',
                'gateway'   => $data['gateway'] ?? 'internal',
            ]);

            try {

                $gatewayResult = $this->callGateway($data);

                $payment->update([
                    'status'                 => Payment::STATUS_PAID,
                    'gateway_transaction_id' => $gatewayResult['transaction_id'],
                    'gateway_response'       => $gatewayResult,
                    'paid_at'                => now(),
                ]);

                $order->update(['status' => Order::STATUS_CONFIRMED]);

            } catch (\Exception $e) {
                $payment->update([
                    'status'        => Payment::STATUS_FAILED,
                    'failed_reason' => $e->getMessage(),
                ]);
                Log::error('Payment failed', [
                    'order_id' => $order->id,
                    'error'    => $e->getMessage(),
                ]);
                throw new \RuntimeException('Payment processing failed: ' . $e->getMessage());
            }

            return $payment;
        });
    }

    protected function callGateway(array $data): array
    {

        usleep(random_int(100000, 300000));

        if (isset($data['card_number']) && str_ends_with($data['card_number'], '0002')) {
            throw new \RuntimeException('Your card was declined.');
        }

        return [
            'transaction_id' => 'txn_' . strtoupper(substr(md5(uniqid()), 0, 16)),
            'status'         => 'succeeded',
            'gateway'        => 'simulated',
            'processed_at'   => now()->toIso8601String(),
        ];
    }

    public function handleWebhook(array $payload, string $signature): array
    {

        Log::info('Payment webhook received', ['event' => $payload['type'] ?? 'unknown']);
        return ['received' => true];
    }

    public function refund(int $paymentId): Payment
    {
        $payment = Payment::findOrFail($paymentId);

        if (!$payment->isPaid()) {
            throw new \RuntimeException('Only paid payments can be refunded.');
        }

        $payment->update([
            'status'           => Payment::STATUS_REFUNDED,
            'gateway_response' => array_merge($payment->gateway_response ?? [], [
                'refund_id'   => 'ref_' . strtoupper(substr(md5(uniqid()), 0, 10)),
                'refunded_at' => now()->toIso8601String(),
            ]),
        ]);

        $payment->order->update(['status' => Order::STATUS_REFUNDED]);

        return $payment->fresh();
    }
}
