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
 * uses gemini-3.6-flash explicitly, per the thesis (the AI SDK's Gemini
 * default may change independently of this evaluation's model choice).
 */
class RagasEvaluator
{
    private const string MODEL = 'gemini-3.6-flash';

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
            'context_precision' => $this->contextPrecisionScore($contextPrecision),
            'context_recall' => $contextRecall === null ? null : $this->ratioScore($contextRecall, 'statements', 'attributed'),
            'faithfulness' => $this->ratioScore($faithfulness, 'claims', 'supported'),
            'answer_relevance' => $this->ratioScore($answerRelevance, 'requirements', 'addressed'),
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

    /**
     * RAGAS Context Precision = Average Precision over the judge's per-chunk
     * relevant/not-relevant verdicts: for every chunk verdicted relevant,
     * take precision@k at that chunk's position, then average those
     * precision@k values over the relevant chunks. This rewards relevant
     * chunks being ranked higher over the exact same chunks ranked lower,
     * unlike a flat relevant/total ratio.
     */
    private function contextPrecisionScore(AgentResponse $response): float
    {
        $verdicts = $this->structured($response)['verdicts'];

        $relevantCount = 0;
        $precisionSum = 0.0;

        foreach (array_values($verdicts) as $index => $verdict) {
            if (! $verdict['relevant']) {
                continue;
            }

            $relevantCount++;
            $precisionSum += $relevantCount / ($index + 1);
        }

        return $relevantCount === 0 ? 0.0 : $precisionSum / $relevantCount;
    }

    /**
     * Shared by context recall, faithfulness, and answer relevance: each
     * judge decomposes its input into discrete, independently verifiable
     * items and returns a boolean verdict per item rather than a single
     * float chosen freehand. The score is then a deterministic ratio over
     * those verdicts — positive/total — instead of a number the model
     * estimated itself, which is both the actual RAGAS methodology and far
     * more consistent run to run. An empty item list (e.g. an answer with
     * no factual claims) scores 1.0 — vacuously true, nothing was
     * unsupported.
     *
     * @param  'statements'|'claims'|'requirements'  $itemsKey
     * @param  'attributed'|'supported'|'addressed'  $verdictKey
     */
    private function ratioScore(AgentResponse $response, string $itemsKey, string $verdictKey): float
    {
        $items = new Collection($this->structured($response)[$itemsKey]);

        if ($items->isEmpty()) {
            return 1.0;
        }

        return $items->filter(fn (array $item) => $item[$verdictKey])->count() / $items->count();
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
        return "Question: {$question}\n\n"
            ."Retrieved chunks (ranked, most relevant-per-the-retriever first):\n"
            .$this->formatChunks($chunks);
    }

    /**
     * @param  Collection<int, DocumentChunk>  $chunks
     */
    private function buildContextRecallPrompt(string $groundTruthAnswer, Collection $chunks): string
    {
        return "Ground-truth answer:\n{$groundTruthAnswer}\n\n"
            ."Retrieved chunks:\n"
            .$this->formatChunks($chunks);
    }

    /**
     * @param  Collection<int, DocumentChunk>  $chunks
     */
    private function buildFaithfulnessPrompt(string $answer, Collection $chunks): string
    {
        return "Generated answer:\n{$answer}\n\n"
            ."Retrieved context:\n"
            .$this->formatChunks($chunks);
    }

    private function buildAnswerRelevancePrompt(string $question, string $answer): string
    {
        return "Question: {$question}\n\n"
            ."Generated answer:\n{$answer}";
    }
}
