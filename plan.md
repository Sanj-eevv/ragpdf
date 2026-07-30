# RAG-Based PDF Question Answering System — Implementation Plan

Source of truth: `~/Desktop/thesis draft 3.docx` ("RAG Based PDF Question Answering System", Sanjeev Bhandari, Purbanchal University, MIT thesis).

This plan turns the thesis architecture into a working prototype. It is intentionally the **simplest possible implementation that satisfies the thesis's experimental requirements** — no authentication, no multi-tenancy, no roles. One person uses this locally to upload PDFs, ask questions, and run the RAGAS evaluation matrix described in Chapter 3.

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
- **Cross-encoder re-ranking** can't run natively in PHP. Simplest viable approach: a tiny Python **FastAPI sidecar** (`sentence-transformers`, `cross-encoder/ms-marco-MiniLM-L-6-v2`) added as one more `docker-compose` service, called over HTTP with `(query, [chunk_texts])` → relevance scores. This is the smallest possible cross-encoder implementation — one route, one model, no persistence.
- **Chunking strategy and retrieval algorithm are request-time parameters**, not global config — every document is ingested once per chunking strategy (so both 500-token and 1000-token variants exist for the same PDF, enabling direct comparison), and every query picks its retrieval algorithm + rerank on/off at call time.
- **Evaluation dataset lives in a JSON fixture**, not a UI-managed table — it's a fixed research artifact (50 questions / 5 PDFs), not something a user edits through the app.
- **Results reporting** is a simple Artisan command that dumps a CSV/table — not a dashboard with charts. A prototype needs the numbers, not visualization polish.

---

## Phase 0 — Infrastructure

**Goal:** environment can store vectors, extract PDF text, and call OpenAI.

- Swap `db` image in `docker-compose.yaml` from `postgres:18.4-alpine` to `pgvector/pgvector:pg18` (or `ankane/pgvector` equivalent) so the `vector` extension is available.
- Add `poppler-utils` to `php/Dockerfile` (`apk add --no-cache poppler-utils`) — this provides the `pdftotext` binary that `spatie/pdf-to-text` shells out to.
- Add a new `rerank` service to `docker-compose.yaml`: small Python image running FastAPI + `sentence-transformers`, exposing `POST /rerank`.
- Composer additions: `spatie/pdf-to-text`, `openai-php/client` (or raw `Illuminate\Http\Client` calls to OpenAI — prefer raw HTTP client to avoid an extra dependency if the SDK feels heavy; decide during implementation based on ergonomics).
- New `.env` keys: `OPENAI_API_KEY`, `OPENAI_EMBEDDING_MODEL=text-embedding-3-small`, `OPENAI_CHAT_MODEL=gpt-3.5-turbo`, `OPENAI_JUDGE_MODEL=gpt-4o`, `RERANK_SERVICE_URL=http://rerank:8000`.
- `php artisan migrate` should run `CREATE EXTENSION IF NOT EXISTS vector` (in a migration, not manually).
- Verify: `docker compose up`, confirm Postgres has `vector` extension, `pdftotext -v` works inside the `app` container, rerank service responds to a health check.

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
  3. `EmbedChunksJob` — batches chunk content to OpenAI `text-embedding-3-small`, writes back `embedding` vectors. Sets `status: ready` (or `failed` with `error_message` on exception).
- `DocumentController@index`/`show`: list documents with status (for the upload UI to poll/display progress).
- Keep this simple: no retry-with-backoff tuning beyond Laravel's defaults, no per-page progress bars — status enum is enough for a prototype.

---

## Phase 3 — Retrieval algorithms

`app/Services/Retrieval/` :

- **`DenseRetriever`** — implements Algorithm 1 exactly as specified: embed the query (OpenAI), then
  ```sql
  SELECT id, document_id, content,
         (1 - (embedding <=> :query_vector)) AS similarity_score
  FROM document_chunks
  WHERE chunking_strategy = :strategy
    AND (:document_id IS NULL OR document_id = :document_id)
  ORDER BY embedding <=> :query_vector ASC
  LIMIT 15;
  ```
- **`HybridRetriever`** — implements Algorithm 2:
  - Run the dense query above to get a ranked list (Rank_Dense).
  - Run a lexical query: `ts_rank(content_tsv, plainto_tsquery('english', :question))` ordered descending, same `chunking_strategy`/`document_id` filters (Rank_Sparse).
  - Fuse: for every chunk appearing in either list, `RRF_Score = 1/(k + Rank_Dense) + 1/(k + Rank_Sparse)` with `k = 60` (missing rank in one list ⇒ treat as absent, only the present term contributes). Sort descending, take Top-K.
- Both retrievers return a common DTO: array of `{chunk_id, content, document_id, score}`.
- `RetrievalService` (facade over both) picks the retriever based on a `retrieval_algorithm` parameter — this is the "independent variable" switch used both by the chat UI and the eval harness.

---

## Phase 4 — Cross-encoder re-ranking

- Python sidecar (`rerank/` new top-level dir, mirroring `nginx/`/`php/`): FastAPI app, one endpoint `POST /rerank { query: string, candidates: [{id, text}] } → [{id, score}]`, using `cross-encoder/ms-marco-MiniLM-L-6-v2` from `sentence-transformers`. Dockerfile installs `fastapi`, `uvicorn`, `sentence-transformers`, `torch` (CPU).
- `app/Services/Retrieval/CrossEncoderReranker.php` — Laravel HTTP client call to the sidecar, takes the Top-K from dense/hybrid retrieval, re-sorts by returned score, truncates to a smaller final K (e.g. top 5) before handing to the generator.
- This step is toggleable (`reranked: bool`) so the eval harness can compare "with/without re-ranking" as the thesis's post-retrieval-refinement variable.

---

## Phase 5 — Answer generation

- `app/Services/AnswerGenerator.php`: builds the final prompt — system instruction enforcing "answer only from the provided context; if the answer isn't in the context, respond exactly 'Information Not Found'" — injects the (re-ranked) context chunks, calls OpenAI `gpt-3.5-turbo` chat completion.
- Captures `prompt_tokens`, `completion_tokens`, and wall-clock latency; returns them alongside the answer so the calling controller/command can persist a `Query` row.
- `QueryController@store` (chat endpoint): accepts `question`, `document_id` (nullable), `chunking_strategy`, `retrieval_algorithm`, `reranked` — runs retrieval → (optional) rerank → generation → persists `Query` → returns the answer + retrieved chunks (for UI transparency) via Inertia/JSON.

---

## Phase 6 — Frontend (Vue 3 + Inertia)

Reuse existing shadcn-style components already in `resources/js/components/ui`. New pages under `resources/js/pages/`:

- **`Documents/Index.vue`** — upload form + table of documents with status badges (pending/extracting/chunking/embedding/ready/failed), polling (Inertia partial reloads) while any document is processing.
- **`Chat/Index.vue`** — single-page chat interface:
  - Document selector (or "all documents").
  - Experiment controls: chunking strategy toggle (500/1000), retrieval algorithm toggle (dense/hybrid), rerank on/off checkbox — exposed directly in the UI since they're the thesis's independent variables and the whole point is to compare them interactively.
  - Message list (question/answer), with an expandable "show retrieved context" panel per answer (chunk text + score) for transparency/debugging.
  - Latency + token count shown per answer (small, unobtrusive — matches the "Computational Latency" dependent variable).
- Routes: plain `web.php` entries (`documents.index`, `documents.store`, `chat.index`, `queries.store`) — no middleware groups beyond what's already global, no auth middleware at all.
- Run `npm run build` (or confirm `composer run dev` is active) after adding pages — new Inertia pages need a build/dev-server pass to show up, per project conventions.

---

## Phase 7 — RAGAS evaluation framework

`app/Services/Evaluation/RagasEvaluator.php` — one method per metric, each a GPT-4o prompt (`OPENAI_JUDGE_MODEL`) following the thesis's methodology table:

- `contextPrecision(question, retrievedChunks)` — judge scores whether retrieved chunks contain necessary evidence, penalizing relevant evidence ranked low.
- `contextRecall(retrievedChunks, groundTruthAnswer)` — judge computes the proportion of ground-truth facts present in retrieved text.
- `faithfulness(generatedAnswer, retrievedChunks)` — judge extracts claims from the answer, cross-checks each against context, flags unsupported claims.
- `answerRelevance(question, generatedAnswer)` — judge checks the answer actually addresses the question (not just factually correct but tangential).

Each method returns a float score (and stores the raw judge response for auditability) — persisted to `ragas_evaluations`, one row per `Query`.

**Evaluation dataset**: `database/fixtures/ragas_dataset.json` (or `storage/app/eval/`) — 50 questions, each tagged with `document_filename`, `ground_truth_answer` (nullable for intentionally unanswerable questions), `answerable: bool`. This is a static research artifact the user (thesis author) curates by hand from the 5 chosen PDFs — not something built through the UI.

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

- **Ingestion**: feature test uploading a small fixture PDF, asserting jobs run (`Queue::fake()`/`Bus::fake()` for dispatch assertions, then a separate test running the jobs synchronously against a tiny sample PDF to assert chunk counts/overlap behavior for both strategies).
- **Retrieval**: unit tests seeding `document_chunks` with known embeddings/content, asserting `DenseRetriever` orders by cosine distance correctly, `HybridRetriever` RRF math matches hand-computed expected scores.
- **Generation**: `Http::fake()` OpenAI responses, assert prompt construction (context injection, "Information Not Found" instruction present) and token/latency capture.
- **Reranker**: `Http::fake()` the sidecar call, assert re-sort + truncation logic.
- **Evaluation**: `Http::fake()` GPT-4o judge responses, assert each RAGAS metric method parses scores correctly and persists.
- Run via `php artisan test --compact --filter=<Name>` per the project's test-enforcement rule — every phase above ships with its tests before moving to the next.

---

## Phase 10 — Wrap-up

- Update `.env.example` with all new keys (Phase 0).
- `vendor/bin/pint --dirty --format agent` after any PHP changes.
- Confirm `docker-compose.yaml` boots cleanly end-to-end (`web`, `app`, `node`, `db` with pgvector, `cache`, new `rerank` service).
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
