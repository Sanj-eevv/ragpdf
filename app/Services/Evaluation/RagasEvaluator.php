<?php

namespace App\Services\Evaluation;

use App\Ai\Agents\Judges\AnswerRelevanceJudge;
use App\Ai\Agents\Judges\ContextPrecisionJudge;
use App\Ai\Agents\Judges\ContextRecallJudge;
use App\Ai\Agents\Judges\FaithfulnessJudge;
use App\Models\DocumentChunk;
use App\Models\Query;
use App\Models\RagasEvaluation;
use Illuminate\Support\Collection;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StructuredAgentResponse;
use LogicException;

/**
 * Runs a Query (and, for the offline experiment harness, its ground-truth
 * answer) through the four RAGAS judges and persists the results. Each judge
 * uses GPT-4o explicitly, per the thesis (the AI SDK's OpenAI default is a
 * newer/different model).
 */
class RagasEvaluator
{
    private const string MODEL = 'gpt-4o';

    public function evaluate(Query $query, ?string $groundTruthAnswer = null): RagasEvaluation
    {
        $chunks = $this->orderedChunks($query);

        $contextPrecision = (new ContextPrecisionJudge)->prompt(
            $this->buildContextPrecisionPrompt($query->question, $chunks),
            model: self::MODEL,
        );

        $contextRecall = $groundTruthAnswer === null ? null : (new ContextRecallJudge)->prompt(
            $this->buildContextRecallPrompt($groundTruthAnswer, $chunks),
            model: self::MODEL,
        );

        $faithfulness = (new FaithfulnessJudge)->prompt(
            $this->buildFaithfulnessPrompt((string) $query->answer, $chunks),
            model: self::MODEL,
        );

        $answerRelevance = (new AnswerRelevanceJudge)->prompt(
            $this->buildAnswerRelevancePrompt($query->question, (string) $query->answer),
            model: self::MODEL,
        );

        return RagasEvaluation::query()->create([
            'query_id' => $query->id,
            'context_precision' => $this->score($contextPrecision),
            'context_recall' => $contextRecall === null ? null : $this->score($contextRecall),
            'faithfulness' => $this->score($faithfulness),
            'answer_relevance' => $this->score($answerRelevance),
            'judge_model' => self::MODEL,
            'raw_judge_response' => [
                'context_precision' => $this->structured($contextPrecision)->toArray(),
                'context_recall' => $contextRecall === null ? null : $this->structured($contextRecall)->toArray(),
                'faithfulness' => $this->structured($faithfulness)->toArray(),
                'answer_relevance' => $this->structured($answerRelevance)->toArray(),
            ],
        ]);
    }

    /**
     * Judge agents implement HasStructuredOutput, so `prompt()` always returns
     * a StructuredAgentResponse at runtime even though Promptable's static
     * return type is the base AgentResponse.
     */
    private function structured(AgentResponse $response): StructuredAgentResponse
    {
        if (! $response instanceof StructuredAgentResponse) {
            throw new LogicException('Expected a structured response from a RAGAS judge agent.');
        }

        return $response;
    }

    private function score(AgentResponse $response): float
    {
        return (float) $this->structured($response)['score'];
    }

    /**
     * @return Collection<int, DocumentChunk>
     */
    private function orderedChunks(Query $query): Collection
    {
        $chunkIds = is_array($query->retrieved_chunk_ids) ? $query->retrieved_chunk_ids : [];

        $chunksById = DocumentChunk::query()->whereIn('id', $chunkIds)->get()->keyBy('id');

        return (new Collection($chunkIds))
            ->map(fn (int $id) => $chunksById->get($id))
            ->filter();
    }

    /**
     * @param  Collection<int, DocumentChunk>  $chunks
     */
    private function formatChunks(Collection $chunks): string
    {
        if ($chunks->isEmpty()) {
            return '(no context retrieved)';
        }

        return $chunks
            ->values()
            ->map(fn (DocumentChunk $chunk, int $rank) => "[rank {$rank}] {$chunk->content}")
            ->implode("\n\n");
    }

    /**
     * @param  Collection<int, DocumentChunk>  $chunks
     */
    private function buildContextPrecisionPrompt(string $question, Collection $chunks): string
    {
        return <<<PROMPT
            Question: {$question}

            Retrieved chunks (ranked, most relevant-per-the-retriever first):
            {$this->formatChunks($chunks)}
            PROMPT;
    }

    /**
     * @param  Collection<int, DocumentChunk>  $chunks
     */
    private function buildContextRecallPrompt(string $groundTruthAnswer, Collection $chunks): string
    {
        return <<<PROMPT
            Ground-truth answer:
            {$groundTruthAnswer}

            Retrieved chunks:
            {$this->formatChunks($chunks)}
            PROMPT;
    }

    /**
     * @param  Collection<int, DocumentChunk>  $chunks
     */
    private function buildFaithfulnessPrompt(string $answer, Collection $chunks): string
    {
        return <<<PROMPT
            Generated answer:
            {$answer}

            Retrieved context:
            {$this->formatChunks($chunks)}
            PROMPT;
    }

    private function buildAnswerRelevancePrompt(string $question, string $answer): string
    {
        return <<<PROMPT
            Question: {$question}

            Generated answer:
            {$answer}
            PROMPT;
    }
}
