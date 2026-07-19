<?php

namespace App\Services;

use App\Models\QueryTemplate;
use App\Models\QueryTemplateVersion;

class QueryTemplateVersionComparisonService
{
    /**
     * @return array{
     *     summary: array{added: int, removed: int, changed: int, total: int},
     *     changes: list<array{path: string, section: string, kind: string, before: mixed, after: mixed}>
     * }
     */
    public function compare(
        QueryTemplate $template,
        QueryTemplateVersion $fromVersion,
        QueryTemplateVersion $toVersion,
    ): array {
        if (! $fromVersion->belongsToTemplate($template) || ! $toVersion->belongsToTemplate($template)) {
            abort(404);
        }

        $changes = [];
        $this->collectChanges(
            $fromVersion->definition,
            $toVersion->definition,
            'definition',
            true,
            true,
            $changes,
        );
        $this->collectChanges(
            $fromVersion->translations,
            $toVersion->translations,
            'translations',
            true,
            true,
            $changes,
        );
        $summary = ['added' => 0, 'removed' => 0, 'changed' => 0, 'total' => count($changes)];

        foreach ($changes as $change) {
            match ($change['kind']) {
                'added' => $summary['added']++,
                'removed' => $summary['removed']++,
                default => $summary['changed']++,
            };
        }

        return ['summary' => $summary, 'changes' => $changes];
    }

    /**
     * @param  list<array{path: string, section: string, kind: string, before: mixed, after: mixed}>  $changes
     */
    private function collectChanges(
        mixed $before,
        mixed $after,
        string $path,
        bool $beforeExists,
        bool $afterExists,
        array &$changes,
    ): void {
        if ($beforeExists && $afterExists && $before === $after) {
            return;
        }

        if ($beforeExists && $afterExists && is_array($before) && is_array($after)) {
            $keys = array_values(array_unique([...array_keys($before), ...array_keys($after)]));
            usort($keys, static function (int|string $left, int|string $right): int {
                if (is_int($left) && is_int($right)) {
                    return $left <=> $right;
                }

                return (string) $left <=> (string) $right;
            });

            foreach ($keys as $key) {
                $hasBefore = array_key_exists($key, $before);
                $hasAfter = array_key_exists($key, $after);
                $this->collectChanges(
                    $hasBefore ? $before[$key] : null,
                    $hasAfter ? $after[$key] : null,
                    "{$path}.{$key}",
                    $hasBefore,
                    $hasAfter,
                    $changes,
                );
            }

            return;
        }

        $kind = ! $beforeExists ? 'added' : (! $afterExists ? 'removed' : 'changed');
        $changes[] = [
            'path' => $path,
            'section' => $this->sectionFor($path),
            'kind' => $kind,
            'before' => $beforeExists ? $before : null,
            'after' => $afterExists ? $after : null,
        ];
    }

    private function sectionFor(string $path): string
    {
        if (str_starts_with($path, 'translations.')) {
            return 'translations';
        }

        foreach (['definition.name', 'definition.description', 'definition.category_id', 'definition.sort_order'] as $metadataPath) {
            if ($path === $metadataPath || str_starts_with($path, "{$metadataPath}.")) {
                return 'metadata';
            }
        }

        return 'technical';
    }
}
