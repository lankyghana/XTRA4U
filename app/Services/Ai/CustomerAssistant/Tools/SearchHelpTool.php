<?php

namespace App\Services\Ai\CustomerAssistant\Tools;

use App\Models\Cms\CmsFaq;
use App\Services\Ai\CustomerAssistant\Tools\Concerns\CustomerSurfaceTool;
use App\Services\Ai\Tools\Contracts\AiTool;
use App\Services\Ai\Tools\Schema\ToolParameter;
use App\Services\Ai\Tools\Schema\ToolSchema;
use App\Services\Ai\Tools\ToolArguments;
use App\Services\Ai\Tools\ToolContext;

/**
 * Searches the PUBLISHED public FAQ (the questions an administrator manages under Content > FAQs and publishes on
 * /faq). That is existing, customer-facing, already-public content: no internal documentation, audit report, developer
 * guide or operational note is reachable from here. Inactive FAQs are excluded in the SQL query.
 *
 * FAQ text is written by administrators and is treated as untrusted data like any other retrieved text: tags and links
 * are stripped and each answer is bounded. Nothing is invented: when no FAQ matches, the tool says so.
 */
final class SearchHelpTool implements AiTool
{
    use CustomerSurfaceTool;

    public const NAME = 'customer_search_help';

    public const MAX_LIMIT = 5;

    /** FAQs examined before ranking. */
    private const SCAN_LIMIT = 40;

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Searches the published XTRA4U FAQ for help with buying, paying and using the platform. Returns the matching questions and answers.';
    }

    public function schema(): ToolSchema
    {
        return new ToolSchema(
            ToolParameter::string('query', 'What the visitor wants help with, in a few words.', true, 100, 2),
            ToolParameter::integer('limit', 'How many answers to return (1-'.self::MAX_LIMIT.', default 3).', 1, self::MAX_LIMIT),
        );
    }

    public function handle(ToolArguments $arguments, ToolContext $context): array
    {
        $limit = $arguments->int('limit', 3);
        $terms = $this->terms((string) $arguments->string('query'));

        if ($terms === []) {
            return ['returned' => 0, 'answers' => [], 'note' => 'Please describe what you need help with in a few words.'];
        }

        $rows = CmsFaq::query()
            ->where('is_active', true)
            ->where(function ($q) use ($terms) {
                foreach ($terms as $term) {
                    $q->orWhere('question', 'like', "%{$term}%")->orWhere('answer', 'like', "%{$term}%");
                }
            })
            ->orderBy('sort_order')->orderBy('id')
            ->limit(self::SCAN_LIMIT)
            ->get(['id', 'category', 'question', 'answer']);

        $ranked = $rows
            ->map(function (CmsFaq $faq) use ($terms) {
                $question = mb_strtolower((string) $faq->question);
                $answer = mb_strtolower((string) $faq->answer);
                $score = 0;
                foreach ($terms as $term) {
                    $score += (str_contains($question, $term) ? 3 : 0) + (str_contains($answer, $term) ? 1 : 0);
                }

                return ['score' => $score, 'faq' => $faq];
            })
            ->filter(fn (array $r) => $r['score'] > 0)
            ->sortByDesc('score')->values()
            ->take($limit);

        $answers = $ranked->map(fn (array $r) => [
            'topic' => CustomerToolSupport::text($r['faq']->category, 60),
            'question' => CustomerToolSupport::text($r['faq']->question, 200),
            'answer' => CustomerToolSupport::text($this->plain((string) $r['faq']->answer), 500),
        ])->all();

        return [
            'returned' => count($answers),
            'answers' => $answers,
            'note' => $answers === []
                ? 'No published FAQ matched. Do not guess; suggest the visitor check the website or contact XTRA4U support.'
                : 'These are published FAQ entries (written by XTRA4U staff). Use them as written; do not add details they do not contain.',
        ];
    }

    /** @return list<string> */
    private function terms(string $query): array
    {
        $text = mb_strtolower((string) preg_replace('/[%_\\\\\p{C}]+/u', ' ', $query));
        $words = array_filter(
            preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [],
            fn (string $w) => mb_strlen($w) >= 3,
        );

        return array_slice(array_values(array_unique($words)), 0, 5);
    }

    /** Markdown to plain text: links keep their label, emphasis/heading/list markers go. SafeText strips any HTML. */
    private function plain(string $markdown): string
    {
        $text = (string) preg_replace('/!?\[([^\]]*)\]\([^)]*\)/', '$1', $markdown);
        $text = (string) preg_replace('/^[ \t]*(?:#{1,6}|>|[-*+]|\d+\.)\s+/m', '', $text);

        return str_replace(['**', '__', '`', '*'], '', $text);
    }
}
