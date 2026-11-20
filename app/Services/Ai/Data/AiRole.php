<?php

namespace App\Services\Ai\Data;

enum AiRole: string
{
    case System = 'system';
    case User = 'user';
    case Assistant = 'assistant';
    case Tool = 'tool';
}
