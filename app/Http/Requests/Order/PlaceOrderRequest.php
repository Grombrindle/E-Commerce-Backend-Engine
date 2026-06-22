<?php

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;

class PlaceOrderRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'shipping_address'          => 'required|array',
            'shipping_address.name'     => 'required|string|max:255',
            'shipping_address.street'   => 'required|string|max:255',
            'shipping_address.city'     => 'required|string|max:100',
            'shipping_address.state'    => 'nullable|string|max:100',
            'shipping_address.country'  => 'required|string|max:100',
            'shipping_address.zip'      => 'required|string|max:20',
            'shipping_address.phone'    => 'nullable|string|max:20',
            'billing_address'           => 'nullable|array',
            'notes'                     => 'nullable|string|max:1000',
        ];
    }
}
