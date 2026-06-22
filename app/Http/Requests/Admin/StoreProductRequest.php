<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->isAdmin() ?? false; }

    public function rules(): array
    {
        return [
            'category_id'     => 'required|integer|exists:categories,id',
            'name'            => 'required|string|max:255',
            'description'     => 'nullable|string',
            'price'           => 'required|numeric|min:0',
            'compare_price'   => 'nullable|numeric|min:0',
            'sku'             => 'nullable|string|max:100|unique:products,sku,' . $this->route('product'),
            'image_url'       => 'nullable|url',
            'images'          => 'nullable|array',
            'is_active'       => 'nullable|boolean',
            'weight'          => 'nullable|numeric|min:0',
            'initial_stock'   => 'nullable|integer|min:0',
            'low_stock_threshold' => 'nullable|integer|min:0',
        ];
    }
}
