<?php

namespace App\Services\Ai\Data;

/** Who the assistant is talking to. The surface is chosen by server code (route), never by a request field. */
enum AiSurface: string
{
    case Customer = 'customer';
    case Vendor = 'vendor';
    case Developer = 'developer';
}
