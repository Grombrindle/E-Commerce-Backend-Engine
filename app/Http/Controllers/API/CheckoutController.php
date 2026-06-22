<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Helpers\CacheHelper;
use App\Services\CheckoutService;
use Illuminate\Http\Request;

class CheckoutController extends Controller
{
    public function __construct(protected CheckoutService $checkoutService)
    {
    }

    public function checkout(Request $request)
    {
        $request->validate([
            'payment.method'            => 'required|string|in:card,bank_transfer,cash_on_delivery,wallet',
            'payment.card_number'       => 'nullable|string|max:19',
            'payment.currency'          => 'nullable|string|size:3',
            'payment.gateway'           => 'nullable|string',
            'shipping_address.name'     => 'required|string|max:255',
            'shipping_address.street'   => 'required|string|max:255',
            'shipping_address.city'     => 'required|string|max:100',
            'shipping_address.country'  => 'required|string|size:2',
            'shipping_address.zip'      => 'required|string|max:20',
            'billing_address.name'      => 'nullable|string|max:255',
            'billing_address.street'    => 'nullable|string|max:255',
            'billing_address.city'      => 'nullable|string|max:100',
            'billing_address.country'   => 'nullable|string|size:2',
            'billing_address.zip'       => 'nullable|string|max:20',
            'notes'                     => 'nullable|string|max:500',
        ]);

        try {
            $result = $this->checkoutService->checkout(
                $request->user(),
                $request->all()
            );

            CacheHelper::flush(['products']);

            return $this->created([
                'order'   => $result['order'],
                'payment' => $result['payment'],
            ], 'Checkout completed successfully. Order placed and payment processed.');

        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }
}
