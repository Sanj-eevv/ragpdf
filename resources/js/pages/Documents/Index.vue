<script setup lang="ts">
import { Head, useForm, usePoll } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { store } from '@/actions/App/Http/Controllers/DocumentController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { dashboard } from '@/routes';

type DocumentStatus =
    'pending' | 'extracting' | 'chunking' | 'embedding' | 'ready' | 'failed';

type DocumentRow = {
    id: number;
    title: string;
    original_filename: string;
    page_count: number | null;
    status: DocumentStatus;
    error_message: string | null;
    created_at: string;
};

const props = defineProps<{
    documents: DocumentRow[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Documents', href: '/documents' },
        ],
    },
});

const fileInput = ref<HTMLInputElement | null>(null);

const form = useForm<{ file: File | null }>({
    file: null,
});

function onFileChange(event: Event) {
    const target = event.target as HTMLInputElement;
    form.file = target.files?.[0] ?? null;
}

function submit() {
    form.submit(store(), {
        forceFormData: true,
        onSuccess: () => {
            form.reset();

            if (fileInput.value) {
                fileInput.value.value = '';
            }
        },
    });
}

const hasProcessingDocuments = computed(() =>
    props.documents.some(
        (document) => !['ready', 'failed'].includes(document.status),
    ),
);

const { start: startPolling, stop: stopPolling } = usePoll(
    3000,
    { only: ['documents'] },
    { autoStart: false },
);

watch(
    hasProcessingDocuments,
    (isProcessing) => (isProcessing ? startPolling() : stopPolling()),
    { immediate: true },
);

const statusVariant: Record<
    DocumentStatus,
    'default' | 'secondary' | 'destructive' | 'outline'
> = {
    pending: 'outline',
    extracting: 'secondary',
    chunking: 'secondary',
    embedding: 'secondary',
    ready: 'default',
    failed: 'destructive',
};
</script>

<template>
    <Head title="Documents" />

    <div
        class="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4"
    >
        <Card>
            <CardHeader>
                <CardTitle>Upload a PDF</CardTitle>
            </CardHeader>
            <CardContent>
                <form class="flex items-center gap-3" @submit.prevent="submit">
                    <input
                        ref="fileInput"
                        type="file"
                        accept="application/pdf"
                        class="text-sm"
                        @change="onFileChange"
                    />
                    <Button
                        type="submit"
                        :disabled="!form.file || form.processing"
                    >
                        {{ form.processing ? 'Uploading…' : 'Upload' }}
                    </Button>
                </form>
                <progress
                    v-if="form.progress"
                    :value="form.progress.percentage"
                    max="100"
                    class="mt-2 w-full"
                >
                    {{ form.progress.percentage }}%
                </progress>
                <p
                    v-if="form.errors.file"
                    class="mt-2 text-sm text-destructive"
                >
                    {{ form.errors.file }}
                </p>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle>Documents</CardTitle>
            </CardHeader>
            <CardContent>
                <p
                    v-if="documents.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    No documents uploaded yet.
                </p>
                <table v-else class="w-full text-sm">
                    <thead>
                        <tr class="border-b text-left text-muted-foreground">
                            <th class="py-2 pr-4 font-medium">Title</th>
                            <th class="py-2 pr-4 font-medium">Pages</th>
                            <th class="py-2 pr-4 font-medium">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="document in documents"
                            :key="document.id"
                            class="border-b last:border-0"
                        >
                            <td class="py-2 pr-4">{{ document.title }}</td>
                            <td class="py-2 pr-4">
                                {{ document.page_count ?? '—' }}
                            </td>
                            <td class="py-2 pr-4">
                                <Badge
                                    :variant="statusVariant[document.status]"
                                    >{{ document.status }}</Badge
                                >
                                <span
                                    v-if="
                                        document.status === 'failed' &&
                                        document.error_message
                                    "
                                    class="ml-2 text-xs text-destructive"
                                >
                                    {{ document.error_message }}
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </CardContent>
        </Card>
    </div>
</template>
