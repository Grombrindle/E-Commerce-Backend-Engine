<?php

namespace App\Http\Requests\Payment;

use Illuminate\Foundation\Http\FormRequest;

class ProcessPaymentRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'order_id'    => 'required|integer|exists:orders,id',
            'method'      => 'required|in:card,bank_transfer,cash_on_delivery,wallet',
            'currency'    => 'nullable|string|size:3',
            'card_number' => 'required_if:method,card|nullable|string|digits_between:13,19',
            'card_expiry' => 'required_if:method,card|nullable|string',
            'card_cvv'    => 'required_if:method,card|nullable|string|digits:3',
        ];
    }

    protected function prepareForValidation(): void
    {

        if ($this->card_number) {
            $this->merge(['card_number' => str_replace(' ', '', $this->card_number)]);
        }
    }
}
