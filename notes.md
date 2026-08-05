# Notes: Thesis Alignment & Research-Gap Fulfillment

This file is the honest cross-check between what the thesis (`~/Desktop/thesis draft 3.docx`) specifies and what was actually built. `plan.md` is the build log; `gaps.md` is the operational follow-up list; this file answers a different question: **does the code say what the thesis says, and does any of this actually satisfy the research gap yet?**

---

## Short answer

**The system fulfills Research Objective 1** ("design and develop a localized RAG web application..."). It does **not yet** fulfill Research Objective 2 ("evaluate the impact of different chunking strategies and hybrid retrieval approaches... using the RAGAS framework"), and therefore **does not yet fulfill the research gap** identified in Chapter 2.6. The research gap is an empirical claim ("a localized web stack *can* mitigate hallucinations, *as measured by* RAGAS") — that requires actually running the experiment on real data and reporting real numbers. Nobody has done that yet: the evaluation dataset is a 2-row placeholder, no `GEMINI_API_KEY` has been used to make a real call, and zero real `RagasEvaluation` rows exist. Everything built is the *instrument* for closing the gap, not the closing of it.

What's left to actually close the gap (see `gaps.md` #2 and #8 for the operational detail):
1. Add real API keys.
2. Pick 5 real structurally-complex PDFs and curate 50 real questions (mix of answerable/unanswerable) with verified ground-truth answers.
3. Run `php artisan rag:evaluate` for real.
4. Analyze whether the results actually show what the thesis hypothesizes (hybrid+rerank beats naive dense on faithfulness/context precision, and that the Accuracy Fallacy shows up in the unanswerable-question subset).

---

## Chapter/algorithm → implementation map

| Thesis element | Where it lives in code | Fidelity |
|---|---|---|
| 3.2.1 Laravel + Vue.js + async job queues | `app/Jobs/*`, `Bus::chain(...)` in `DocumentController@store` | Exact |
| 3.2.2 `document_chunks` table (`text`, `document_id`, `embedding VECTOR(1536)`) | `database/migrations/..._create_document_chunks_table.php` | **Diverges — see below.** Table now stores `VECTOR(384)`, not `VECTOR(1536)`, since embeddings come from a self-hosted 384-dim model instead of OpenAI's 1536-dim one. Extra columns (`chunking_strategy`, `chunk_index`, `token_count`) still present as before |
| Algorithm 1 — Pure Dense Retrieval (`ORDER BY embedding <=> query LIMIT 15`, no threshold) | `app/Services/Retrieval/DenseRetriever.php` | Exact — required overriding Laravel's `whereVectorSimilarTo` default `minSimilarity: 0.6` to `-1.0` to remove the threshold the thesis doesn't have |
| Algorithm 2 — Hybrid RRF (`k ≈ 60`) | `app/Services/Retrieval/HybridRetriever.php` + `ReciprocalRankFusion.php` (`$k = 60` default) | Exact |
| HNSW index via pgvector | `document_chunks` migration: `$table->vector(...)->index()` | Exact (Laravel's vector index defaults to HNSW + cosine distance) |
| `to_tsvector`/`ts_rank` lexical search | `HybridRetriever::lexicalSearch()` | Exact |
| Cross-Encoder re-ranking | `app/Services/Retrieval/ChunkReranker.php` via a self-hosted `rerank/` FastAPI sidecar (`cross-encoder/ms-marco-MiniLM-L-6-v2`) | Exact — resolved from an earlier hosted-API iteration, see below |
| `text-embedding-3-small`, 1536-dim | `app/Jobs/EmbedChunksJob.php` + `app/Services/Retrieval/ChunkEmbedder.php`, via a self-hosted `all-MiniLM-L6-v2` (384-dim) model on the `rerank` FastAPI sidecar | **Diverges — see below.** No longer OpenAI, no longer 1536-dim, no longer goes through the AI SDK at all |
| `gpt-3.5-turbo` generation, "Information Not Found" instruction | `app/Ai/Agents/RagAnswerAgent.php` + `app/Services/AnswerGenerator.php` (`MODEL = 'gemini-3.5-flash-lite'`) | Instruction wording still exact; **model swapped OpenAI → Gemini, see below** |
| GPT-4o judge, 4 RAGAS metrics | `app/Ai/Agents/Judges/*`, `RagasEvaluator` (`MODEL = 'gemini-3.6-flash'`) | Metric descriptions still mirror the thesis's Chapter 3.4 table and Chapter 2.4.1 exactly; **judge model swapped OpenAI → Gemini, see below** |
| 500-token / 1000-token chunking, ~10% overlap | `app/Enums/ChunkingStrategy.php`, `TextSplitter.php` | **Approximate, not exact — see divergences below** |
| 50 questions / 5 PDFs, answerable + unanswerable | `database/fixtures/ragas_dataset.json` | **Not done — placeholder only, needs the user** |
| Naive vs Advanced Hybrid+Cross-Encoder comparison | `rag:evaluate`'s full 2×2×2 config matrix | **Broader than the thesis literally describes — see below** |

---

## Where the implementation diverges from the thesis's literal text

### 1. Token counting is approximate, not exact (real fidelity gap)
The thesis talks about "500-token chunks" and "1000 tokens" as if token count is a precise, measured quantity — which for OpenAI's real tokenizer, it is. This implementation estimates tokens as `chars ÷ 4` (a common rule of thumb) instead of using OpenAI's actual BPE tokenizer, to avoid adding a new PHP dependency. **Practical effect**: chunk boundaries are approximately, not exactly, 500/1000 OpenAI tokens. If your methodology chapter states "chunks of exactly 500 tokens," that's not literally true of this implementation — you'd need to either (a) caveat this as an approximation, or (b) swap in a real tokenizer (e.g. a PHP tiktoken port) before running the real experiment. Tracked in `gaps.md` #6.

### 2. ~~The re-ranker is a hosted API~~ — RESOLVED: now a real self-hosted cross-encoder
This was flagged in an earlier version of this file: reranking briefly went through `Laravel\Ai\Reranking` backed by Jina's hosted API (a genuine cross-encoder architecture under the hood, so the *technique* claim held, but not a locally-run model). After reconsidering, it's back to a **self-hosted** Python FastAPI sidecar (`rerank/`) running `cross-encoder/ms-marco-MiniLM-L-6-v2` via `sentence-transformers` — a literal, locally-run "Cross-Encoder re-ranking model" exactly as the thesis words it, with no external API key and no per-call cost. Verified for real (not just against fakes): built the Docker image, brought the service up, and hit `/rerank` directly with the thesis's own vocabulary-mismatch example (Chapter 2.2.1 — "respiratory illness treatment" query against a pneumonia-treatment document with no shared vocabulary) — the real model correctly ranked it highest.

**For your write-up**: you can now describe the re-ranking step exactly as the thesis does, without a caveat about hosting. The trade-off you accepted for this: one more Docker service to build/maintain (`rerank/`, in `docker-compose.yaml`), and a noticeably heavier `app` image build (`sentence-transformers` + `torch`, CPU-only, ~130s build time, model baked in at build time so no network dependency at request time).

### 3. The implementation uses Laravel's official AI SDK for generation/judging, not raw provider HTTP calls — embeddings no longer go through it at all
The thesis's Chapter 3.2/3.3 reads as if the OpenAI API is called fairly directly/manually ("Laravel immediately converts it into an embedding using the OpenAI API"). This build uses `laravel/ai` (Laravel's first-party AI SDK, `Laravel\Ai\Agent` classes) for the hosted-LLM steps — answer generation, RAGAS judging — rather than hand-rolled HTTP client calls. Two steps do **not** go through this SDK: reranking (see #2) and, as of this iteration, embeddings too (see #6) — both are plain `Illuminate\Support\Facades\Http` calls to the self-hosted sidecar instead. Worth naming the actual tool in your methodology chapter (`laravel/ai`) for the generation/judging steps specifically, since a reader who goes looking at the code should recognize what you describe — but do not describe embeddings as going through it, since they now don't.

### 4. The experiment runner does a full 2×2×2 matrix, not just "Naive vs Advanced"
Chapter 3.4's closing paragraph describes running the dataset through "the baseline Naive configuration, and subsequently through the proposed Advanced Hybrid and Cross-Encoder configurations" — read literally, that's **two** configurations. But Chapter 3.1.1 lists chunking strategy, retrieval algorithm, and post-retrieval refinement as **three independent variables**, which implies wanting to see each factor's effect in isolation, not just two bundled endpoints. `rag:evaluate` resolves this by running the **full factorial matrix** (all 8 combinations: `{tokens_500, tokens_1000} × {dense, hybrid} × {no_rerank, rerank}`), which is a superset that includes both named configs plus six more. This gives you strictly more analytical options (you can isolate the pure effect of chunk size, or of rerank, independent of the other factors) — but it also means your results chapter needs to decide whether to report all 8 or collapse back down to the two headline configs the thesis narrative focuses on. Not a bug, just a scope decision to make when you write up results.

### 5. Post-rerank truncation to "Top 5" is not a number the thesis specifies
Algorithm 1's `LIMIT 15` is explicit in the thesis. The number of chunks kept *after* re-ranking (before being handed to the generator) isn't given a specific value anywhere in the thesis text — `ChunkReranker` defaults to keeping the top 5. This is a reasonable, defensible default, but it's an implementation choice you should state and justify in your methodology chapter rather than imply it came from the thesis's own spec.

### 6. Embeddings are now a self-hosted `all-MiniLM-L6-v2` model (384-dim), not OpenAI's `text-embedding-3-small` (1536-dim) — new fidelity gap
The thesis specifies OpenAI's `text-embedding-3-small` at 1536 dimensions (Chapter 3.2.2/3.3). This build now generates embeddings via a self-hosted `sentence-transformers` model, `all-MiniLM-L6-v2` (384 dimensions), served by the same `rerank/` FastAPI sidecar used for cross-encoder reranking (new `/embed` route in `rerank/main.py`). `app/Services/Retrieval/ChunkEmbedder.php` is the new client for this route, used by both `EmbedChunksJob` (indexing) and `DenseRetriever` (query-time embedding, which previously relied on `whereVectorSimilarTo`'s built-in string-to-embedding conversion — that convenience only works with the AI SDK's own providers, so it's now bypassed in favor of embedding the query explicitly up front). The `document_chunks.embedding` column was migrated from `VECTOR(1536)` to `VECTOR(384)` to match.

**Practical effect**: this is not a drop-in swap. The thesis's specific embedding model, its dimensionality, and the semantic quality/behavior of a 1536-dim OpenAI embedding vs. a 384-dim local Sentence-Transformers embedding are all different things. If your methodology chapter states "OpenAI's `text-embedding-3-small`," that's no longer true of this implementation — you'll need to name the actual model (`all-MiniLM-L6-v2`) and dimension (384), and be prepared to discuss how a smaller, locally-run embedding model may affect retrieval quality (context precision/recall) compared to what the thesis assumed. On the plus side, this removes OpenAI entirely from the embedding path — no API key, no per-call cost, fully localized indexing and retrieval, which arguably strengthens the thesis's "localized RAG" framing even as it weakens the "matches thesis's literal model choice" framing.

### 7. Generation and judging now run on Gemini, not OpenAI
`config/ai.php`'s `default` provider changed from `openai` to `gemini`, and both `AnswerGenerator` (`gpt-3.5-turbo` → `gemini-3.5-flash-lite`) and `RagasEvaluator` (`gpt-4o` → `gemini-3.6-flash`) now pass explicit Gemini model names instead of OpenAI ones. `app/Ai/Agents/RagAnswerAgent.php` and the four `app/Ai/Agents/Judges/*` classes are unchanged in structure/instructions — they still run through `laravel/ai`'s `Agent` abstraction, just resolving to the Gemini provider now. The `cohere` (reranking) and `jina` provider configs were also removed from `config/ai.php` since reranking and embeddings no longer route through the AI SDK at all (see #2 and #6). `.env.example` swapped `OPENAI_API_KEY` for `GEMINI_API_KEY` accordingly.

**Practical effect**: any place the thesis names `gpt-3.5-turbo` or `gpt-4o` specifically (Chapter 3.3's generation step, Chapter 3.4's GPT-4o judge) is no longer literally true of this implementation. The *role* each model plays (fast/cheap generator, stronger judge model) is preserved, and the "Information Not Found" instruction wording and RAGAS metric descriptions are untouched — only the underlying provider/model names changed. State the actual models (`gemini-3.5-flash-lite`, `gemini-3.6-flash`) in your methodology chapter rather than the thesis draft's OpenAI names, and be ready to justify why a different judge model doesn't invalidate the RAGAS methodology (it's still an LLM-as-judge approach, just a different LLM).

---

## Things that align exactly (verified against the literal thesis text, not just from memory)

- Dense retrieval SQL semantics (unthresholded top-15 by cosine distance) — required a deliberate fix (see `plan.md` Phase 3) to override Laravel's default similarity threshold, specifically to match the thesis's literal, threshold-free Algorithm 1.
- RRF formula and `k = 60`.
- The re-ranker: a real, self-hosted `cross-encoder/ms-marco-MiniLM-L-6-v2` model — verified against a real inference call, not just against the thesis's wording (see #2 above for how this changed mid-build).
- `to_tsvector`/`ts_rank` for the lexical half of hybrid search.
- pgvector HNSW indexing.
- The "Information Not Found" fallback instruction — near-verbatim from the thesis's own phrasing (model behind it changed, wording didn't — see #7).
- The role structure of "fast/cheap model for generation, stronger model for judging" — preserved even though the specific models (`gemini-3.5-flash-lite` generation, `gemini-3.6-flash` judging) no longer match the thesis's named OpenAI models (see #7).
- All 4 RAGAS metrics and their target components (Context Precision → retriever/re-ranker, Context Recall → chunking, Faithfulness → generator, Answer Relevance → system alignment) — matches Chapter 3.4's table exactly, including testing the Accuracy Fallacy via intentionally-unanswerable dataset questions.
- The three independent variables (chunking strategy, retrieval algorithm, post-retrieval refinement) and four dependent variables (context precision, context recall, faithfulness, computational latency) from Chapter 3.1 — all present and captured (`Query.latency_ms`/`prompt_tokens`/`completion_tokens` cover "Computational Latency").
