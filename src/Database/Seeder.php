<?php

declare(strict_types=1);

namespace Racin\Database;

use Racin\Container\Container;
use Racin\Validation\ValidationException;
use RuntimeException;

/**
 * Database seeders live in database/seeders (namespace Database\Seeders) and are
 * run with "php racin db:seed [--class=Name]". Dependencies are constructor-injected.
 */
abstract class Seeder
{
    protected ?Container $container = null;

    /** @var (callable(string): void)|null */
    protected $output = null;

    abstract public function run(): void;

    /**
     * Run other seeders from this one.
     *
     * @param class-string<Seeder> ...$seeders
     */
    public function call(string ...$seeders): void
    {
        foreach ($seeders as $class) {
            $seeder = $this->container?->make($class) ?? new $class();
            $seeder->setContainer($this->container)->setOutput($this->output);

            if ($this->output !== null) {
                ($this->output)('Seeding: ' . $class);
            }

            $seeder->run();
        }
    }

    /**
     * Insert the rows of a data file through the model, so its casts, $rules and callbacks apply:
     *
     *     $this->seedFromFile(Blog::class, __DIR__ . '/data/blogs.json');              // skipped while the table has rows
     *     $this->seedFromFile(Tag::class, __DIR__ . '/data/tags.csv', uniqueBy: 'slug');  // re-runnable: insert or update by slug
     *
     * .json files hold a list of objects; .csv files have a header row (empty cells are left out, so the column default applies).
     * Everything runs in one transaction; a row that fails validation stops the seed with its row number.
     * Returns the number of rows written.
     *
     * @param class-string<Model> $model
     * @param string|list<string>|null $uniqueBy columns that identify a row
     */
    protected function seedFromFile(string $model, string $file, string|array|null $uniqueBy = null): int
    {
        $table = (new $model())->getTable();

        if ($uniqueBy === null && $model::query()->exists()) {
            $this->say("  {$table} already has rows, " . basename($file) . ' skipped (empty the table or use uniqueBy).');

            return 0;
        }

        $rows = self::readRows($file);

        DB::transaction(function () use ($model, $rows, $uniqueBy, $file): void {
            foreach ($rows as $index => $row) {
                $keys = $uniqueBy === null ? [] : array_intersect_key($row, array_flip((array) $uniqueBy));
                $record = $keys === [] ? null : $model::query()->where($keys)->first();
                $record ??= new $model();

                try {
                    $record->forceFill($row)->save();
                } catch (ValidationException $e) {
                    $errors = array_map(static fn (string $field, array $messages): string => "{$field}: " . implode(' ', $messages), array_keys($e->errors()), $e->errors());

                    throw new RuntimeException(basename($file) . ' row ' . ($index + 1) . ': ' . implode('; ', $errors), 0, $e);
                }
            }
        });

        $this->say("  {$table}: " . count($rows) . ' row(s) from ' . basename($file));

        return count($rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected static function readRows(string $file): array
    {
        if (!is_file($file)) {
            throw new RuntimeException("Seed file [{$file}] not found.");
        }

        if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'csv') {
            $handle = fopen($file, 'r') ?: throw new RuntimeException("Cannot read [{$file}].");
            $header = null;
            $rows = [];

            while (($line = fgetcsv($handle, escape: '')) !== false) {
                if ($line === [null]) {
                    continue; // blank line
                }

                if ($header === null) {
                    $header = array_map(static fn (?string $h): string => trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $h)), $line);
                    continue;
                }

                // Empty cells are left out: the column default (or NULL) applies.
                $rows[] = array_filter(array_combine($header, array_pad($line, count($header), null)), static fn (?string $v): bool => $v !== null && $v !== '');
            }

            fclose($handle);

            return $rows;
        }

        $rows = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($rows) || !array_is_list($rows)) {
            throw new RuntimeException("[{$file}] must contain a JSON list of objects.");
        }

        return $rows;
    }

    protected function say(string $line): void
    {
        if ($this->output !== null) {
            ($this->output)($line);
        }
    }

    public function setContainer(?Container $container): static
    {
        $this->container = $container;

        return $this;
    }

    /**
     * @param (callable(string): void)|null $output
     */
    public function setOutput(?callable $output): static
    {
        $this->output = $output;

        return $this;
    }
}
