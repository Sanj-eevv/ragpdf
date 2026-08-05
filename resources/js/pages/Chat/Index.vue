<script setup lang="ts">
import { Head, useHttp } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import { toast } from 'vue-sonner';
import { store } from '@/actions/App/Http/Controllers/QueryController';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

type ChunkingStrategy = 'tokens_500' | 'tokens_1000';
type RetrievalAlgorithm = 'dense' | 'hybrid';

type ContextChunk = {
    id: number;
    content: string;
};

type QueryForm = {
    question: string;
    document_id: number | null;
    chunking_strategy: ChunkingStrategy;
    retrieval_algorithm: RetrievalAlgorithm;
    reranked: boolean;
};

type QueryResponse = {
    query: {
        id: number;
        answer: string;
        latency_ms: number;
        prompt_tokens: number;
        completion_tokens: number;
    };
    context: ContextChunk[];
};

type ErrorResponse = {
    message?: string;
};

type Message = {
    question: string;
    answer: string;
    latencyMs: number;
    promptTokens: number;
    completionTokens: number;
    context: ContextChunk[];
    showContext: boolean;
};

defineProps<{
    documents: { id: number; title: string }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Chat', href: '/chat' },
        ],
    },
});

const messages = reactive<Message[]>([]);

const http = useHttp<QueryForm, QueryResponse>({
    question: '',
    document_id: null,
    chunking_strategy: 'tokens_500',
    retrieval_algorithm: 'dense',
    reranked: false,
});

const ALL_DOCUMENTS = 'all';

const documentIdString = computed({
    get: () => (http.document_id ? String(http.document_id) : ALL_DOCUMENTS),
    set: (value: string) => {
        http.document_id = value === ALL_DOCUMENTS ? null : Number(value);
    },
});

function ask() {
    if (!http.question.trim() || http.processing) {
        return;
    }

    const askedQuestion = http.question;

    http.post(store.url(), {
        onSuccess: (response) => {
            messages.push({
                question: askedQuestion,
                answer: response.query.answer,
                latencyMs: response.query.latency_ms,
                promptTokens: response.query.prompt_tokens,
                completionTokens: response.query.completion_tokens,
                context: response.context,
                showContext: false,
            });
            http.question = '';
        },
        onHttpException: (response) => {
            const data = response.data as ErrorResponse;
            toast.error(data.message ?? 'Something went wrong. Please try again.');
        },
        onNetworkError: () => {
            toast.error('Network error. Check your connection and try again.');
        },
    });
}
</script>

<template>
    <Head title="Chat" />

    <div
        class="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4"
    >
        <Card>
            <CardHeader>
                <CardTitle>Experiment settings</CardTitle>
            </CardHeader>
            <CardContent class="flex flex-wrap items-end gap-4">
                <div class="flex flex-col gap-1.5">
                    <Label>Document</Label>
                    <Select v-model="documentIdString">
                        <SelectTrigger class="w-56">
                            <SelectValue placeholder="All documents" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All documents</SelectItem>
                            <SelectItem
                                v-for="document in documents"
                                :key="document.id"
                                :value="String(document.id)"
                            >
                                {{ document.title }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div class="flex flex-col gap-1.5">
                    <Label>Chunking strategy</Label>
                    <Select v-model="http.chunking_strategy">
                        <SelectTrigger class="w-40">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="tokens_500"
                                >500 tokens</SelectItem
                            >
                            <SelectItem value="tokens_1000"
                                >1000 tokens</SelectItem
                            >
                        </SelectContent>
                    </Select>
                </div>

                <div class="flex flex-col gap-1.5">
                    <Label>Retrieval algorithm</Label>
                    <Select v-model="http.retrieval_algorithm">
                        <SelectTrigger class="w-40">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="dense">Dense</SelectItem>
                            <SelectItem value="hybrid">Hybrid (RRF)</SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <label class="flex items-center gap-2 pb-2 text-sm">
                    <Checkbox v-model="http.reranked" />
                    Re-rank results
                </label>
            </CardContent>
        </Card>

        <Card class="flex flex-1 flex-col overflow-hidden">
            <CardHeader>
                <CardTitle>Questions &amp; answers</CardTitle>
            </CardHeader>
            <CardContent class="flex flex-1 flex-col gap-4 overflow-y-auto">
                <p
                    v-if="messages.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    Ask a question about your documents to get started.
                </p>

                <div
                    v-for="(message, index) in messages"
                    :key="index"
                    class="rounded-lg border p-3"
                >
                    <p class="font-medium">{{ message.question }}</p>
                    <p class="mt-1 text-sm whitespace-pre-line">
                        {{ message.answer }}
                    </p>
                    <div
                        class="mt-2 flex items-center gap-3 text-xs text-muted-foreground"
                    >
                        <span>{{ message.latencyMs }}ms</span>
                        <span
                            >{{ message.promptTokens }} +
                            {{ message.completionTokens }} tokens</span
                        >
                        <button
                            type="button"
                            class="underline"
                            @click="message.showContext = !message.showContext"
                        >
                            {{ message.showContext ? 'Hide' : 'Show' }}
                            retrieved context ({{ message.context.length }})
                        </button>
                    </div>
                    <div
                        v-if="message.showContext"
                        class="mt-2 flex flex-col gap-2"
                    >
                        <div
                            v-for="chunk in message.context"
                            :key="chunk.id"
                            class="rounded bg-muted p-2 text-xs"
                        >
                            {{ chunk.content }}
                        </div>
                    </div>
                </div>
            </CardContent>
        </Card>

        <form class="flex gap-2" @submit.prevent="ask">
            <Input
                v-model="http.question"
                placeholder="Ask a question about your documents…"
                class="flex-1"
            />
            <Button
                type="submit"
                :disabled="http.processing || !http.question.trim()"
            >
                {{ http.processing ? 'Asking…' : 'Ask' }}
            </Button>
        </form>
        <p v-if="http.errors.question" class="text-sm text-destructive">
            {{ http.errors.question }}
        </p>
    </div>
</template>
