<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\Developer\PromptScrubber;
use App\Services\Ai\Logging\Redactor;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Data minimisation: what the scrubber and the log redactor remove before text goes to a model or a log, and - just as
 * important for a documented heuristic - what they deliberately do NOT touch. These are shape heuristics, not proof that
 * personal data cannot get through (see docs/ai/06, "Limits of the PII heuristics").
 */
class PrivacyScrubbingTest extends TestCase
{
    /** @return array<string,array{0:string,1:string}> */
    public static function masked(): array
    {
        return [
            'local phone' => ['call me on 0244123456 please', '0244123456'],
            'spaced phone' => ['call 024 412 3456 now', '024 412 3456'],
            'dashed phone' => ['call 024-412-3456 now', '024-412-3456'],
            'country code phone' => ['reach me at +233244123456', '+233244123456'],
            'country code with spaces' => ['reach me at +233 24 412 3456', '+233 24 412 3456'],
            'international phone' => ['my uk number +44 7700 900123', '+44 7700 900123'],
            'email' => ['write to kwame.mensah@example.com', 'kwame.mensah@example.com'],
            'payment reference (hex)' => ['my ref is XTRA4U-65f1a2b3c4d5e-123', 'XTRA4U-65f1a2b3c4d5e-123'],
            'payment reference (gateway tag)' => ['ref XTRA4U-FLW-65F1A2B3C4D5E-1700000000', 'XTRA4U-FLW-65F1A2B3C4D5E-1700000000'],
            'payment reference (result checker)' => ['ref XTRA4U-RC-65F1A2B3C4D5E.12345678-77', 'XTRA4U-RC-65F1A2B3C4D5E.12345678-77'],
            'afa reference' => ['registration AFA-1A2B3C4D-123', 'AFA-1A2B3C4D-123'],
            'long digit run' => ['card 4111111111111111', '4111111111111111'],
            'api key' => ['key sk-abcdefghijklmnopqrstuvwxyz0123456789', 'sk-abcdefghijklmnopqrstuvwxyz0123456789'],
            'platform key' => ['key xt_live_abcdef0123456789', 'xt_live_abcdef0123456789'],
            'webhook secret' => ['secret whsec_abcdef0123456789', 'whsec_abcdef0123456789'],
            'authorization header' => ['Authorization: Bearer abcdefghijklmnopqrstuvwxyz', 'abcdefghijklmnopqrstuvwxyz'],
        ];
    }

    #[DataProvider('masked')]
    public function test_the_scrubber_masks_personal_and_secret_shapes(string $input, string $secret): void
    {
        foreach ([false, true] as $strict) {
            $out = PromptScrubber::scrub($input, $strict);

            $this->assertStringNotContainsString($secret, $out, ($strict ? 'strict: ' : '').$input);
            $this->assertNotSame($input, $out);
        }
    }

    #[DataProvider('masked')]
    public function test_the_log_redactor_masks_the_same_shapes(string $input, string $secret): void
    {
        $this->assertStringNotContainsString($secret, Redactor::redact($input), $input);
    }

    /** @return array<string,array{0:string}> */
    public static function untouched(): array
    {
        return [
            'header name' => ['the X-XTRA4U-Environment header says sandbox'],
            'user agent' => ['User-Agent: XTRA4U-Webhooks/1.0'],
            'brand word' => ['XTRA4U-Environment is a word, not a reference'],
            'vendor code' => ['vendor code XTRA4U-001 is public'],
            'prose about tokens' => ['a bearer token goes in the header'],
            'afa word' => ['AFA-Registration is open to everyone'],
            'ordinary prices' => ['MTN 1GB costs GHS 5.00 for 30 days'],
            'short numbers' => ['order 12345 and size 10GB'],
        ];
    }

    #[DataProvider('untouched')]
    public function test_ordinary_text_and_documented_names_are_not_mangled_on_the_developer_and_vendor_paths(string $input): void
    {
        $this->assertSame($input, PromptScrubber::scrub($input), 'default (non-strict) scrubbing');
    }

    public function test_the_strict_mode_adds_the_long_identifier_heuristic_and_says_so(): void
    {
        $unknownShape = 'my order code is ORD2026ABCDE9X87Q65R4T3Z';

        $this->assertSame($unknownShape, PromptScrubber::scrub($unknownShape), 'the default mode does not guess');
        $this->assertSame('my order code is [REFERENCE]', PromptScrubber::scrub($unknownShape, true));

        // The documented limits: a SHORT or unusually shaped reference is not caught by any mode.
        foreach (['ref ABC12', 'ref 7f3a', 'ref pay_12345'] as $short) {
            $this->assertSame($short, PromptScrubber::scrub($short, true), "{$short}: below the heuristic's reach");
        }
    }

    public function test_the_log_redactor_leaves_request_and_provider_ids_readable(): void
    {
        foreach (['01m80k4ey5bydax41pndza0t6x', 'resp_67f1a2b3c4d5e6f7a8b9c0d1', '3f2504e0-4f89-41d3-9a0c-0305e82c3301'] as $id) {
            $this->assertSame($id, Redactor::redact($id), 'an id that is not a payment reference stays readable in logs');
        }
    }

    public function test_the_redactor_masks_values_nested_in_log_context(): void
    {
        $out = Redactor::context(['phone' => '0244123456', 'nested' => ['email' => 'a@b.com', 'ref' => 'XTRA4U-65f1a2b3c4d5e-123'], 'request_id' => '01m80k4ey5bydax41pndza0t6x']);

        $this->assertStringNotContainsString('0244123456', json_encode($out));
        $this->assertStringNotContainsString('a@b.com', json_encode($out));
        $this->assertStringNotContainsString('65f1a2b3c4d5e', json_encode($out));
        $this->assertSame('01m80k4ey5bydax41pndza0t6x', $out['request_id']);
    }

    public function test_the_customer_assistant_is_the_only_caller_of_the_strict_mode(): void
    {
        foreach (glob(app_path('Services/Ai/*/*.php')) as $file) {
            $strict = preg_match('/PromptScrubber::scrub\([^)]*,\s*true\)/', (string) file_get_contents($file)) === 1;

            $this->assertSame(basename($file) === 'CustomerAssistant.php', $strict, basename($file));
        }
    }
}
