<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInventoryRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->isAdmin() ?? false; }

    public function rules(): array
    {
        return [
            'quantity'             => 'required|integer|min:0',
            'low_stock_threshold'  => 'nullable|integer|min:0',
        ];
    }
}
