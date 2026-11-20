<?php

namespace App\Services\Ai\Tools;

/** What kind of authenticated actor a ToolContext represents. */
enum ToolActorType: string
{
    /** An approved vendor, authenticated through the vendor guard (also the owner of Developer API applications). */
    case Vendor = 'vendor';

    /** An anonymous storefront visitor. Not an account: there is no customer id, only a server-session key. */
    case Guest = 'guest';
}
