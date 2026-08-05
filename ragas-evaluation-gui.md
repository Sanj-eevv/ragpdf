# RAGAS Evaluation GUI

A background-job-driven GUI for triggering and reviewing the full RAGAS experiment matrix from the browser. The original `rag:evaluate` Artisan command has been removed — this GUI is now the only way to run the matrix.

## Backend

- **`ragas_evaluation_runs` table** + `RagasEvaluationRun` model + `EvaluationRunStatus` enum (`queued` / `running` / `completed` / `failed` / `cancelled`).
- **`App\Services\Evaluation\RagasExperimentRunner`**: dataset loading, the 8-config matrix definition, result aggregation, and CSV export — shared helpers used by the controller and the per-unit job.
- **One job per question×config unit** (`RunSingleRagasEvaluationJob`), dispatched as a `Bus::batch()` with `allowFailures()` — not one job for the whole matrix, so a 50-question×8-config run can't blow Horizon's 60s job timeout, units run in parallel across workers, and one failing unit doesn't take the rest of the run down with it. The batch's `finally()` callback re-gathers every unit's `Query`+`RagasEvaluation` rows (tagged via a `ragas_evaluation_run_id` column on `queries`) once everything's finished, and computes the summary/CSV.
- **`RagasEvaluationController`**:
  - `index` — latest run + dataset questions
  - `store` — blocks a second concurrent run, else dispatches the batch
  - `cancel` — calls the real `Batch::cancel()`; already-in-flight units finish naturally, unstarted ones no-op
  - `download` — streams the CSV
- Dataset path is `config('services.ragas.dataset_path')` — defaults to the real dataset, overridable in tests only.

## Frontend

`resources/js/pages/Evaluation/Index.vue`, linked from the sidebar:

- "Run Evaluation" button, progress bar while active (same `usePoll` pattern as `Documents/Index.vue`).
- On completion: a grouped SVG bar chart (4 RAGAS metrics × 8 configs, using the project's validated dataviz palette, dark-mode aware) + the full numeric summary table + CSV download link.
- Handles the failed and empty-result states explicitly.

## Verification

- 65 Pest tests pass, Pint clean, ESLint clean.
- **Verified for real, not just tests**: rebuilt frontend assets, dispatched an actual job through the live Docker stack's Horizon worker (`rag-horizon-1`), confirmed it progressed `queued → running → completed` and wrote the right state.
- Real bug found and fixed this way: Horizon keeps the app booted in memory, so it didn't pick up the new `config/services.php` key until restarted — worth remembering when editing config while Horizon is running.
- **Not done**: clicking through it in an actual browser — no browser-automation access in this session, only HTTP/log inspection. The chart geometry and rotated axis labels in particular are worth a real look before trusting them.

## Known gap

The placeholder `ragas_dataset.json` won't match the two actually-uploaded PDFs (`31742.pdf`, `Evaluating_Chunking_Strategies...pdf`), so a run today will complete with zero results — the same real-50-question-dataset gap as before, now just visible through the GUI too.
