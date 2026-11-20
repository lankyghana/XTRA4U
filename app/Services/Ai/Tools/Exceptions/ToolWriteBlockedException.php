<?php

namespace App\Services\Ai\Tools\Exceptions;

/** Thrown by the read-only guard when a tool tries to run a statement that is not a plain read. Never shown to a model. */
final class ToolWriteBlockedException extends \RuntimeException {}
