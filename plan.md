# RAG-Based PDF Question Answering System — Implementation Plan

Source of truth: `~/Desktop/thesis draft 3.docx` ("RAG Based PDF Question Answering System", Sanjeev Bhandari, Purbanchal University, MIT thesis).

This plan turns the thesis architecture into a working prototype. It is intentionally the **simplest possible implementation that satisfies the thesis's experimental requirements** — no authentication, no multi-tenancy, no roles. One person uses this locally to upload PDFs, ask questions, and run the RAGAS evaluation matrix described in Chapter 3.

**Architecture note (post-Phase-0 revision):** Laravel 13 ships an official first-party AI SDK (`laravel/ai`, namespace `Laravel\Ai`) plus core query-builder methods (`whereVectorSimilarTo`, `whereFullText`) that cover most of what this plan originally proposed hand-rolling. Phases 2–7 below use these instead of raw OpenAI HTTP calls and a custom Python cross-encoder sidecar — see each phase for specifics. `composer require laravel/ai` is already done; `config/ai.php` is published.

---

## 0. What the thesis requires (recap, so nothing gets lost)

- **Stack** (already scaffolded): Laravel 13 + Inertia + Vue 3 + PostgreSQL + pgvector. Docker-based.
- **Ingestion**: PDF → `pdftotext` (via `spatie/pdf-to-text`) → plain text → Recursive Character Text Splitter → OpenAI `text-embedding-3-small` (1536-dim) → stored in `document_chunks.embedding` (pgvector `VECTOR(1536)`).
- **Independent variables (must be configurable per request/experiment run)**:
  1. **Chunking strategy**: 500-token chunks vs 1000-token chunks, ~10% overlap (≈50 tokens for the 500 config, ≈100 for the 1000 config).
  2. **Retrieval algorithm**:
     - Algorithm 1 — Pure Dense Vector Retrieval: cosine distance via pgvector `<=>` operator, `ORDER BY embedding <=> query LIMIT 15`.
     - Algorithm 2 — Hybrid Search with Reciprocal Rank Fusion: dense search (embeddings) run alongside PostgreSQL full-text search (`to_tsvector`/`ts_rank`), fused via `RRF_Score = 1/(k + Rank_Dense) + 1/(k + Rank_Sparse)`, `k ≈ 60`.
  3. **Post-retrieval refinement**: Cross-Encoder re-ranker applied to Top-K to cut noise before generation.
- **Generation**: `gpt-3.5-turbo`, prompted to answer strictly from provided context, and to say **"Information Not Found"** when the context doesn't support an answer (this is how the "Accuracy Fallacy" gets tested).
- **Evaluation**: RAGAS framework, LLM-as-judge using GPT-4o, four metrics:
  - Context Precision (retriever/re-ranker quality — signal vs noise)
  - Context Recall (chunking quality — did we retrieve all ground-truth facts)
  - Faithfulness (generator quality — hallucination detection)
  - Answer Relevance (does the answer address the actual question)
  - Plus latency and token counts as practical metrics.
- **Experiment**: 50 curated questions (mix of answerable and *intentionally unanswerable*) across 5 structurally complex PDFs, run through baseline (Naive: dense + 500-token) vs advanced (Hybrid + Cross-Encoder + configurable chunk size) configurations.
- **Explicitly out of scope**: OCR/vision models, multilingual support, large web-scale corpora, auth of any kind.

---

## 1. Simplifications for the prototype (decisions, so we don't relitigate mid-build)

- **No auth, no users table dependency.** Documents and queries are global — anyone with access to the app sees everything. (Auth scaffolding was already stripped per the last two commits — stay that way.)
- **No login-gated queue dashboard.** Use Laravel's `database` queue driver (already configured) with a plain `php artisan queue:work`. No Horizon — the thesis mentions Horizon as a *justification* for using Laravel, but Horizon itself isn't required for a single-user prototype; the plain queue worker demonstrates the same async-ingestion property.
- **Cross-encoder re-ranking** uses the official `Laravel\Ai\Reranking` class backed by **Jina**'s rerank API (`default_for_reranking => 'jina'` in `config/ai.php`) rather than a self-hosted Python model — practically free at this project's scale (10M free tokens) and needs no extra container. (An earlier version of this plan built a Python FastAPI + sentence-transformers sidecar for this; it was removed once the official SDK's `Reranking` class was found to cover the same need with less infrastructure.)
- **Chunking strategy and retrieval algorithm are request-time parameters**, not global config — every document is ingested once per chunking strategy (so both 500-token and 1000-token variants exist for the same PDF, enabling direct comparison), and every query picks its retrieval algorithm + rerank on/off at call time.
- **Evaluation dataset lives in a JSON fixture**, not a UI-managed table — it's a fixed research artifact (50 questions / 5 PDFs), not something a user edits through the app.
- **Results reporting** is a simple Artisan command that dumps a CSV/table — not a dashboard with charts. A prototype needs the numbers, not visualization polish.

---

## Phase 0 — Infrastructure ✅ done

**Goal:** environment can store vectors, extract PDF text, and call AI providers.

- Swapped `db` image in `docker-compose.yaml` from `postgres:18.4-alpine` to `pgvector/pgvector:pg18` so the `vector` extension is available. (Also fixed a pre-existing bug: the port mapping was `5432:6432`, which pointed the host port at a container port nothing listened on.)
- Added `poppler-utils` to `php/Dockerfile` — provides the `pdftotext` binary that `spatie/pdf-to-text` shells out to.
- Composer: `spatie/pdf-to-text` (PDF extraction) and **`laravel/ai`** (official AI SDK — see architecture note above; supersedes the originally-planned raw OpenAI HTTP client and Python rerank sidecar).
- `config/ai.php` published; `default_for_reranking` set to `jina`. `.env` keys: `OPENAI_API_KEY`, `JINA_API_KEY`.
- New migration `enable_pgvector_extension` runs `CREATE EXTENSION IF NOT EXISTS vector`.
- **Test infra**: `phpunit.xml` switched from in-memory SQLite to a real Postgres database (`rag_testing`, pgvector enabled) — SQLite can't represent vector columns at all, and every phase from here on needs real Postgres-specific schema.
- Verified: stack rebuilt and up, `pdftotext` works in the `app` container, `vector` extension installed, migration runs clean, tests pass against real Postgres.

---

## Phase 1 — Data model

Migrations (all in `database/migrations/`):

- **`documents`**
  - `id`, `title`, `original_filename`, `disk_path`, `page_count` (nullable), `status` (enum: `pending`, `extracting`, `chunking`, `embedding`, `ready`, `failed`), `error_message` (nullable), timestamps.
- **`document_chunks`**
  - `id`, `document_id` (FK → documents), `chunking_strategy` (enum/string: `tokens_500`, `tokens_1000`), `chunk_index` (int, order within document+strategy), `content` (text), `token_count` (int), `embedding` (`vector(1536)`, via pgvector Laravel cast or raw column), timestamps.
  - Generated column or maintained column `content_tsv tsvector` (via `to_tsvector('english', content)`) with a GIN index, for the lexical half of hybrid search.
  - Index: HNSW index on `embedding` (`USING hnsw (embedding vector_cosine_ops)`), plus an index on `(document_id, chunking_strategy)`.
- **`queries`** (experiment/logging table — every question asked through the app, both interactively and via the eval harness, lands here)
  - `id`, `document_id` (nullable FK — null means "search all documents"), `question`, `answer`, `chunking_strategy`, `retrieval_algorithm` (`dense`, `hybrid`), `reranked` (bool), `retrieved_chunk_ids` (jsonb array), `latency_ms`, `prompt_tokens`, `completion_tokens`, timestamps.
- **`ragas_evaluations`**
  - `id`, `query_id` (FK → queries), `context_precision`, `context_recall`, `faithfulness`, `answer_relevance` (all float/nullable — unanswerable questions may not have all four), `judge_model`, `raw_judge_response` (jsonb, for auditability), timestamps.

Eloquent models: `Document`, `DocumentChunk`, `Query`, `RagasEvaluation`, with the obvious relationships (`Document::hasMany(DocumentChunk)`, `Document::hasMany(Query)`, `Query::hasOne(RagasEvaluation)`). Factories for each, for test coverage.

---

## Phase 2 — PDF ingestion pipeline

**Goal:** upload a PDF, get it chunked + embedded under *both* chunking strategies, asynchronously.

- `DocumentController@store`: Inertia form upload (single file), stores the PDF on a local disk, creates a `Document` row (`status: pending`), dispatches `ExtractDocumentTextJob`.
- Job chain (all in `app/Jobs/`), chained via `Bus::chain` so failures surface cleanly on the `Document`:
  1. `ExtractDocumentTextJob` — runs `spatie/pdf-to-text` to get raw text, stores it (e.g., a `raw_text` disk file or column), sets `status: chunking`.
  2. `ChunkDocumentJob` — for **each** chunking strategy (500-token and 1000-token), runs a `RecursiveCharacterTextSplitter` (new `app/Services/TextSplitter.php`): split by paragraph → line → sentence → character, with ~10% overlap, until each chunk is within its token budget (token counting via a simple approximation or `tiktoken`-equivalent — decide a lightweight approach, e.g. `str_word_count`-based estimate or a PHP BPE tokenizer library if one exists cheaply). Persists `DocumentChunk` rows for both strategies. Sets `status: embedding`.
  3. `EmbedChunksJob` — batches chunk content through `Laravel\Ai\Embeddings::for($texts)->generate()` (OpenAI `text-embedding-3-small`, 1536 dims — the AI SDK's OpenAI default, matches the thesis exactly), writes back `embedding` vectors. Sets `status: ready` (or `failed` with `error_message` on exception).
- `DocumentController@index`/`show`: list documents with status (for the upload UI to poll/display progress).
- Keep this simple: no retry-with-backoff tuning beyond Laravel's defaults, no per-page progress bars — status enum is enough for a prototype.

---

## Phase 3 — Retrieval algorithms ✅ done

`app/Services/Retrieval/`:

- **`DenseRetriever`** — implements Algorithm 1 using Laravel's core `whereVectorSimilarTo` (pgvector-backed, ships in `laravel/framework` 13.x — no separate package). Important fidelity fix: the thesis's Algorithm 1 is an *unthresholded* top-K rank (`ORDER BY embedding <=> query LIMIT 15`, no relevance cutoff), but `whereVectorSimilarTo` defaults to `minSimilarity: 0.6` — overridden to `minSimilarity: -1.0` so every candidate is admitted, matching the thesis exactly (including its "irrelevant noise" weakness that Phase 4's re-ranking exists to correct).
- **`ReciprocalRankFusion`** — pure RRF math extracted into its own class (`fuse(array $rankedIdLists, int $limit): array`, operating on plain ID arrays) so it's unit-testable independent of Eloquent/DB — no built-in RRF helper exists in the framework or AI SDK, this part is still hand-rolled.
- **`HybridRetriever`** — implements Algorithm 2: dense ranked list (same query as `DenseRetriever`) + lexical ranked list via `whereFullText('content', $question)` for the match condition (PostgreSQL `to_tsvector`/`plainto_tsquery`) paired with an explicit `orderByRaw("ts_rank(to_tsvector('english', content), plainto_tsquery('english', ?)) DESC", [$question])` for ranking — `whereFullText` filters but does **not** order by relevance on PostgreSQL (only MySQL/MariaDB get that automatically). The two ID-ranked lists are fed into `ReciprocalRankFusion` (`k = 60`), then hydrated back into `DocumentChunk` models.
- Both retrievers return `Illuminate\Database\Eloquent\Collection<int, DocumentChunk>` directly — no separate DTO needed, since every field a consumer needs (content, id, document_id) already lives on the model.
- `RetrievalService` (facade over both) picks the retriever via a `match` on the `RetrievalAlgorithm` enum — this is the "independent variable" switch used both by the chat UI and the eval harness.
- Tested with hand-computed RRF expectations (verified against the implementation, not just against itself) and real Postgres integration tests using `Embeddings::fake()` with a closure returning a fixed vector, so cosine-similarity ordering is deterministic in tests.

---

## Phase 4 — Cross-encoder re-ranking ✅ done

- `app/Services/Retrieval/ChunkReranker.php` — thin wrapper around `Laravel\Ai\Reranking`, backed by Jina's rerank API (`default_for_reranking => 'jina'` in `config/ai.php`, set in Phase 0):
  ```php
  Reranking::of($contentArray)->limit(5)->rerank($question);
  ```
  Each result is a `RankedDocument` with an `index` pointing back into the original input array — the wrapper snapshots the chunk collection to a plain PHP array first (`$chunks->values()->all()`) so it can map `$result->index` straight back to the original `DocumentChunk` model, no content-matching needed.
- Short-circuits to an empty collection without calling the provider when given zero chunks (`Reranking::assertNothingReranked()` verifies this in tests).
- This step is toggleable (a `reranked: bool` the caller decides whether to invoke `ChunkReranker` at all) so the eval harness can compare "with/without re-ranking" as the thesis's post-retrieval-refinement variable.
- Tested via `Reranking::fake(fn ($prompt) => [...])` with explicit `RankedDocument` responses (the default fake shuffles order randomly, so tests supply deterministic responses) + `Reranking::assertReranked(...)` — no HTTP mocking needed.

---

## Phase 5 — Answer generation ✅ done

- `app/Ai/Agents/RagAnswerAgent.php` (via `php artisan make:agent`, stripped down from its `Conversational`/`HasTools` scaffold — this app needs neither: each query is independent, no tool-calling): implements `Laravel\Ai\Contracts\Agent`, uses `Promptable`. `instructions()` returns the system prompt enforcing "answer only from the provided context; if the answer isn't in the context, respond exactly 'Information Not Found'".
- `app/Services/AnswerGenerator.php` builds the actual prompt (numbered context chunks + question) and calls `(new RagAnswerAgent)->prompt($prompt, model: 'gpt-3.5-turbo')` — explicit model, since the AI SDK's OpenAI default is a newer model than the thesis specifies. `AgentResponse->usage` carries prompt/completion tokens, but **not** wall-clock latency — that's measured by hand with `microtime(true)` around the `->prompt()` call. Returns a small `AnswerGenerationResult` readonly DTO (answer, promptTokens, completionTokens, latencyMs).
- `QueryController@store` (chat endpoint, `POST /queries`): accepts `question`, `document_id` (nullable), `chunking_strategy`, `retrieval_algorithm`, `reranked` — runs `RetrievalService` → (optional) `ChunkReranker` → `AnswerGenerator` → persists a `Query` row → returns JSON (answer + retrieved context) rather than an Inertia response, since this is a standalone chat-style call, not a page navigation.
- Tested via `RagAnswerAgent::fake([...])` with explicit `TextResponse(...)` instances (to control token usage) + `assertPrompted(...)`, and a full `QueryControllerTest` exercising the whole retrieval → rerank → generation → persistence chain through the actual HTTP endpoint with `Embeddings::fake()` + `Reranking::fake()` + `RagAnswerAgent::fake()` together.

---

## Phase 6 — Frontend (Vue 3 + Inertia) ✅ done

Reuse existing shadcn-style components already in `resources/js/components/ui`. New pages under `resources/js/pages/`:

- **`Documents/Index.vue`** — upload form (`useForm` + Wayfinder's `store()` action, file input with upload-progress bar) + table of documents with status badges (pending/extracting/chunking/embedding/ready/failed). Polls (`usePoll`, start/stop tied to a `hasProcessingDocuments` watcher rather than polling forever) only while something is actually processing.
- **`Chat/Index.vue`** — single-page chat interface using Inertia v3's `useHttp` hook (standalone JSON requests, no page navigation — matches `QueryController`'s JSON response) rather than `useForm`/router, since this isn't a page visit:
  - Document selector ("All documents" sentinel + per-document options), chunking strategy toggle, retrieval algorithm toggle, rerank checkbox — bound directly onto the `useHttp` reactive object's fields (mirrors `useForm`'s binding pattern).
  - Local message list (question/answer) with an expandable "show retrieved context" panel per answer, latency + token counts shown per answer.
- `ChatController@index` (new) feeds the Chat page a list of `ready`-status documents only.
- Routes: `documents.index`/`documents.store` (existing), `chat.index` (new), `queries.store` (existing) — no auth middleware.
- `AppSidebar.vue` nav updated with Documents/Chat links.

**Bugs found via actual browser verification** (per project convention: start the dev server, drive the feature, don't just typecheck) — screenshotted with Playwright against the already-running Docker stack (`http://localhost`), since `chromium-cli` wasn't available in this environment:
1. `resources/js/app.ts`'s `layout` option was passed as a raw component (`AppLayout`) instead of a resolver function — broke during the Phase 0 Welcome/settings cleanup. Inertia threw `defaultLayout is not a function` and the page was a **blank white screen** despite the server-rendered HTML/JSON payload looking completely correct — a good reminder that curl-level checks aren't enough for SPA pages. Fixed: `layout: () => AppLayout`.
2. Reka UI's `SelectItem` rejects an empty-string `value` (reserved for "clear selection") — the "All documents" option used `value=""`, throwing at runtime. Fixed with an `"all"` sentinel value mapped to `null` in a computed getter/setter.
3. `useHttp`'s actual type signature (checked directly in `node_modules/@inertiajs/vue3/types/useHttp.d.ts`) doesn't match the simplified doc examples: `.data` is a **method**, not an assignable property, and `.post()`/`.get()` take a **plain URL string**, not a Wayfinder route object — form fields are meant to be bound directly onto the returned object (`http.question`, `http.chunking_strategy`, ...), same as `useForm`. Fixed by binding fields directly and calling `store.url()`.
- Full upload flow (select file → submit → redirect → status badge appears) verified end-to-end in the real browser, not just via Pest.
- **Known pre-existing issue, not introduced by this work**: `vue-tsc --noEmit` reports ~119 errors across the *original* starter-kit files (e.g. `resources/js/components/ui/sidebar/*.vue` failing to import `HTMLAttributes` from `vue`) — a `vue`/`@vue/*` package dependency-resolution issue present before any of these six phases touched the frontend (confirmed: these files were untouched by any phase). It doesn't block `npm run build` (Vite doesn't type-check), only `vue-tsc`/`npm run types:check`. Left as-is per "don't change dependencies without approval."
- **Follow-up needed**: no queue worker runs inside the `app` Docker container (it runs plain `php-fpm`, not `php artisan dev`), so uploaded documents will sit at `pending` indefinitely inside Docker until either a `queue` service is added to `docker-compose.yaml` or `php artisan queue:work` is run manually inside the container.

---

## Phase 7 — RAGAS evaluation framework ✅ done

One `Laravel\Ai\Contracts\Agent` per metric under `app/Ai/Agents/Judges/` (stripped of the `make:agent --structured` scaffold's default `Conversational`/`HasTools`, same as `RagAnswerAgent`), each implementing `HasStructuredOutput` via `Illuminate\Contracts\JsonSchema\JsonSchema` so scores come back as typed/validated JSON instead of hand-parsed text — following the thesis's methodology table:

- `ContextPrecisionJudge` — scores whether retrieved chunks contain necessary evidence, penalizing relevant evidence ranked low. Schema: `{score: number(0-1), reasoning: string}`.
- `ContextRecallJudge` — computes the proportion of ground-truth facts present in retrieved text. Schema: `{score: number(0-1), missing_facts: string[]}`.
- `FaithfulnessJudge` — extracts claims from the answer, cross-checks each against context, flags unsupported claims. Schema: `{score: number(0-1), unsupported_claims: string[]}`.
- `AnswerRelevanceJudge` — checks the answer actually addresses the question (not just factually correct but tangential). Schema: `{score: number(0-1), reasoning: string}`.

All four use explicit `model: 'gpt-4o'` per the thesis. `app/Services/Evaluation/RagasEvaluator.php` orchestrates the four judges and persists results (plus the full structured response for auditability) to `ragas_evaluations`, one row per `Query`. `context_recall` (and its raw response) is left `null` and the judge is never even called when no ground-truth answer is supplied — matches the column being nullable and the thesis's note that unanswerable questions may not have all four metrics.

A subtlety worth noting for future callers: `Promptable::prompt()` is statically typed to return the base `AgentResponse`, but a `HasStructuredOutput` agent always returns a `StructuredAgentResponse` (implements `ArrayAccess`/`toArray()`) at runtime — `RagasEvaluator` narrows this with an explicit `instanceof` check (throwing if it's ever not structured) rather than casting, since PHPStan can't see the polymorphism through the trait's fixed return type.

**Evaluation dataset**: `database/fixtures/ragas_dataset.json` — schema in place (`document_filename`, `question`, `ground_truth_answer` nullable, `answerable: bool`) with 2 placeholder rows demonstrating the format. The real 50-question/5-PDF dataset is a research artifact only the thesis author can curate (needs real PDFs + verified answers) — tracked in `gaps.md`.

---

## Phase 8 — Experiment runner

`php artisan rag:evaluate` (new Artisan command, `app/Console/Commands/RunRagasEvaluation.php`):

- Loads the fixture dataset.
- Iterates the experiment matrix: `{chunking: [500, 1000]} × {retrieval: [dense, hybrid]} × {rerank: [on, off]}` — 8 configurations (naive baseline = 500/dense/no-rerank; advanced = 1000-or-500/hybrid/rerank, whichever the data favors).
- For each question × config: runs retrieval → rerank (if enabled) → generation → RAGAS scoring, persists `Query` + `RagasEvaluation`.
- On completion, prints an aggregate table (mean context precision/recall/faithfulness/answer relevance/latency/tokens **per configuration**) to the console, and optionally writes a CSV to `storage/app/eval/results_{timestamp}.csv` for pulling into the thesis's results chapter.
- Expect this to be slow/API-cost-bound (50 questions × 8 configs × several judge calls each) — support a `--configs=` filter and `--limit=` flag so partial runs are possible during development instead of always running the full matrix.

---

## Phase 9 — Testing (Pest)

- **Ingestion**: feature test uploading a small fixture PDF, asserting jobs run (`Queue::fake()`/`Bus::fake()` for dispatch assertions, then a separate test running the jobs synchronously against a tiny sample PDF to assert chunk counts/overlap behavior for both strategies). `Embeddings::fake()` for the embedding step.
- **Retrieval**: unit tests seeding `document_chunks` with known embeddings/content, asserting `DenseRetriever` orders by cosine distance correctly, `HybridRetriever` RRF math matches hand-computed expected scores.
- **Generation**: `RagAnswerAgent::fake([...])` + `assertPrompted(...)`, assert prompt construction (context injection, "Information Not Found" instruction present) and token/latency capture off the response.
- **Reranker**: `Reranking::fake()` + `assertReranked(...)`, assert re-sort + truncation logic.
- **Evaluation**: fake each judge agent (`ContextPrecisionJudge::fake([...])` etc.), assert structured scores parse correctly and persist.
- Run via `php artisan test --compact --filter=<Name>` per the project's test-enforcement rule — every phase above ships with its tests before moving to the next.

---

## Phase 10 — Wrap-up

- Update `.env.example` with all new keys (Phase 0).
- `vendor/bin/pint --dirty --format agent` after any PHP changes.
- Confirm `docker-compose.yaml` boots cleanly end-to-end (`web`, `app`, `node`, `db` with pgvector, `cache`).
- No README/documentation files beyond this plan unless separately requested.

---

## Suggested build order (phase dependencies)

```
Phase 0 (infra) → Phase 1 (schema) → Phase 2 (ingestion)
                                         │
                        ┌────────────────┴────────────────┐
                        ▼                                  ▼
                Phase 3 (retrieval)                Phase 6 (frontend shell,
                        │                            upload UI only)
                        ▼
                Phase 4 (rerank) → Phase 5 (generation) → Phase 6 (chat UI)
                                                              │
                                                              ▼
                                                    Phase 7 (RAGAS) → Phase 8 (experiment runner)
                                                              │
                                                              ▼
                                                        Phase 9 (tests, throughout)
                                                              │
                                                              ▼
                                                        Phase 10 (wrap-up)
```

Each phase should end with passing Pest tests for what it added before moving on.
