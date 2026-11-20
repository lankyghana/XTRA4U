<?php

namespace App\Services\Ai\Tools;

enum ToolResultStatus: string
{
    /** The tool ran and returned data. */
    case Ok = 'ok';

    /** Refused BEFORE the tool ran (unknown/unavailable tool, bad arguments, bad context, not authorised, limits). */
    case Rejected = 'rejected';

    /** The tool ran (or started to) and did not produce a usable result. */
    case Failed = 'failed';
}
