<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PlaceOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $delivery = $this->input('fulfillment_type', 'delivery') === 'delivery';

        return [
            'customer.first_name' => ['required', 'string', 'min:2', 'max:80'],
            'customer.last_name' => ['nullable', 'string', 'max:80'],
            'customer.phone' => ['required', 'string', 'max:30', 'regex:/^[+0-9 ().-]{9,20}$/'],
            'customer.email' => ['nullable', 'email:rfc', 'max:160'],
            'fulfillment_type' => ['nullable', 'in:delivery,pickup'],
            'delivery' => [$delivery ? 'required' : 'nullable', 'array'],
            'delivery.zone_id' => [$delivery ? 'required' : 'nullable', 'uuid'],
            'delivery.district' => [$delivery ? 'required' : 'nullable', 'string', 'min:2', 'max:120'],
            'delivery.street' => ['nullable', 'string', 'max:200'],
            'delivery.landmark' => ['nullable', 'string', 'max:255'],
            'delivery.lat' => ['nullable', 'numeric', 'between:14.0,15.2'],
            'delivery.lng' => ['nullable', 'numeric', 'between:-17.8,-16.8'],
            'slot_start_at' => ['required', 'date'],
            'payment_method' => ['nullable', 'string', 'max:30'],
            'promotion_code' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:500'],
            'accepted_terms' => ['accepted'],
            'marketing_consent' => ['nullable', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'customer.first_name' => 'prénom',
            'customer.phone' => 'téléphone',
            'customer.email' => 'email',
            'delivery.zone_id' => 'zone de livraison',
            'delivery.district' => 'quartier',
            'slot_start_at' => 'créneau',
            'accepted_terms' => 'conditions générales',
        ];
    }
}
