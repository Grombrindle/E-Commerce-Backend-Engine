<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name'      => 'required|string|max:255',
            'email'     => 'required|string|email|max:255|unique:users,email',
            'password'  => 'required|string|min:8|confirmed',
            'phone'     => 'nullable|string|max:20',
            'address'   => 'nullable|array',
            'address.street'  => 'nullable|string|max:255',
            'address.city'    => 'nullable|string|max:100',
            'address.country' => 'nullable|string|max:100',
            'address.zip'     => 'nullable|string|max:20',
        ];
    }
}
