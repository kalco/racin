<?php

declare(strict_types=1);

namespace Racin\Console\Generators;

use InvalidArgumentException;
use Racin\Support\Str;

/**
 * One field of a generator command: name:type[{args}][?][:unique|:index|:nullable]
 *
 *   title:string{200}    body:text?     price:decimal{10,2}     published:boolean
 *   user:references      cover:image    status:enum{draft,live}  slug:string:unique
 */
final class Field
{
    public const array TYPES = [
        'string', 'text', 'longtext', 'integer', 'bigint', 'smallint', 'tinyint', 'decimal', 'float',
        'boolean', 'date', 'datetime', 'json', 'enum', 'uuid', 'references', 'image',
    ];

    private const array ALIASES = [
        'int' => 'integer', 'bool' => 'boolean', 'double' => 'float', 'timestamp' => 'datetime',
        'belongs_to' => 'references', 'belongsto' => 'references', 'ref' => 'references', 'long_text' => 'longtext',
    ];

    /**
     * @param list<string> $arguments values inside {} (length, precision/scale, enum values)
     */
    private function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly bool $nullable,
        public readonly bool $unique,
        public readonly bool $index,
        public readonly array $arguments,
    ) {
    }

    public static function parse(string $spec): self
    {
        $parts = explode(':', trim($spec));
        $name = array_shift($parts);
        $nullable = false;

        if (str_ends_with($name, '?')) {
            $nullable = true;
            $name = substr($name, 0, -1);
        }

        if (preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1) {
            throw new InvalidArgumentException("Invalid field name [{$name}] in \"{$spec}\" (use snake_case).");
        }

        $typeSpec = strtolower(array_shift($parts) ?? 'string');

        if (str_ends_with($typeSpec, '?')) {
            $nullable = true;
            $typeSpec = substr($typeSpec, 0, -1);
        }

        $arguments = [];

        if (preg_match('/^([a-z_]+)\{([^}]*)\}$/', $typeSpec, $m) === 1) {
            $typeSpec = $m[1];
            $arguments = array_values(array_filter(array_map('trim', explode(',', $m[2])), static fn (string $a): bool => $a !== ''));
        }

        $type = self::ALIASES[$typeSpec] ?? $typeSpec;

        if (!in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException("Unknown field type [{$typeSpec}] in \"{$spec}\". Types: " . implode(', ', self::TYPES) . '.');
        }

        if ($type === 'enum' && $arguments === []) {
            throw new InvalidArgumentException("Enum field [{$name}] needs values: {$name}:enum{draft,live}.");
        }

        $modifiers = array_map('strtolower', $parts);

        foreach ($modifiers as $modifier) {
            if (!in_array($modifier, ['unique', 'index', 'nullable', 'null'], true)) {
                throw new InvalidArgumentException("Unknown modifier [{$modifier}] in \"{$spec}\" (unique, index, nullable).");
            }
        }

        return new self(
            $name,
            $type,
            $nullable || in_array('nullable', $modifiers, true) || in_array('null', $modifiers, true) || $type === 'image',
            in_array('unique', $modifiers, true),
            in_array('index', $modifiers, true),
            $arguments,
        );
    }

    /**
     * @param list<string> $specs
     * @return list<self>
     */
    public static function parseAll(array $specs): array
    {
        $fields = array_map(self::parse(...), $specs);
        $columns = array_map(static fn (self $f): string => $f->column(), $fields);

        if (count($columns) !== count(array_unique($columns))) {
            throw new InvalidArgumentException('Duplicate field names.');
        }

        return $fields;
    }

    /**
     * Database column ("user" references → "user_id").
     */
    public function column(): string
    {
        return $this->type === 'references' ? $this->name . '_id' : $this->name;
    }

    public function isReference(): bool
    {
        return $this->type === 'references';
    }

    public function isImage(): bool
    {
        return $this->type === 'image';
    }

    /**
     * Table a reference points to ("user" → "users").
     */
    public function referencedTable(): string
    {
        return Str::plural($this->name);
    }

    /**
     * Model class a reference points to ("user" → "User").
     */
    public function referencedModel(): string
    {
        return Str::studly($this->name);
    }

    /**
     * Blueprint line for a migration.
     */
    public function migrationLine(string $table): string
    {
        $column = var_export($this->column(), true);
        $arg = fn (int $i, string $default): string => $this->arguments[$i] ?? $default;

        $line = match ($this->type) {
            'string' => "\$table->string({$column}, " . (int) $arg(0, '255') . ')',
            'text' => "\$table->text({$column})",
            'longtext' => "\$table->longText({$column})",
            'integer' => "\$table->integer({$column})",
            'bigint' => "\$table->bigInteger({$column})",
            'smallint' => "\$table->smallInteger({$column})",
            'tinyint' => "\$table->tinyInteger({$column})",
            'decimal' => "\$table->decimal({$column}, " . (int) $arg(0, '10') . ', ' . (int) $arg(1, '2') . ')',
            'float' => "\$table->double({$column})",
            'boolean' => "\$table->boolean({$column})",
            'date' => "\$table->date({$column})",
            'datetime' => "\$table->dateTime({$column})",
            'json' => "\$table->json({$column})",
            'enum' => "\$table->enum({$column}, [" . implode(', ', array_map(static fn (string $v): string => var_export($v, true), $this->arguments)) . '])',
            'uuid' => "\$table->uuid({$column})",
            'references' => "\$table->foreignId({$column})",
            'image' => "\$table->string({$column}, 255)",
        };

        // Images are optional: the controller only sets the column when a file was uploaded.
        if ($this->nullable || $this->isImage()) {
            $line .= '->nullable()';
        } elseif ($this->type === 'boolean') {
            $line .= '->default(false)';
        }

        if ($this->type === 'references') {
            $line .= "->constrained('" . $this->referencedTable() . "')" . ($this->nullable ? '->nullOnDelete()' : '->cascadeOnDelete()');
        }

        if ($this->unique) {
            $line .= '->unique()';
        } elseif ($this->index && $this->type !== 'references') {
            $line .= '->index()';
        }

        return $line . ';';
    }

    /**
     * Validation rule for the model's $rules ("{id}" lets unique ignore the record itself).
     */
    public function rule(string $table): string
    {
        if ($this->isImage()) {
            return 'nullable|string|max:255';
        }

        $rules = [$this->nullable ? 'nullable' : ($this->type === 'boolean' ? 'boolean' : 'required')];

        $typeRule = match ($this->type) {
            'string' => 'string|max:' . (int) ($this->arguments[0] ?? 255),
            'text', 'longtext' => 'string',
            'integer', 'bigint', 'smallint', 'tinyint' => 'integer',
            'decimal', 'float' => 'numeric',
            'boolean' => $this->nullable ? 'boolean' : '',
            'date' => 'date_format:Y-m-d',
            'datetime' => 'date',
            'json' => 'array',
            'enum' => 'in:' . implode(',', $this->arguments),
            'uuid' => 'uuid',
            'references' => 'integer|exists:' . $this->referencedTable() . ',id',
        };

        if ($typeRule !== '') {
            $rules[] = $typeRule;
        }

        if ($this->unique) {
            $rules[] = "unique:{$table}," . $this->column() . ',{id}';
        }

        return implode('|', $rules);
    }

    /**
     * Model cast, if the type needs one.
     */
    public function cast(): ?string
    {
        return match ($this->type) {
            'integer', 'bigint', 'smallint', 'tinyint', 'references' => 'int',
            'decimal', 'float' => 'float',
            'boolean' => 'bool',
            'json' => 'array',
            default => null,
        };
    }

    /**
     * Example value for generated tests.
     */
    public function sample(string $suffix = ''): mixed
    {
        return match ($this->type) {
            'string' => $this->unique ? "{$this->name}-{$suffix}" : 'Sample ' . str_replace('_', ' ', $this->name),
            'text', 'longtext' => 'Sample text',
            'integer', 'bigint', 'smallint', 'tinyint' => 7,
            'decimal', 'float' => 19.99,
            'boolean' => true,
            'date' => '2026-01-15',
            'datetime' => '2026-01-15 10:30:00',
            'json' => ['key' => 'value'],
            'enum' => $this->arguments[0],
            'uuid' => '123e4567-e89b-12d3-a456-426614174000',
            'references', 'image' => null,
        };
    }
}
