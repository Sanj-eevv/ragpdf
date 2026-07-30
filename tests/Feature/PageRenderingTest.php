<?php

use App\Enums\DocumentStatus;
use App\Models\Document;
use Inertia\Testing\AssertableInertia as Assert;

test('the documents index page lists all documents regardless of status', function () {
    $ready = Document::factory()->create(['title' => 'Ready doc']);
    $pending = Document::factory()->pending()->create(['title' => 'Pending doc']);

    $response = $this->get(route('documents.index'))->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->component('Documents/Index')
        ->has('documents', 2)
    );

    $ids = collect($response->original->getData()['page']['props']['documents'])->pluck('id')->all();
    expect($ids)->toEqualCanonicalizing([$ready->id, $pending->id]);
});

test('the chat page only offers documents that are ready for querying', function () {
    $ready = Document::factory()->create(['title' => 'Ready doc']);
    Document::factory()->pending()->create(['title' => 'Pending doc']);
    Document::factory()->failed()->create(['title' => 'Failed doc']);

    $this->get(route('chat.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Chat/Index')
            ->has('documents', 1)
            ->where('documents.0.id', $ready->id)
        );
});

test('a document reports failed status with its error message', function () {
    $document = Document::factory()->failed()->create();

    expect($document->status)->toBe(DocumentStatus::Failed)
        ->and($document->error_message)->not->toBeNull();
});
