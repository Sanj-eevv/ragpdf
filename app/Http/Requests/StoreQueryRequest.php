<?php

namespace App\Http\Requests;

use App\Enums\ChunkingStrategy;
use App\Enums\RetrievalAlgorithm;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreQueryRequest extends FormRequest
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
        return [
            'question' => ['required', 'string'],
            'document_id' => ['nullable', 'integer', 'exists:documents,id'],
            'chunking_strategy' => ['required', Rule::enum(ChunkingStrategy::class)],
            'retrieval_algorithm' => ['required', Rule::enum(RetrievalAlgorithm::class)],
            'reranked' => ['boolean'],
        ];
    }
}
