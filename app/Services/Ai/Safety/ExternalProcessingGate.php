<?php

namespace App\Services\Ai\Safety;

use App\Services\Ai\Exceptions\AiConfigurationException;

/**
 * The privacy gate for sending prompts to a third party (an external AI provider).
 *
 * Whether that is acceptable is a LEGAL / PRIVACY decision that this code cannot make and does not claim to have
 * verified. The gate therefore ships closed (`ai.external_processing.approved` = false) and opens only when an
 * authorised person records, in the environment, that the approval exists AND where it can be found:
 *
 *   AI_EXTERNAL_PROCESSING_APPROVED=true
 *   AI_EXTERNAL_PROCESSING_APPROVAL_REF=<ticket / document reference of the signed-off privacy review>
 *   AI_EXTERNAL_PROCESSING_APPROVED_BY=<name or role of the approver>
 *   AI_EXTERNAL_PROCESSING_APPROVED_ON=<YYYY-MM-DD, not in the future>
 *
 * All four must be present and well-formed. This makes a casual `AI_EXTERNAL_PROCESSING_APPROVED=true` insufficient and
 * leaves an audit trail, but it is still only a RECORD: an environment variable cannot prove the approval is genuine.
 * Who may set it and what must precede it is documented in docs/ai/06 (section 5). Nothing in this class contacts the
 * provider, and none of the recorded values is ever logged or shown (only the names of the missing settings are).
 */
class ExternalProcessingGate
{
    /** @return list<string> what is missing or malformed (names only - never a value); empty when the gate is open */
    public function problems(): array
    {
        $cfg = (array) config('ai.external_processing', []);
        $problems = [];

        if (($cfg['approved'] ?? false) !== true) {
            return ['AI_EXTERNAL_PROCESSING_APPROVED is not true (external AI processing has not been approved)'];
        }

        $ref = $cfg['approval_reference'] ?? null;
        if (! is_string($ref) || preg_match('/^[A-Za-z0-9][A-Za-z0-9 ._\-\/#:]{2,119}$/D', $ref) !== 1) {
            $problems[] = 'AI_EXTERNAL_PROCESSING_APPROVAL_REF is missing or malformed';
        }

        $by = $cfg['approved_by'] ?? null;
        if (! is_string($by) || mb_strlen(trim($by)) < 3 || mb_strlen($by) > 120) {
            $problems[] = 'AI_EXTERNAL_PROCESSING_APPROVED_BY is missing or malformed';
        }

        $on = $cfg['approved_on'] ?? null;
        $date = is_string($on) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $on) === 1 ? \DateTimeImmutable::createFromFormat('!Y-m-d', $on) : false;
        if ($date === false || $date->format('Y-m-d') !== $on) {
            $problems[] = 'AI_EXTERNAL_PROCESSING_APPROVED_ON must be a valid YYYY-MM-DD date';
        } elseif ($date > now()->endOfDay()->toDateTimeImmutable()) {
            $problems[] = 'AI_EXTERNAL_PROCESSING_APPROVED_ON is in the future';
        }

        return $problems;
    }

    public function isOpen(): bool
    {
        return $this->problems() === [];
    }

    /** @throws AiConfigurationException */
    public function assertOpen(): void
    {
        if ($this->problems() !== []) {
            throw new AiConfigurationException('External AI processing has not been approved; the live provider will not run.');
        }
    }
}
