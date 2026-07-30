<?php

namespace App\Http\Controllers;

use App\Enums\DocumentStatus;
use App\Http\Requests\StoreDocumentRequest;
use App\Jobs\ChunkDocumentJob;
use App\Jobs\EmbedChunksJob;
use App\Jobs\ExtractDocumentTextJob;
use App\Models\Document;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Bus;
use Inertia\Inertia;
use Inertia\Response;

class DocumentController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Documents/Index', [
            'documents' => Document::query()->latest()->get(),
        ]);
    }

    public function store(StoreDocumentRequest $request): RedirectResponse
    {
        $file = $request->file('file');

        $document = Document::query()->create([
            'title' => $file->getClientOriginalName(),
            'original_filename' => $file->getClientOriginalName(),
            'disk_path' => $file->store('documents', 'local'),
            'status' => DocumentStatus::Pending,
        ]);

        Bus::chain([
            new ExtractDocumentTextJob($document),
            new ChunkDocumentJob($document),
            new EmbedChunksJob($document),
        ])->dispatch();

        return to_route('documents.index');
    }
}
