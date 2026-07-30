<?php

namespace App\Http\Controllers;

use App\Enums\DocumentStatus;
use App\Models\Document;
use Inertia\Inertia;
use Inertia\Response;

class ChatController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Chat/Index', [
            'documents' => Document::query()
                ->where('status', DocumentStatus::Ready)
                ->orderBy('title')
                ->get(['id', 'title']),
        ]);
    }
}
