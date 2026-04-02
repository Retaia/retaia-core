<?php

namespace App\Controller\Api;

use Symfony\Component\HttpFoundation\Request;

final class AssetListQueryParser
{
    /**
     * @return array{
     *     states:list<string>,
     *     mediaType:?string,
     *     query:?string,
     *     sort:?string,
     *     capturedAtFrom:?string,
     *     capturedAtTo:?string,
     *     limit:int,
     *     cursor:?string,
     *     tags:list<string>,
     *     tagsMode:string,
     *     hasPreview:?bool,
     *     locationCountry:?string,
     *     locationCity:?string,
     *     geoBbox:?string
     * }
     */
    public function parse(Request $request): array
    {
        $mediaType = $request->query->get('media_type');
        $query = $request->query->get('q');
        $sort = $request->query->get('sort');
        $capturedAtFrom = $request->query->get('captured_at_from');
        $capturedAtTo = $request->query->get('captured_at_to');
        $cursor = $request->query->get('cursor');

        return [
            'states' => $this->csvUpperList($request->query->get('state')),
            'mediaType' => is_string($mediaType) ? $mediaType : null,
            'query' => is_string($query) ? $query : null,
            'sort' => is_string($sort) ? $sort : null,
            'capturedAtFrom' => is_string($capturedAtFrom) ? $capturedAtFrom : null,
            'capturedAtTo' => is_string($capturedAtTo) ? $capturedAtTo : null,
            'limit' => max(1, (int) $request->query->get('limit', 50)),
            'cursor' => is_string($cursor) ? $cursor : null,
            'tags' => $this->csvList($request->query->get('tags')),
            'tagsMode' => (string) $request->query->get('tags_mode', 'AND'),
            'hasPreview' => $this->nullableBooleanQuery($request, 'has_preview'),
            'locationCountry' => $this->optionalString($request->query->get('location_country')),
            'locationCity' => $this->optionalString($request->query->get('location_city')),
            'geoBbox' => $this->optionalString($request->query->get('geo_bbox')),
        ];
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private function csvList(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $items = array_map(static fn (string $item): string => mb_strtolower(trim($item)), explode(',', $value));

        return array_values(array_filter($items, static fn (string $item): bool => $item !== ''));
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private function csvUpperList(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $items = array_map(static fn (string $item): string => strtoupper(trim($item)), explode(',', $value));

        return array_values(array_unique(array_filter($items, static fn (string $item): bool => $item !== '')));
    }

    private function optionalString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function nullableBooleanQuery(Request $request, string $key): ?bool
    {
        if (!$request->query->has($key)) {
            return null;
        }

        $value = $request->query->get($key);
        if (is_bool($value)) {
            return $value;
        }

        if (!is_string($value)) {
            return null;
        }

        return match (mb_strtolower(trim($value))) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => null,
        };
    }
}
