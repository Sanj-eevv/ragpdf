<?php

namespace App\Models;

use App\Enums\EvaluationRunStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * @property EvaluationRunStatus $status
 * @property array<int, array<string, mixed>>|null $summary
 */
class RagasEvaluationRun extends Model
{
    protected $fillable = [
        'batch_id',
        'status',
        'total',
        'completed',
        'summary',
        'csv_path',
        'error_message',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => EvaluationRunStatus::class,
            'summary' => 'array',
        ];
    }
}
