<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

final readonly class PuCurvePrerequisiteCheckResult
{
    /**
     * @param  list<PuCurvePrerequisiteIssue>  $issues
     */
    public function __construct(
        public array $issues = [],
    ) {}

    public function passes(): bool
    {
        return $this->blockingIssues() === [];
    }

    /**
     * @return list<PuCurvePrerequisiteIssue>
     */
    public function blockingIssues(): array
    {
        return array_values(array_filter(
            $this->issues,
            static fn (PuCurvePrerequisiteIssue $issue): bool => $issue->blocking,
        ));
    }

    /**
     * @return list<PuCurvePrerequisiteIssue>
     */
    public function warningIssues(): array
    {
        return array_values(array_filter(
            $this->issues,
            static fn (PuCurvePrerequisiteIssue $issue): bool => ! $issue->blocking,
        ));
    }

    /**
     * @return list<string>
     */
    public function blockingMessages(): array
    {
        return array_map(
            static fn (PuCurvePrerequisiteIssue $issue): string => $issue->message,
            $this->blockingIssues(),
        );
    }

    /**
     * @return list<string>
     */
    public function warningMessages(): array
    {
        return array_map(
            static fn (PuCurvePrerequisiteIssue $issue): string => $issue->message,
            $this->warningIssues(),
        );
    }

    public function blockingSummary(): string
    {
        return implode("\n", $this->blockingMessages());
    }

    /**
     * @return array{
     *     passes: bool,
     *     blocking: list<array{key: string, message: string}>,
     *     warnings: list<array{key: string, message: string}>
     * }
     */
    public function toArray(): array
    {
        return [
            'passes' => $this->passes(),
            'blocking' => array_map(
                static fn (PuCurvePrerequisiteIssue $issue): array => [
                    'key' => $issue->key,
                    'message' => $issue->message,
                ],
                $this->blockingIssues(),
            ),
            'warnings' => array_map(
                static fn (PuCurvePrerequisiteIssue $issue): array => [
                    'key' => $issue->key,
                    'message' => $issue->message,
                ],
                $this->warningIssues(),
            ),
        ];
    }
}
