<?php

declare(strict_types=1);

namespace Racin\Console\Generators;

use RuntimeException;
use stdClass;

/**
 * Adds (or removes) a folder of CRUD requests for a resource in a Postman collection:
 * list, create (POST), get, replace (PUT), update (PATCH), image upload, delete (DELETE), 404 after delete.
 *
 * Requests use the {{baseUrl}} and {{adminToken}} variables; "Create" stores the new id in {{<model>Id}}.
 * Objects are kept as stdClass so a rewrite leaves the rest of the file byte-for-byte unchanged.
 */
final class PostmanFolder
{
    public const string SAMPLE_IMAGE = 'sample-image.png';

    /**
     * @param list<array<string, mixed>> $rows three sample rows (create, replace, patch)
     * @param list<string> $images image fields (multipart upload request)
     */
    public static function add(string $file, Names $names, array $rows, array $images = []): void
    {
        $collection = self::read($file);
        $folder = self::folder($names, $rows, $images);
        $items = array_values(array_filter($collection->item, static fn (stdClass $i): bool => $i->name !== $folder->name));
        $before = array_search('Error examples', array_map(static fn (stdClass $i): string => $i->name, $items), true);
        array_splice($items, $before === false ? count($items) : $before, 0, [$folder]);
        $collection->item = $items;

        $variable = $names->variable . 'Id';
        $known = array_map(static fn (stdClass $v): string => $v->key, $collection->variable ?? []);

        if (!in_array($variable, $known, true)) {
            $collection->variable[] = (object) ['key' => $variable, 'value' => '', 'type' => 'string', 'description' => "{$names->model} created by \"Create {$names->variable}\""];
        }

        self::write($file, $collection);
    }

    public static function remove(string $file, Names $names): bool
    {
        $collection = self::read($file);
        $name = self::folderName($names);
        $items = array_values(array_filter($collection->item, static fn (stdClass $i): bool => $i->name !== $name));

        if (count($items) === count($collection->item)) {
            return false;
        }

        $collection->item = $items;
        $collection->variable = array_values(array_filter($collection->variable ?? [], static fn (stdClass $v): bool => $v->key !== $names->variable . 'Id'));
        self::write($file, $collection);

        return true;
    }

    public static function folderName(Names $names): string
    {
        return $names->pluralStudly;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string> $images
     */
    private static function folder(Names $names, array $rows, array $images): stdClass
    {
        $route = $names->route;
        $id = '{{' . $names->variable . 'Id}}';
        $label = $names->variable;
        [$create, $replace] = [$rows[0] ?? [], $rows[1] ?? $rows[0] ?? []];
        $patchKey = null;

        foreach ($create as $key => $value) {
            if (is_string($value)) {
                $patchKey = $key;
                break;
            }
        }

        // The third sample row's value passes the same rules (max length, alpha_dash, unique ...).
        $patch = $patchKey === null ? [] : [$patchKey => $rows[2][$patchKey] ?? $replace[$patchKey]];

        $items = [
            self::request("List {$names->pluralVariable}", 'GET', ['v1', $route], tests: self::status(200), query: ['page' => '1', 'per_page' => '10'],
                description: 'Paginated: `data` + `meta` (total, per_page, current_page, last_page).'),
            self::request("Create {$label}", 'POST', ['v1', $route], self::json($create), [
                ...self::status(201), '',
                "pm.collectionVariables.set(\"{$names->variable}Id\", String(pm.response.json().data.id));",
            ], description: 'JSON body. Fields outside the controller\'s permitted params are ignored; invalid values give `422` with `errors`.'),
            self::request("Get {$label}", 'GET', ['v1', $route, $id], tests: self::status(200)),
            self::request("Replace {$label} (PUT)", 'PUT', ['v1', $route, $id], self::json($replace), self::status(200),
                description: 'PUT and PATCH both update: only the fields sent are changed.'),
            self::request("Update {$label} (PATCH)", 'PATCH', ['v1', $route, $id], self::json($patch), self::status(200)),
        ];

        foreach ($images as $field) {
            $items[] = self::request("Upload {$label} {$field}", 'POST', ['v1', $route, $id], (object) [
                'mode' => 'formdata',
                'formdata' => [
                    (object) ['key' => '_method', 'value' => 'PATCH', 'type' => 'text', 'description' => 'PHP only parses multipart bodies on POST, so the update is sent as POST + _method=PATCH.'],
                    (object) ['key' => $field, 'type' => 'file', 'src' => self::SAMPLE_IMAGE, 'description' => 'JPEG, PNG, WebP or GIF. In Postman pick a file (e.g. postman/' . self::SAMPLE_IMAGE . ').'],
                ],
            ], [
                ...self::status(200), '',
                "pm.test(\"Has {$field}_url\", function () {",
                "    pm.expect(pm.response.json().data.{$field}_url).to.include(\"/uploads/\");",
                '});',
            ], description: 'multipart/form-data. The image is re-encoded to WebP with a thumbnail; the previous file is deleted.');
        }

        $items[] = self::request("Delete {$label}", 'DELETE', ['v1', $route, $id], tests: self::status(204),
            description: 'Removes the record' . ($images === [] ? '.' : ' and its image files.'));
        $items[] = self::request("Get deleted {$label} (404)", 'GET', ['v1', $route, $id], tests: self::status(404));

        return (object) [
            'name' => self::folderName($names),
            'description' => "/v1/{$route} — generated by `php racin g postman {$names->model}`. Run **Auth → Login (admin)** first; "
                . "writes need the `{$names->table}.manage` permission (admins have every permission).",
            'item' => $items,
        ];
    }

    /**
     * @param list<string> $path
     * @param list<string> $tests
     * @param array<string, string> $query
     */
    private static function request(string $name, string $method, array $path, ?stdClass $body = null, array $tests = [], array $query = [], string $description = ''): stdClass
    {
        $headers = [(object) ['key' => 'Accept', 'value' => 'application/json']];

        if ($body?->mode === 'raw') {
            $headers[] = (object) ['key' => 'Content-Type', 'value' => 'application/json'];
        }

        $url = (object) [
            'raw' => '{{baseUrl}}/' . implode('/', $path) . ($query === [] ? '' : '?' . http_build_query($query)),
            'host' => ['{{baseUrl}}'],
            'path' => $path,
        ];

        if ($query !== []) {
            $url->query = array_map(static fn (string $k, string $v): stdClass => (object) ['key' => $k, 'value' => $v], array_keys($query), $query);
        }

        $request = (object) [
            'method' => $method,
            'header' => $headers,
            'url' => $url,
            'auth' => (object) ['type' => 'bearer', 'bearer' => [(object) ['key' => 'token', 'value' => '{{adminToken}}', 'type' => 'string']]],
        ];

        if ($body !== null) {
            $request->body = $body;
        }

        if ($description !== '') {
            $request->description = $description;
        }

        return (object) [
            'name' => $name,
            'event' => [(object) ['listen' => 'test', 'script' => (object) ['type' => 'text/javascript', 'exec' => $tests]]],
            'request' => $request,
            'response' => [],
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function json(array $data): stdClass
    {
        return (object) [
            'mode' => 'raw',
            'raw' => json_encode((object) $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
            'options' => (object) ['raw' => (object) ['language' => 'json']],
        ];
    }

    /**
     * @return list<string>
     */
    private static function status(int $code): array
    {
        return ["pm.test(\"Status is {$code}\", function () {", "    pm.expect(pm.response.code).to.eql({$code});", '});'];
    }

    private static function read(string $file): stdClass
    {
        $collection = json_decode((string) file_get_contents($file), false, 512, JSON_THROW_ON_ERROR);

        if (!$collection instanceof stdClass || !isset($collection->item) || !is_array($collection->item)) {
            throw new RuntimeException("[{$file}] is not a Postman collection.");
        }

        return $collection;
    }

    private static function write(string $file, stdClass $collection): void
    {
        file_put_contents($file, json_encode($collection, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
    }
}
