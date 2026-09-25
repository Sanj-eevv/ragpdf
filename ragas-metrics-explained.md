# RAGAS Metrics Explained

What each of the four RAGAS scores actually measures, how this codebase computes it, and why some of them vary a lot between configs while others don't. See `ragas-findings.md` for the actual results this all applies to.

## The four metrics, in one line each

| Metric | Judges | Answers |
|---|---|---|
| Context Precision | `ContextPrecisionJudge` | Of what was retrieved, how much is actually useful? |
| Context Recall | `ContextRecallJudge` | Of what's needed to answer, how much did retrieval find? |
| Faithfulness | `FaithfulnessJudge` | Is the generated answer actually grounded in the retrieved context? |
| Answer Relevance | `AnswerRelevanceJudge` | Does the answer actually address what was asked? |

Precision and Recall judge the **retriever/reranker**. Faithfulness and Answer Relevance judge the **generator**. That split matters — see the last section.

## Context Precision

**Definition**: of the chunks the retriever pulled back, how many are genuinely useful evidence for answering the question — and are the useful ones ranked near the top, or buried under noise?

**How it's computed here**: `ContextPrecisionJudge` is given the question and the ranked list of retrieved chunks, and returns one binary `relevant: true/false` verdict per chunk (not a single freehand score). `RagasEvaluator::contextPrecisionScore()` then turns those verdicts into **Average Precision**: for every chunk marked relevant, take precision@k at that chunk's rank, then average those precision@k values over just the relevant chunks. A relevant chunk ranked #1 contributes more than the same chunk ranked #10 — this rewards good *ranking*, not just eventually including the right thing somewhere in the top 15.

It only looks at the retrieved chunks and the question — it has no idea what the "correct" answer is, and doesn't care what the generator does with the chunks afterward.

## Context Recall

**Definition**: of everything actually needed to answer the question correctly (per a verified ground-truth answer), how much of it shows up *somewhere* in the retrieved chunks, at any rank?

**How it's computed here**: `ContextRecallJudge` breaks the ground-truth answer into atomic factual statements (e.g. "the command is `make:policy PostPolicy`" and "it's placed in `app/Policies`" as two separate statements), and returns an `attributed: true/false` verdict per statement — was this specific fact found anywhere in the retrieved chunks? `RagasEvaluator::ratioScore()` computes the score as `attributed / total`. Rank position doesn't matter here, only presence.

Recall is undefined (not zero) when there's no ground truth to check against — which is exactly the 5 unanswerable questions in this dataset (`context_recall` is `null` for those, on purpose).

## Precision vs. Recall — the difference, with real numbers

**Precision = "is what I retrieved clean?"** **Recall = "did I retrieve everything I need?"** You can have one without the other, and this run has a textbook example:

- `tokens_1000/hybrid/no-rerank` on the "removing 'public' from the URL" question: precision **0.111** (mostly noise — only about 1 in 9 retrieved chunks was actually useful) but recall **1.000** (despite all that noise, both needed facts still showed up somewhere in the 15 retrieved chunks).
- `tokens_1000/hybrid/rerank` on the "Policy class" question: precision **1.000** (every retrieved chunk was useful) but recall **0.5** (reranking narrowed the context down to the top 5, and in doing so dropped a chunk that held one of the two needed facts).

Broad-but-noisy vs. tight-but-occasionally-incomplete is a real trade-off, not a bug in either metric.

## Faithfulness

**Definition**: is every claim in the generated answer actually supported by the retrieved context, or did the generator assert something the context doesn't back up (a hallucination)?

**How it's computed here**: `FaithfulnessJudge` breaks the generated answer into atomic factual claims and returns a `supported: true/false` verdict per claim, checked directly against the retrieved context. `RagasEvaluator::ratioScore()` computes `supported / total`. An answer that makes zero claims (e.g. `"Information Not Found"`) scores 1.0 by convention — it can't hallucinate if it doesn't assert anything.

**Why it's 1.000 for every single config in this run**: this measures the *generator*, and every config shares the same generator (`RagAnswerAgent`), which is explicitly instructed to answer only from the provided context or say `"Information Not Found"` otherwise. That structurally leaves almost no room to assert something ungrounded — whatever context it's handed, it either paraphrases that context (faithful by construction) or declines (vacuously faithful). Faithfulness in this study is therefore a property of the generator's prompt, not of which retrieval config fed it — retrieval quality shows up in precision/recall/relevance instead.

## Answer Relevance

**Definition**: does the generated answer actually address what the question is asking, independent of whether it's factually correct?

**How it's computed here**: `AnswerRelevanceJudge` breaks the *question* down into the distinct informational requirements a fully relevant answer would need to satisfy (usually just one), and returns an `addressed: true/false` verdict per requirement. `RagasEvaluator::ratioScore()` computes `addressed / total`.

**Important limitation**: this judge is only given the question and the generated answer — never the retrieved context. It cannot tell whether `"Information Not Found"` was the *correct* call or a retrieval failure; it can only judge whether the answer engages with the question at a surface level. In practice this means it's lenient on *incorrect* abstentions (a wrong "Information Not Found" on an answerable question still scores full relevance, since the judge can't know retrieval failed) and occasionally inconsistent on *correct* ones — the same `"Information Not Found"` answer to the same unanswerable question sometimes scores 1.0 and sometimes 0.0 across otherwise-identical configs. Single-point relevance differences between configs should be read as judge noise, not signal; only large, repeated gaps are meaningful.

## Why the judges return discrete verdicts instead of a single score

Earlier versions of these four judges asked the LLM to freehand a single `0.0–1.0` number directly. That was replaced with itemized boolean verdicts (per chunk / per statement / per claim / per requirement) plus a deterministic formula (`RagasEvaluator::contextPrecisionScore()` / `ratioScore()`) that computes the final score from those verdicts. Two reasons:

1. **It's the actual RAGAS methodology** — Context Precision as Average Precision over relevance verdicts, Context Recall/Faithfulness as ratios over statement/claim verdicts, is how the original RAGAS paper defines these metrics, not "ask a model to guess a float."
2. **It's far more reproducible.** A model asked to output one float directly tends to cluster on round numbers and drift between otherwise-identical runs. A model asked "is this one specific, small thing true or false?" repeated per item, with the arithmetic done outside the LLM, is much more consistent.

## FAQ: why didn't everything vary?

Two of the four metrics (context precision, context recall) clearly discriminate between configs in this run — see `ragas-findings.md`. The other two mostly don't, and now you know why:

- **Faithfulness is flat because the generator is deliberately hard to make unfaithful** (see above) — this is the intended behavior the "Accuracy Fallacy" part of the thesis is testing, not something to "fix" by loosening the prompt. Loosening it would let the generator hallucinate on weak context, which would make faithfulness vary — but at the cost of undermining the exact hypothesis (a well-designed generator should decline rather than hallucinate) the constraint exists to demonstrate.
- **Answer Relevance mostly doesn't vary because most of the dataset's questions are single-fact asks** that any config either answers correctly or declines on — and because the judge structurally can't see whether a decline was justified. The one real signal buried in it (the `tokens_1000/hybrid/rerank` relevance dip) is documented in `ragas-findings.md`.

If you need a metric to differentiate every config from every other one, Context Precision is that metric here (0.239–0.500 spread) — it's the one actually designed to isolate retriever/reranker quality, which is what this thesis is comparing.
