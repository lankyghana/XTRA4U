<?php

namespace App\Services\Ai\Tools\Schema;

enum ToolParameterType: string
{
    case String = 'string';
    case Integer = 'integer';
    case Number = 'number';
    case Boolean = 'boolean';
    case Enum = 'enum';
}
