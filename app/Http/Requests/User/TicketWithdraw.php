<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class TicketWithdraw  extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'withdraw_method' => 'required',
            'withdraw_account' => 'required',
            'withdraw_balance' => 'nullable|numeric|min:0.01'
        ];
    }

    public function messages()
    {
        return [
            'withdraw_method.required' => __('The withdrawal method cannot be empty'),
            'withdraw_account.required' => __('The withdrawal account cannot be empty'),
            'withdraw_balance.numeric' => __('The withdrawal amount format is incorrect'),
            'withdraw_balance.min' => __('The withdrawal amount must be greater than 0')
        ];
    }
}
