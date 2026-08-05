<script setup lang="ts">
import { Head, router, usePage, usePoll } from '@inertiajs/vue3';
import {
    BarElement,
    CategoryScale,
    Chart as ChartJS,
    Legend,
    LinearScale,
    Tooltip,
} from 'chart.js';
import { computed, onMounted, ref, watch } from 'vue';
import { Bar } from 'vue-chartjs';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { useAppearance } from '@/composables/useAppearance';
import { cancel, download, store } from '@/routes/evaluation';

// Chart.js/canvas rendering isn't meaningful during SSR (there's no real
// canvas in Node) — guard registration to the browser and defer <Bar> itself
// past hydration so it only ever runs client-side.
const isBrowser = typeof window !== 'undefined';

if (isBrowser) {
    ChartJS.register(CategoryScale, LinearScale, BarElement, Legend, Tooltip);
}

const isMounted = ref(false);
onMounted(() => {
    isMounted.value = true;
});

type RunStatus = 'queued' | 'running' | 'completed' | 'failed' | 'cancelled';

type ConfigSummary = {
    config_id: string;
    n: number;
    context_precision: number | null;
    context_recall: number | null;
    faithfulness: number | null;
    answer_relevance: number | null;
    avg_latency_ms: number | null;
    avg_tokens: number | null;
};

type EvaluationRun = {
    id: number;
    status: RunStatus;
    total: number;
    completed: number;
    summary: ConfigSummary[] | null;
    csv_path: string | null;
    error_message: string | null;
};

type DatasetQuestion = {
    question: string;
    ground_truth_answer: string | null;
    answerable: boolean;
};

type QuestionDetailConfig = {
    config_id: string;
    answer: string | null;
    context_precision: number | null;
    context_recall: number | null;
    faithfulness: number | null;
    answer_relevance: number | null;
    latency_ms: number | null;
    prompt_tokens: number | null;
    completion_tokens: number | null;
};

type QuestionDetail = {
    question: string;
    ground_truth_answer: string | null;
    configs: QuestionDetailConfig[];
};

const props = defineProps<{
    run: EvaluationRun | null;
    questions: DatasetQuestion[];
    details: QuestionDetail[];
}>();

const showQuestions = ref(false);

const selectedQuestion = ref<string | null>(null);

watch(
    () => props.details,
    (details: QuestionDetail[]) => {
        if (details.length === 0) {
            selectedQuestion.value = null;

            return;
        }

        if (!selectedQuestion.value || !details.some((detail: QuestionDetail) => detail.question === selectedQuestion.value)) {
            selectedQuestion.value = details[0].question;
        }
    },
    { immediate: true },
);

const selectedDetail = computed<QuestionDetail | null>(
    () => props.details.find((detail: QuestionDetail) => detail.question === selectedQuestion.value) ?? null,
);

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Evaluation', href: '/evaluation' },
        ],
    },
});

const page = usePage();
const evaluationError = computed(
    () => (page.props as { errors?: { evaluation?: string } }).errors?.evaluation,
);

const isActive = computed(
    () => props.run !== null && (props.run.status === 'queued' || props.run.status === 'running'),
);

const { start: startPolling, stop: stopPolling } = usePoll(
    3000,
    { only: ['run'] },
    { autoStart: false },
);

watch(isActive, (active) => (active ? startPolling() : stopPolling()), { immediate: true });

function runEvaluation() {
    router.post(store().url, {}, { preserveScroll: true });
}

function cancelEvaluation() {
    if (!props.run) {
        return;
    }

    router.post(cancel(props.run.id).url, {}, { preserveScroll: true });
}

const statusVariant: Record<RunStatus, 'default' | 'secondary' | 'destructive' | 'outline'> = {
    queued: 'outline',
    running: 'secondary',
    completed: 'default',
    failed: 'destructive',
    cancelled: 'outline',
};

const METRICS = [
    { key: 'context_precision', label: 'Context Precision' },
    { key: 'context_recall', label: 'Context Recall' },
    { key: 'faithfulness', label: 'Faithfulness' },
    { key: 'answer_relevance', label: 'Answer Relevance' },
] as const;

// The same validated categorical palette as before (blue/green/magenta/
// yellow, CVD-safe adjacent order) — Chart.js renders to canvas, so colors
// have to be resolved to literal hex here rather than left as CSS custom
// properties the way the old hand-drawn SVG used them.
const SERIES_COLORS = {
    light: ['#2a78d6', '#008300', '#e87ba4', '#eda100'],
    dark: ['#3987e5', '#008300', '#d55181', '#c98500'],
} as const;

const { resolvedAppearance } = useAppearance();
const seriesColors = computed(() => SERIES_COLORS[resolvedAppearance.value as 'light' | 'dark']);

// The raw config_id (e.g. "tokens_500_dense_no_rerank") is precise and used
// as-is elsewhere (table cells, keys) — but reads poorly as a chart axis
// label, so only the label shown on the chart gets the underscores swapped
// for spaces.
function formatConfigLabel(configId: string): string {
    return configId.replace(/_/g, ' ');
}

const chartData = computed(() => {
    const configs = selectedDetail.value?.configs ?? [];

    return {
        labels: configs.map((config: QuestionDetailConfig) => formatConfigLabel(config.config_id)),
        datasets: METRICS.map((metric, index) => ({
            label: metric.label,
            backgroundColor: seriesColors.value[index],
            data: configs.map((config: QuestionDetailConfig) => config[metric.key]),
        })),
    };
});

const chartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    scales: {
        y: {
            beginAtZero: true,
            max: 1,
        },
    },
    plugins: {
        legend: {
            position: 'bottom' as const,
        },
    },
};

const BASELINE_CONFIG_ID = 'tokens_500_dense_no_rerank';

const COMPARISON_ROWS = [
    { key: 'context_precision', label: 'Context Precision', kind: 'score' },
    { key: 'context_recall', label: 'Context Recall', kind: 'score' },
    { key: 'faithfulness', label: 'Faithfulness', kind: 'score' },
    { key: 'answer_relevance', label: 'Answer Relevance', kind: 'score' },
    { key: 'avg_latency_ms', label: 'Avg Latency (ms)', kind: 'number' },
    { key: 'avg_tokens', label: 'Avg Tokens', kind: 'number' },
] as const;

function meanScore(config: ConfigSummary): number | null {
    const values = METRICS.map((metric) => config[metric.key]).filter(
        (value): value is number => value != null,
    );

    return values.length === 0 ? null : values.reduce((a, b) => a + b, 0) / values.length;
}

const comparison = computed(() => {
    const summary = props.run?.summary;

    if (!summary || summary.length < 2) {
        return null;
    }

    const baseline = summary.find((config: ConfigSummary) => config.config_id === BASELINE_CONFIG_ID);

    if (!baseline) {
        return null;
    }

    let best: { config: ConfigSummary; score: number } | null = null;

    for (const config of summary) {
        if (config.config_id === BASELINE_CONFIG_ID) {
            continue;
        }

        const score = meanScore(config);

        if (score !== null && (!best || score > best.score)) {
            best = { config, score };
        }
    }

    return best ? { baseline, best: best.config } : null;
});

function delta(baselineValue: number | null | undefined, bestValue: number | null | undefined): string {
    if (baselineValue == null || bestValue == null) {
        return '—';
    }

    const diff = bestValue - baselineValue;
    const sign = diff > 0 ? '+' : diff < 0 ? '−' : '±';

    return `${sign}${Math.abs(diff).toFixed(3)}`;
}

function numberDelta(baselineValue: number | null | undefined, bestValue: number | null | undefined): string {
    if (baselineValue == null || bestValue == null) {
        return '—';
    }

    const diff = Math.round(bestValue - baselineValue);
    const sign = diff > 0 ? '+' : diff < 0 ? '−' : '±';

    return `${sign}${Math.abs(diff)}`;
}

function deltaClass(baselineValue: number | null | undefined, bestValue: number | null | undefined): string {
    if (baselineValue == null || bestValue == null || bestValue === baselineValue) {
        return 'text-muted-foreground';
    }

    return bestValue > baselineValue
        ? 'text-green-600 dark:text-green-400'
        : 'text-red-600 dark:text-red-400';
}

// Loose null check deliberately catches both `null` and `undefined` — a
// summary row from a malformed/older run can be missing a metric key
// entirely, and an uncaught exception here crashes the SSR renderer.
function formatScore(value: number | null | undefined): string {
    return value == null ? '—' : value.toFixed(3);
}

function formatNumber(value: number | null | undefined): string {
    return value == null ? '—' : String(Math.round(value));
}
</script>

<template>
    <Head title="Evaluation" />

    <div class="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
        <Card>
            <CardHeader>
                <CardTitle>RAGAS Evaluation</CardTitle>
                <CardDescription>
                    Runs every question in the curated dataset through all 8 chunking
                    &times; retrieval &times; rerank configurations, scored by the RAGAS
                    judge. This runs in the background — you can navigate away and come
                    back.
                </CardDescription>
            </CardHeader>
            <CardContent class="flex flex-col gap-3">
                <Alert v-if="evaluationError" variant="destructive">
                    <AlertDescription>{{ evaluationError }}</AlertDescription>
                </Alert>

                <div class="flex items-center gap-3">
                    <Button :disabled="isActive" @click="runEvaluation">
                        {{ isActive ? 'Running…' : 'Run Evaluation' }}
                    </Button>
                    <Button v-if="isActive" variant="destructive" @click="cancelEvaluation">
                        Stop
                    </Button>
                    <Badge v-if="run" :variant="statusVariant[run.status]">{{ run.status }}</Badge>
                    <button
                        type="button"
                        class="text-sm text-muted-foreground underline"
                        @click="showQuestions = !showQuestions"
                    >
                        {{ showQuestions ? 'Hide' : 'Show' }} dataset questions ({{ questions.length }})
                    </button>
                </div>

                <div v-if="showQuestions" class="overflow-x-auto rounded-md border">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b text-left text-muted-foreground">
                                <th class="py-2 px-3 font-medium">Question</th>
                                <th class="py-2 px-3 font-medium">Answerable</th>
                                <th class="py-2 px-3 font-medium">Ground truth answer</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="(item, index) in questions"
                                :key="index"
                                class="border-b last:border-0 align-top"
                            >
                                <td class="py-2 px-3">{{ item.question }}</td>
                                <td class="py-2 px-3">
                                    <Badge :variant="item.answerable ? 'default' : 'outline'">
                                        {{ item.answerable ? 'yes' : 'no' }}
                                    </Badge>
                                </td>
                                <td class="py-2 px-3 text-muted-foreground">{{ item.ground_truth_answer ?? '—' }}</td>
                            </tr>
                            <tr v-if="questions.length === 0">
                                <td colspan="3" class="py-3 px-3 text-center text-muted-foreground">
                                    No dataset loaded — check `services.ragas.dataset_path`.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="run && isActive" class="flex flex-col gap-1">
                    <progress
                        :value="run.completed"
                        :max="run.total"
                        class="w-full max-w-md"
                    ></progress>
                    <p class="text-sm text-muted-foreground">
                        {{ run.completed }} / {{ run.total }} question-configs evaluated
                    </p>
                </div>

                <Alert v-if="run && run.status === 'failed'" variant="destructive">
                    <AlertTitle>Evaluation run failed</AlertTitle>
                    <AlertDescription>{{ run.error_message }}</AlertDescription>
                </Alert>

                <p
                    v-if="run && run.status === 'completed' && run.error_message"
                    class="text-sm text-muted-foreground"
                >
                    {{ run.error_message }}
                </p>

                <p v-if="run && run.status === 'cancelled'" class="text-sm text-muted-foreground">
                    Evaluation stopped at {{ run.completed }} / {{ run.total }} question-configs.
                    Start a new run whenever you're ready.
                </p>

                <p v-if="!run" class="text-sm text-muted-foreground">
                    No evaluation has been run yet.
                </p>
                <p
                    v-if="run && run.status === 'completed' && (!run.summary || run.summary.length === 0)"
                    class="text-sm text-muted-foreground"
                >
                    Completed, but nothing was evaluated — none of the dataset's
                    documents matched an uploaded, ready document.
                </p>
            </CardContent>
        </Card>

        <template v-if="run && run.status === 'completed' && run.summary && run.summary.length > 0">
            <Card v-if="selectedDetail">
                <CardHeader>
                    <CardTitle>RAGAS scores by question</CardTitle>
                    <CardDescription>
                        Compare all 8 configurations for one question at a time — an
                        average across the whole dataset hides exactly this kind of
                        detail (e.g. did it correctly say "Information Not Found"?).
                    </CardDescription>
                </CardHeader>
                <CardContent class="flex flex-col gap-4">
                    <div class="flex flex-col gap-1.5">
                        <Label>Question</Label>
                        <Select v-model="selectedQuestion">
                            <SelectTrigger class="w-full sm:w-[32rem]">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="detail in details"
                                    :key="detail.question"
                                    :value="detail.question"
                                >
                                    {{ detail.question }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <p class="text-sm text-muted-foreground">
                            <span class="font-medium">Ground truth answer:</span>
                            {{ selectedDetail.ground_truth_answer ?? '—' }}
                        </p>
                    </div>

                    <div class="h-80">
                        <Bar v-if="isMounted" :data="chartData" :options="chartOptions" />
                        <Skeleton v-else class="h-full w-full" />
                    </div>

                    <div class="overflow-x-auto rounded-md border">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b text-left text-muted-foreground">
                                    <th class="py-2 px-3 font-medium">Config</th>
                                    <th class="py-2 px-3 font-medium">Answer</th>
                                    <th class="py-2 px-3 font-medium">Ctx Precision</th>
                                    <th class="py-2 px-3 font-medium">Ctx Recall</th>
                                    <th class="py-2 px-3 font-medium">Faithfulness</th>
                                    <th class="py-2 px-3 font-medium">Answer Relevance</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="config in selectedDetail.configs"
                                    :key="config.config_id"
                                    class="border-b last:border-0 align-top"
                                >
                                    <td class="py-2 px-3 font-mono text-xs whitespace-nowrap">{{ formatConfigLabel(config.config_id) }}</td>
                                    <td class="py-2 px-3">{{ config.answer ?? '—' }}</td>
                                    <td class="py-2 px-3">{{ formatScore(config.context_precision) }}</td>
                                    <td class="py-2 px-3">{{ formatScore(config.context_recall) }}</td>
                                    <td class="py-2 px-3">{{ formatScore(config.faithfulness) }}</td>
                                    <td class="py-2 px-3">{{ formatScore(config.answer_relevance) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </CardContent>
            </Card>

            <Card v-if="comparison">
                <CardHeader>
                    <CardTitle>Baseline vs Best</CardTitle>
                    <CardDescription>
                        Naive baseline (<span class="font-mono text-xs">{{ formatConfigLabel(comparison.baseline.config_id) }}</span>)
                        vs the best-performing config by average RAGAS score
                        (<span class="font-mono text-xs">{{ formatConfigLabel(comparison.best.config_id) }}</span>).
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b text-left text-muted-foreground">
                                    <th class="py-2 pr-4 font-medium">Metric</th>
                                    <th class="py-2 pr-4 font-medium">Baseline</th>
                                    <th class="py-2 pr-4 font-medium">Best</th>
                                    <th class="py-2 pr-4 font-medium">Δ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="row in COMPARISON_ROWS"
                                    :key="row.key"
                                    class="border-b last:border-0"
                                >
                                    <td class="py-2 pr-4">{{ row.label }}</td>
                                    <td class="py-2 pr-4">
                                        {{
                                            row.kind === 'score'
                                                ? formatScore(comparison.baseline[row.key])
                                                : formatNumber(comparison.baseline[row.key])
                                        }}
                                    </td>
                                    <td class="py-2 pr-4">
                                        {{
                                            row.kind === 'score'
                                                ? formatScore(comparison.best[row.key])
                                                : formatNumber(comparison.best[row.key])
                                        }}
                                    </td>
                                    <td
                                        class="py-2 pr-4"
                                        :class="row.kind === 'score' ? deltaClass(comparison.baseline[row.key], comparison.best[row.key]) : 'text-muted-foreground'"
                                    >
                                        {{
                                            row.kind === 'score'
                                                ? delta(comparison.baseline[row.key], comparison.best[row.key])
                                                : numberDelta(comparison.baseline[row.key], comparison.best[row.key])
                                        }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Summary table</CardTitle>
                </CardHeader>
                <CardContent>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b text-left text-muted-foreground">
                                    <th class="py-2 pr-4 font-medium">Config</th>
                                    <th class="py-2 pr-4 font-medium">N</th>
                                    <th class="py-2 pr-4 font-medium">Ctx Precision</th>
                                    <th class="py-2 pr-4 font-medium">Ctx Recall</th>
                                    <th class="py-2 pr-4 font-medium">Faithfulness</th>
                                    <th class="py-2 pr-4 font-medium">Answer Relevance</th>
                                    <th class="py-2 pr-4 font-medium">Avg Latency (ms)</th>
                                    <th class="py-2 pr-4 font-medium">Avg Tokens</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="config in run.summary"
                                    :key="config.config_id"
                                    class="border-b last:border-0"
                                >
                                    <td class="py-2 pr-4 font-mono text-xs">{{ formatConfigLabel(config.config_id) }}</td>
                                    <td class="py-2 pr-4">{{ config.n }}</td>
                                    <td class="py-2 pr-4">{{ formatScore(config.context_precision) }}</td>
                                    <td class="py-2 pr-4">{{ formatScore(config.context_recall) }}</td>
                                    <td class="py-2 pr-4">{{ formatScore(config.faithfulness) }}</td>
                                    <td class="py-2 pr-4">{{ formatScore(config.answer_relevance) }}</td>
                                    <td class="py-2 pr-4">{{ formatNumber(config.avg_latency_ms) }}</td>
                                    <td class="py-2 pr-4">{{ formatNumber(config.avg_tokens) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <Button v-if="run.csv_path" variant="outline" class="mt-4" as-child>
                        <a :href="download(run.id).url">Download raw CSV</a>
                    </Button>
                </CardContent>
            </Card>
        </template>
    </div>
</template>
