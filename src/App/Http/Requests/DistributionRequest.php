<?php

namespace PLSys\DistrbutionQueue\App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DistributionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     */
    public function rules()
    {
        return [
            'distribution_request' => 'required|array|min:1',
            'distribution_request.*.distribution_request_id' => 'required',
            'distribution_request.*.distribution_job_name' => 'required'

        ];
    }
}
