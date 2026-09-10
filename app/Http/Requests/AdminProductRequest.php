<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminProductRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $productId = $this->route('id') ? (int) $this->route('id') : ($this->route('product') ? (int) $this->route('product') : null);
        $isPost = $this->isMethod('POST');

        return [
            'name' => [$isPost ? 'required' : 'sometimes', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', Rule::unique('products', 'slug')->ignore($productId)],
            'category_id' => [$isPost ? 'required' : 'sometimes', 'integer', 'exists:categories,id'],
            'description' => ['nullable', 'string'],
            'price' => [$isPost ? 'required' : 'sometimes', 'numeric', 'min:0'],
            'weight' => [$isPost ? 'required' : 'sometimes', 'integer', 'min:1'],
            'stock' => [$isPost ? 'required' : 'sometimes', 'integer', 'min:0'],
            'image' => ['nullable'],
            'image_file' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
