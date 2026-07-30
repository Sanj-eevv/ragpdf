<?php

namespace App\Models;

use Database\Factories\RagasEvaluationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RagasEvaluation extends Model
{
    /** @use HasFactory<RagasEvaluationFactory> */
    use HasFactory;

    protected $fillable = [
        'query_id',
        'context_precision',
        'context_recall',
        'faithfulness',
        'answer_relevance',
        'judge_model',
        'raw_judge_response',
    ];

    /**
     * @return BelongsTo<Query, $this>
     */
    public function queryRecord(): BelongsTo
    {
        return $this->belongsTo(Query::class, 'query_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'raw_judge_response' => 'array',
        ];
    }
}
