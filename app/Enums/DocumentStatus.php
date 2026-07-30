<?php

namespace App\Enums;

enum DocumentStatus: string
{
    case Pending = 'pending';
    case Extracting = 'extracting';
    case Chunking = 'chunking';
    case Embedding = 'embedding';
    case Ready = 'ready';
    case Failed = 'failed';
}
