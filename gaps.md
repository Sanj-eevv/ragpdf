# Known Gaps / Follow-ups

Running list of things found during the build that work as designed but are intentionally left unfinished, need a decision, or need the user to do something outside of what an agent can do (add API keys, run a command, make a cost/scope call). Not blocking — the app functions without these — but worth revisiting.

---

## Infrastructure

### 1. No queue worker runs in Docker
The `app` container in `docker-compose.yaml` runs plain `php-fpm`. `QUEUE_CONNECTION=database`, and nothing dispatches a worker — so `ExtractDocumentTextJob` → `ChunkDocumentJob` → `EmbedChunksJob` will sit at `pending` forever inside Docker until a worker actually runs.

**Fix options:**
- Add a dedicated `queue` service to `docker-compose.yaml` running `php artisan queue:work`.
- Or just run `docker exec rag-app-1 php artisan queue:work` manually whenever testing ingestion.

(Not an issue when running locally via `composer run dev` — Laravel's `dev` orchestrator starts a queue listener automatically.)

### 2. `OPENAI_API_KEY` / `JINA_API_KEY` are blank
Nothing in the app will actually call a real embedding/generation/rerank/judge endpoint until these are filled in `.env`. Everything so far has been verified with `laravel/ai`'s fakes (`Embeddings::fake()`, `Reranking::fake()`, agent `::fake()`) — real end-to-end behavior (real PDF → real embeddings → real answer) hasn't been exercised yet since the user is adding these keys themselves.

### 3. `rag_testing` database was created by hand
`phpunit.xml` points tests at a real Postgres database (`rag_testing`, pgvector enabled) instead of SQLite, since SQLite can't represent vector columns. That database was created manually via `docker exec ... psql -c "CREATE DATABASE rag_testing"` — it's **not** created by any migration, seeder, or docker-compose init script. If the `postgres_data` volume is ever wiped (`docker compose down -v`, fresh volume, new machine), tests will fail until someone recreates it:
```
docker exec rag-db-1 psql -U root -d rag -c "CREATE DATABASE rag_testing;"
docker exec rag-db-1 psql -U root -d rag_testing -c "CREATE EXTENSION IF NOT EXISTS vector;"
```
Worth turning into a documented setup step (or a docker-compose init script) at some point.

---

## Frontend

### 4. Pre-existing `vue-tsc` errors (not introduced by any of this work)
`npm run types:check` reports ~119 errors, all in the *original* starter-kit files (e.g. every file under `resources/js/components/ui/sidebar/*.vue` failing to import `HTMLAttributes` from `vue`). Confirmed these files were untouched by any phase of this build — it's a `vue`/`@vue/*` package version-resolution issue baked into the starter kit itself, surfaced now because `node_modules` wasn't installed until this session. Doesn't block `npm run build` (Vite doesn't type-check). Left alone since fixing it likely means changing dependency versions, which needs approval first.

### 5. `page_count` is never populated
The `documents.page_count` column exists and is nullable, but nothing sets it — `ExtractDocumentTextJob` only calls `pdftotext`, not `pdfinfo` (which would give the actual page count). Cosmetic gap only; shows as `—` in the Documents table.

---

## Design decisions worth revisiting (not bugs, but fidelity trade-offs)

### 6. Token counting is a `chars/4` approximation
`app/Services/TextSplitter.php` estimates token counts as `strlen/4` (a common GPT rule of thumb) rather than using a real BPE tokenizer, to avoid adding a new dependency. This affects how precisely the "500-token" vs "1000-token" chunking strategies match their literal thesis definitions — chunk boundaries are approximately, not exactly, 500/1000 tokens. If exact reproducibility matters for the thesis write-up, consider a real tokenizer package (e.g. a PHP tiktoken port) instead.

### 7. No queue dashboard / Horizon
Intentional simplification (see `plan.md`'s "Simplifications for the prototype" section) — plain `php artisan queue:work`, no Horizon UI. Fine for a single-user prototype; would need revisiting for anything beyond that.

---

## Research artifacts only the thesis author can produce

### 8. The RAGAS evaluation dataset is a placeholder
`database/fixtures/ragas_dataset.json` has the correct schema (`document_filename`, `question`, `ground_truth_answer` nullable, `answerable: bool`) and 2 example rows showing the format, but the thesis calls for **50 curated questions across 5 real structurally-complex PDFs**, including intentionally unanswerable ones to test the Accuracy Fallacy. This requires the actual chosen PDFs and manually-verified correct answers — not something that can be fabricated. Needs the user to:
1. Pick the 5 PDFs.
2. Upload them through the Documents page.
3. Write ~10 questions per document (mix of answerable/unanswerable) with verified ground-truth answers.
4. Replace the placeholder rows in `ragas_dataset.json` with the real 50.

This blocks Phase 8's experiment runner from producing any real numbers — it can run against the placeholder dataset for a smoke test, but the actual thesis results depend on this dataset being real.
