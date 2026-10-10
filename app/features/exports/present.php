<?php
declare(strict_types=1);

/** The export list's cards and the facts a download reports (JSON mode, DECISION 4: facts, never the file). */

function present_export_card(string $slug, array $spec): array
{
    return ['export' => $slug, 'title' => $spec['title'], 'schema' => $spec['schema'], 'about' => $spec['about'], 'accounting' => $spec['accounting'], 'fields' => $spec['fields'], 'may' => export_may($slug)];
}

function export_filename(string $export, array $params, string $format): string
{
    $tag = isset($params['from']) ? $params['from'] . '-' . $params['to'] : ($params['as_of'] ?? gmdate('Y-m-d'));
    return $export . '-' . $tag . '.' . $format;
}
