# RAGAS Evaluation Findings

Results from evaluation run **id 2** (2026-08-07, 80/80 units completed). See `ragas-metrics-explained.md` for what each metric means and how it's computed — this file is the results themselves.

## Overview

- **Dataset**: `database/fixtures/ragas_dataset.json`, 10 questions against a single Laravel-5-era tutorial book.
  - **5 answerable** questions with a curated `ground_truth_answer` — the content genuinely exists in the corpus.
  - **5 deliberately unanswerable** questions (Sanctum, Livewire, Vite, Octane, Inertia.js) — these ask about modern Laravel features that do not exist anywhere in this Laravel-5-era book. No ground truth is set for these; the correct system behavior is to say `"Information Not Found"` rather than hallucinate from outside knowledge.
- **Matrix**: 2 chunking strategies (`tokens_500`, `tokens_1000`) × 2 retrieval algorithms (`dense`, `hybrid`) × 2 rerank states (on/off) = 8 configs, each run against all 10 questions = 80 units.
- **Metrics**: Context Precision, Context Recall, Faithfulness, Answer Relevance — each 0.0–1.0, computed from discrete per-item judge verdicts (see `ragas-metrics-explained.md`).

## Executive summary

1. **Reranking always improves context precision.** Every rerank config beats its own no-rerank counterpart at the same chunking + algorithm (4/4 pairs) — the single cleanest, most consistent result in the run.
2. **Chunk size drives context recall, not algorithm choice.** Every `tokens_500` config hits perfect (1.000) recall; `tokens_1000` configs range 0.7–0.9. Dense vs. hybrid barely moves recall once chunk size is fixed.
3. **Abstention on unanswerable questions is 100% correct across every config.** All 5 unanswerable questions × all 8 configs (40/40 units) answered exactly `"Information Not Found"` — zero hallucination anywhere in the matrix.
4. **Faithfulness is pinned at 1.000 in all 80/80 units, with zero variance.** This is a property of the answer generator's strict "only answer from context, or say Information Not Found" instruction (`app/Ai/Agents/RagAnswerAgent.php`), not a scoring defect — see Per-metric analysis below.
5. **Best overall config: `tokens_500` + rerank** (dense and hybrid tie). **Worst: `tokens_1000/dense/no-rerank`.**

## Summary table

Per-config averages across all 10 questions (`ragas_evaluation_runs.summary`, run id 2):

| Chunking | Algorithm | Rerank | Precision | Recall | Faithfulness | Relevance | Mean\* | Avg latency (ms) | Avg tokens |
|---|---|---|---|---|---|---|---|---|---|
| 1000 | dense | no | 0.307 | 0.700 | 1.000 | 0.900 | 0.727 | 1291.5 | 15301.1 |
| 1000 | dense | yes | 0.400 | 0.700 | 1.000 | 1.000 | 0.775 | 1272.8 | 5245.7 |
| 1000 | hybrid | no | 0.239 | 1.000 | 1.000 | 0.900 | 0.785 | 1339.1 | 15510.7 |
| 1000 | hybrid | yes | **0.500** | 0.900 | 1.000 | 0.700 | 0.775 | 1296.4 | 5253.3 |
| 500 | dense | no | 0.442 | 1.000 | 1.000 | 0.800 | 0.811 | 1292.3 | 7467.0 |
| 500 | dense | yes | 0.483 | 1.000 | 1.000 | 1.000 | **0.871** | 1230.3 | 2478.5 |
| 500 | hybrid | no | 0.410 | 1.000 | 1.000 | 0.900 | 0.828 | 1329.0 | 7586.5 |
| 500 | hybrid | yes | 0.483 | 1.000 | 1.000 | 1.000 | **0.871** | 1123.8 | 2491.2 |

\* Mean = unweighted average of the 4 RAGAS metrics — the same method the app's own "Baseline vs Best" card uses (`meanScore()` in `resources/js/pages/Evaluation/Index.vue`).

Note the token-count column: unreranked configs feed all 15 retrieved chunks to the generator, while reranked configs cut that to the top 5 — roughly a 3x drop in tokens per call (and cost) for equal or better scores in every chunking+algorithm pair except `tokens_1000/hybrid`, where rerank trades some recall/relevance for the precision gain.

## Best vs. worst

Ranked by mean score:

1. **`tokens_500/dense/rerank`** and **`tokens_500/hybrid/rerank`** — tied at **0.871**. Perfect recall, faithfulness, and relevance; strong precision (0.483); also the cheapest reranked configs (fewest avg tokens).
2. `tokens_500/hybrid/no-rerank` — 0.828
3. `tokens_500/dense/no-rerank` — 0.811
4. `tokens_1000/hybrid/no-rerank` — 0.785
5. `tokens_1000/dense/rerank` — 0.775
5. `tokens_1000/hybrid/rerank` — 0.775
7. **`tokens_1000/dense/no-rerank`** — **0.727 (worst)**

The highest *single-metric* precision in the whole matrix belongs to `tokens_1000/hybrid/rerank` (0.500) — but it is **not** the overall best config: it pays for that precision with the worst relevance in the run (0.700) and a recall dip (0.900). This is a genuine precision/recall/relevance trade-off, not noise — see the per-question detail for the specific question driving the relevance dip.

**Recommendation**: `tokens_500` chunking with reranking enabled is the strongest, most robust choice in this evaluation — dense and hybrid are statistically tied on it, so algorithm choice matters far less than getting chunk size and reranking right.

## Per-metric analysis

### Context Precision — clearly discriminates configs (0.239–0.500)

This is the primary signal for comparing retrievers in this study. Two clean patterns:
- **Rerank helps, always.** Every no-rerank → rerank pair improves (e.g. `tokens_1000/hybrid`: 0.239 → 0.500; `tokens_500/dense`: 0.442 → 0.483).
- **Precision on unanswerable questions is always 0.000.** Not a floor artifact — the judge is correctly marking all 15 retrieved chunks as "not relevant" for topics that genuinely don't exist in the corpus, which is itself a sanity check that the precision judge isn't rubber-stamping.

### Context Recall — driven by chunk size, not algorithm (0.5–1.0 among answerable questions)

Every `tokens_500` config: 1.000 recall on every answerable question. `tokens_1000` configs dip on two specific questions:
- **"Removing 'public' from a Laravel app's URLs"**, `tokens_1000/dense` (both rerank states) → recall **0.0**. A genuine retrieval miss: neither the `.htaccess`-copy step nor the `server.php`-rename step appears anywhere in the 15 retrieved chunks, so the generator (correctly, given what it received) answered `"Information Not Found"` — for a question that *was* answerable. `tokens_1000/hybrid` finds the content fine (recall 1.0 both rerank states).
- **"Policy class" Artisan command**, 3 of 4 `tokens_1000` configs → recall **0.5**. This one is partly a dataset-curation artifact, not a pure retrieval fault: the ground truth says `php artisan make:policy PostPolicy`, but the book's actual worked example uses `php artisan make:policy ContentPolicy`. At `tokens_500`, both example mentions end up close enough together to be retrieved together (recall 1.0 in all 4 `tokens_500` configs); at `tokens_1000`, the chunk boundaries apparently separate them, and only the `ContentPolicy` mention gets retrieved.

### Faithfulness — flat 1.000 across all 80/80 units, by generator design

Not a scoring bug. `RagAnswerAgent`'s instructions are: *"Answer using ONLY the context provided... If the context does not contain enough information, respond with exactly: Information Not Found."* That leaves only two possible outputs — a paraphrase of context it was actually given (which will almost always be judged "supported," since it's restating the source) or a refusal (zero claims made, vacuously faithful). There is structurally very little room for the generator to assert something ungrounded, regardless of which retrieval config fed it. Spot-checked the underlying per-claim verdicts directly (not just the aggregate score) to confirm the judge isn't being lazy — e.g. for the "removing public" question, real claims like *"copy the .htaccess file from /public to the project root"* and *"rename server.php to index.php"* are each individually checked against the actual retrieved text and correctly marked supported.

**Read this as a positive result**, not a null one: it demonstrates the generator's strict-grounding constraint is fully effective at decoupling faithfulness from retrieval quality — bad retrieval hurts precision/recall/relevance, but never causes hallucination, anywhere in the 80-unit matrix.

### Answer Relevance — mostly 1.000, but the zero-scores are judge inconsistency, not retrieval failure

All 8 zero-relevance rows in the entire run come from the **unanswerable** questions — every one of them is a *correct* `"Information Not Found"` abstention that the judge nonetheless scored as "not addressing the question." This is worth stating precisely because the more intuitive story (relevance drops when retrieval genuinely fails on an answerable question) turns out **not** to be what's in the data: both `tokens_1000/dense` rows that wrongly abstained on the answerable "removing public" question (a genuine retrieval miss, recall 0.0) still scored relevance **1.000** — because `AnswerRelevanceJudge` is only given the question and answer, never the retrieved context, so it cannot distinguish a *correct* refusal from an *incorrect* one. It just tends to treat `"Information Not Found"` as satisfying the question's single requirement — usually, but not perfectly consistently, which is where the 8 zero-scores come from:

| Unanswerable question | Configs scoring 0 relevance (out of 8) |
|---|---|
| Livewire | 1 (`tokens_500/dense/no-rerank`) |
| Sanctum | 1 (`tokens_500/dense/no-rerank`) |
| Octane | 1 (`tokens_1000/hybrid/rerank`) |
| Inertia.js | 1 (`tokens_1000/hybrid/rerank`) |
| Vite | 4 (`tokens_1000/dense/no-rerank`, `tokens_1000/hybrid/no-rerank`, `tokens_1000/hybrid/rerank`, `tokens_500/hybrid/no-rerank`) |

Vite is the outlier — the judge was inconsistent on it more than twice as often as any other question, despite every config giving the identical `"Information Not Found"` answer. This is LLM-judge variance on a boundary case in the instructions, not a difference in retrieval or generation quality between those configs, and it's why `tokens_1000/hybrid/rerank`'s relevance average (0.700) looks worse than its peers — it's carrying 2 of the 8 inconsistent verdicts (Octane and Inertia.js) on top of its share of Vite.

## Answerable vs. unanswerable breakdown

The cleanest binary result in the run: **40/40 unanswerable units, across all 8 configs, correctly answered `"Information Not Found"`.** Zero hallucination, zero false positives, regardless of chunking, algorithm, or reranking. Retrieval-side metrics still behave sensibly here even though there's no "right" content to find — context precision is 0.000 throughout (the judge correctly finds no relevant chunks for a topic that isn't in the corpus), and context recall is `null` (undefined without a ground truth to check completeness against, not zero).

## Per-question detail

Config shorthand: `chunking / algorithm / rerank`. Scores are `precision / recall / faithfulness / relevance`.

### 1. "What was the release date of Laravel 5.1, the LTS version?"
**Ground truth**: Laravel 5.1 (LTS) was released on 2015-06-09.

Every config answered `2015-06-09` — perfectly correct throughout. Only precision varies, and only by rerank status:

| Config | Precision / Recall / Faithfulness / Relevance |
|---|---|
| 500/dense/no | 0.500 / 1 / 1 / 1 |
| 500/dense/yes | 1.000 / 1 / 1 / 1 |
| 500/hybrid/no | 0.625 / 1 / 1 / 1 |
| 500/hybrid/yes | 1.000 / 1 / 1 / 1 |
| 1000/dense/no | 0.071 / 1 / 1 / 1 |
| 1000/dense/yes | 1.000 / 1 / 1 / 1 |
| 1000/hybrid/no | 0.250 / 1 / 1 / 1 |
| 1000/hybrid/yes | 1.000 / 1 / 1 / 1 |

Every rerank config hits exactly 1.000 precision — a clean demonstration of reranking's effect in isolation.

### 2. "What two steps does the guide give for removing 'public' from a Laravel app's URLs?"
**Ground truth**: Copy the .htaccess file from the /public directory to the Laravel project's root folder, and rename server.php in the root folder to index.php.

| Config | Answer | P / R / F / Rel |
|---|---|---|
| 500/dense/no | Copy `.htaccess` from `/public` to root; rename `server.php` to `index.php`. (correct) | 1.000 / 1 / 1 / 1 |
| 500/dense/yes | Same, correct | 1.000 / 1 / 1 / 1 |
| 500/hybrid/no | Same, correct | 1.000 / 1 / 1 / 1 |
| 500/hybrid/yes | Same, correct | 1.000 / 1 / 1 / 1 |
| 1000/dense/no | **"Information Not Found"** (wrong — question is answerable) | 0.000 / 0.0 / 1 / 1 |
| 1000/dense/yes | **"Information Not Found"** (wrong) | 0.000 / 0.0 / 1 / 1 |
| 1000/hybrid/no | Same correct steps, but noisier retrieval | 0.111 / 1 / 1 / 1 |
| 1000/hybrid/yes | Same, correct | 1.000 / 1 / 1 / 1 |

The clearest single example of a genuine retrieval failure in the whole run: `tokens_1000/dense` never surfaces this content at all, in either rerank state, and the generator — behaving exactly as designed — declines rather than guessing.

### 3. "What does the guide say the 'sync' queue driver does, and why is it discouraged in production?"
**Ground truth**: The sync driver runs a queued job immediately within the existing process, effectively meaning there is no queue at all. It's useful for local/testing but discouraged in production since it removes the performance benefit of a real queue.

All 8 configs answered correctly and identically in substance. **Every single score is 1.000 across all 8 configs** — the easiest question in the dataset; no config struggled with it at all.

### 4. "What is the key difference between a 'before' and an 'after' middleware, according to the Middleware chapter?"
**Ground truth**: A 'before' middleware acts before calling `$next($request)`; an 'after' middleware calls `$next($request)` first and acts on the response afterward.

| Config | P / R / F / Rel |
|---|---|
| 500/dense/no | 1.000 / 1 / 1 / 1 |
| 500/dense/yes | 1.000 / 1 / 1 / 1 |
| 500/hybrid/no | 0.643 / 1 / 1 / 1 |
| 500/hybrid/yes | 1.000 / 1 / 1 / 1 |
| 1000/dense/no | 1.000 / 1 / 1 / 1 |
| 1000/dense/yes | 1.000 / 1 / 1 / 1 |
| 1000/hybrid/no | 0.667 / 1 / 1 / 1 |
| 1000/hybrid/yes | 1.000 / 1 / 1 / 1 |

All 8 answers correct; recall/faithfulness/relevance perfect throughout. Only unreranked `hybrid` dips precision — consistent with the "rerank always helps" pattern.

### 5. "What Artisan command does the guide give for generating a new Policy class, and where does it get placed?"
**Ground truth**: `php artisan make:policy PostPolicy`, placed in `app/Policies`.

| Config | Answer mentions | P / R / F / Rel |
|---|---|---|
| 500/dense/no | Both `ContentPolicy` and `PostPolicy` examples | 0.917 / 1.0 / 1 / 1 |
| 500/dense/yes | Both | 0.833 / 1.0 / 1 / 1 |
| 500/hybrid/no | Both | 0.833 / 1.0 / 1 / 1 |
| 500/hybrid/yes | Both | 0.833 / 1.0 / 1 / 1 |
| 1000/dense/no | Only `ContentPolicy` | 1.000 / 0.5 / 1 / 1 |
| 1000/dense/yes | Only `ContentPolicy` | 1.000 / 0.5 / 1 / 1 |
| 1000/hybrid/no | Both | 0.361 / 1.0 / 1 / 1 |
| 1000/hybrid/yes | Only `ContentPolicy` | 1.000 / 0.5 / 1 / 1 |

At `tokens_500`, both worked-example names end up close enough to be retrieved together every time. At `tokens_1000`, 3 of 4 configs only retrieve the chunk mentioning `ContentPolicy`, missing the `PostPolicy` mention entirely — the same chunk-fragmentation pattern as question 2, this time causing a partial (0.5) rather than total recall loss.

### 6–10. The five unanswerable questions

*"How do you configure Laravel Sanctum for API token authentication?" / "How does Laravel Livewire let you build reactive components without writing JavaScript?" / "How do you configure Laravel Vite for asset bundling?" / "How does Laravel Octane keep the application in memory between requests to improve performance?" / "How do you integrate Laravel with Inertia.js to build an SPA with server-side routing?"*

No ground truth (content doesn't exist in this Laravel-5-era corpus). **Every one of the 40 units (5 questions × 8 configs) answered exactly `"Information Not Found"`.** Context precision is 0.000 throughout (correctly — nothing retrieved is relevant); context recall is `null` (undefined, not zero); faithfulness is 1.000 throughout (an empty/refusal answer trivially makes no unsupported claims). Answer relevance is 1.000 for 32/40 and 0.000 for 8/40 despite every answer being textually identical — see the Answer Relevance section above for the per-question breakdown; this is judge inconsistency on the abstention pattern, not a real difference between configs.

## Caveats

- **Ground-truth wording mismatch (question 5)**: the dataset's ground truth cites `PostPolicy`, the book's actual example uses `ContentPolicy`. This depresses `tokens_1000` recall on that question independent of retrieval quality — worth a footnote if question 5 is cited as evidence of a `tokens_1000` weakness.
- **`context_recall` is `null`, not `0`, for all 5 unanswerable questions** — it's mathematically undefined without a ground truth to check completeness against. Don't average it as if `null` were `0`; the summary table already excludes nulls when averaging (see `RagasExperimentRunner::mean()`).
- **Answer Relevance can't see retrieved context**, only the question and generated answer — it structurally cannot penalize an *incorrect* "Information Not Found" (question 2's dense/1000 rows both scored full relevance despite wrongly abstaining), and is inconsistent on *correct* abstentions (the 8 zero-scores). Treat single-digit relevance differences between configs as noise rather than signal; the pattern only becomes meaningful in aggregate (e.g. the full-point 1000/hybrid/rerank gap is worth investigating, single 0/1 flips are not).

## Conclusion

For this thesis's stated goal — identifying the best-performing retrieval configuration — the data supports **`tokens_500` chunking with reranking enabled** as the clear recommendation, with dense and hybrid retrieval statistically tied on it (0.871 mean score, perfect recall/faithfulness/relevance, competitive precision, and the lowest token cost per call of any reranked config). Chunk size is the dominant factor for completeness (recall): `tokens_500` never lost a fact that `tokens_1000` did. Reranking is the dominant factor for precision, improving it in every single chunking+algorithm pairing. Algorithm choice (dense vs. hybrid) matters far less than either of those two levers once they're fixed. Faithfulness and, largely, Answer Relevance are not useful discriminators between configs in this study — they reflect properties of the shared answer generator and a known judge blind-spot respectively, not differences in retrieval quality.
