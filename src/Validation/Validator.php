<?php

declare(strict_types=1);

namespace Racin\Validation;

use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use Racin\Database\Connection;
use Racin\Http\UploadedFile;
use Racin\Support\Arr;
use Racin\Translation\Translator;

/**
 * Rule based input validator.
 *
 * Fields are optional unless a "required*" rule is present: when a value is
 * missing, null or an empty string only the implicit rules run. Validation of
 * a field stops at its first failing rule.
 *
 *     $data = Validator::make($input, [
 *         'email'       => 'required|email|max:255|unique:users,email',
 *         'age'         => 'nullable|integer|between:13,130',
 *         'tags'        => 'array|max:10',
 *         'tags.*'      => 'string|max:30',
 *         'password'    => ['required', 'string', 'min:8', 'confirmed'],
 *     ])->validate();
 */
class Validator
{
    /** Rules that run even when the value is missing or empty. */
    protected const array IMPLICIT = ['required', 'required_if', 'required_unless', 'required_with', 'required_without', 'present', 'accepted'];

    protected const array IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/bmp'];

    /** Detected MIME type => extensions accepted by the "mimes" rule. */
    protected const array MIME_EXTENSIONS = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/gif' => ['gif'],
        'image/webp' => ['webp'],
        'image/avif' => ['avif'],
        'image/bmp' => ['bmp'],
        'application/pdf' => ['pdf'],
        'text/plain' => ['txt'],
        'text/csv' => ['csv'],
        'application/zip' => ['zip'],
    ];

    /** Rules that only change behaviour and never fail on their own. */
    protected const array MARKERS = ['nullable', 'sometimes', 'bail'];

    protected const array MESSAGES = [
        'required' => 'The :attribute field is required.',
        'required_if' => 'The :attribute field is required when :other is :value.',
        'required_unless' => 'The :attribute field is required unless :other is in :values.',
        'required_with' => 'The :attribute field is required when :values is present.',
        'required_without' => 'The :attribute field is required when :values is not present.',
        'present' => 'The :attribute field must be present.',
        'accepted' => 'The :attribute must be accepted.',
        'string' => 'The :attribute must be a string.',
        'integer' => 'The :attribute must be an integer.',
        'numeric' => 'The :attribute must be a number.',
        'boolean' => 'The :attribute field must be true or false.',
        'array' => 'The :attribute must be an array.',
        'list' => 'The :attribute must be a list.',
        'email' => 'The :attribute must be a valid email address.',
        'url' => 'The :attribute must be a valid URL.',
        'ip' => 'The :attribute must be a valid IP address.',
        'ipv4' => 'The :attribute must be a valid IPv4 address.',
        'ipv6' => 'The :attribute must be a valid IPv6 address.',
        'uuid' => 'The :attribute must be a valid UUID.',
        'alpha' => 'The :attribute may only contain letters.',
        'alpha_num' => 'The :attribute may only contain letters and numbers.',
        'alpha_dash' => 'The :attribute may only contain letters, numbers, dashes and underscores.',
        'date' => 'The :attribute is not a valid date.',
        'date_format' => 'The :attribute does not match the format :format.',
        'before' => 'The :attribute must be a date before :date.',
        'after' => 'The :attribute must be a date after :date.',
        'in' => 'The selected :attribute is invalid.',
        'not_in' => 'The selected :attribute is invalid.',
        'regex' => 'The :attribute format is invalid.',
        'not_regex' => 'The :attribute format is invalid.',
        'confirmed' => 'The :attribute confirmation does not match.',
        'same' => 'The :attribute and :other must match.',
        'different' => 'The :attribute and :other must be different.',
        'digits' => 'The :attribute must be :digits digits.',
        'digits_between' => 'The :attribute must be between :min and :max digits.',
        'json' => 'The :attribute must be a valid JSON string.',
        'starts_with' => 'The :attribute must start with one of the following: :values.',
        'ends_with' => 'The :attribute must end with one of the following: :values.',
        'unique' => 'The :attribute has already been taken.',
        'exists' => 'The selected :attribute is invalid.',
        'file' => 'The :attribute must be a file.',
        'image' => 'The :attribute must be an image.',
        'mimetypes' => 'The :attribute must be a file of type: :values.',
        'mimes' => 'The :attribute must be a file of type: :values.',
        'dimensions' => 'The :attribute has invalid image dimensions.',
        'gt' => 'The :attribute must be greater than :value.',
        'gte' => 'The :attribute must be greater than or equal to :value.',
        'lt' => 'The :attribute must be less than :value.',
        'lte' => 'The :attribute must be less than or equal to :value.',
        'min.numeric' => 'The :attribute must be at least :min.',
        'min.string' => 'The :attribute must be at least :min characters.',
        'min.array' => 'The :attribute must have at least :min items.',
        'min.file' => 'The :attribute must be at least :min kilobytes.',
        'max.numeric' => 'The :attribute may not be greater than :max.',
        'max.string' => 'The :attribute may not be greater than :max characters.',
        'max.array' => 'The :attribute may not have more than :max items.',
        'max.file' => 'The :attribute may not be greater than :max kilobytes.',
        'between.numeric' => 'The :attribute must be between :min and :max.',
        'between.string' => 'The :attribute must be between :min and :max characters.',
        'between.array' => 'The :attribute must have between :min and :max items.',
        'between.file' => 'The :attribute must be between :min and :max kilobytes.',
        'size.numeric' => 'The :attribute must be :size.',
        'size.string' => 'The :attribute must be :size characters.',
        'size.array' => 'The :attribute must contain :size items.',
        'size.file' => 'The :attribute must be :size kilobytes.',
    ];

    /** @var array<string, array{callback: Closure, message: string, implicit: bool}> */
    protected static array $extensions = [];

    /** @var (Closure(): Connection)|null */
    protected static ?Closure $connectionResolver = null;

    /** Translates "validation.<rule>" messages and "validation.attributes.<field>" names when set. */
    protected static ?Translator $translator = null;

    /** @var array<string, list<string>> */
    protected array $errors = [];

    protected bool $ran = false;

    /** @var list<string> concrete attribute names that had rules (wildcards expanded) */
    protected array $validatedKeys = [];

    /**
     * @param array<string, mixed> $data
     * @param array<string, string|list<string|Rule|Closure>> $rules
     * @param array<string, string> $messages "field.rule", "field" or "rule" => message
     * @param array<string, string> $attributes field => display name
     */
    public function __construct(
        protected array $data,
        protected array $rules,
        protected array $messages = [],
        protected array $attributes = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string|list<string|Rule|Closure>> $rules
     * @param array<string, string> $messages
     * @param array<string, string> $attributes
     */
    public static function make(array $data, array $rules, array $messages = [], array $attributes = []): static
    {
        return new static($data, $rules, $messages, $attributes);
    }

    /**
     * Register a named rule: callback(mixed $value, list<string> $parameters, array $data, string $attribute): bool
     */
    public static function extend(string $name, Closure $callback, string $message, bool $implicit = false): void
    {
        static::$extensions[$name] = ['callback' => $callback, 'message' => $message, 'implicit' => $implicit];
    }

    /**
     * Provide the database connection used by the "unique" and "exists" rules.
     *
     * @param (Closure(): Connection)|null $resolver
     */
    public static function setConnectionResolver(?Closure $resolver): void
    {
        static::$connectionResolver = $resolver;
    }

    public static function setTranslator(?Translator $translator): void
    {
        static::$translator = $translator;
    }

    public function passes(): bool
    {
        if (!$this->ran) {
            $this->run();
        }

        return $this->errors === [];
    }

    public function fails(): bool
    {
        return !$this->passes();
    }

    /**
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        $this->passes();

        return $this->errors;
    }

    /**
     * Throw a ValidationException on failure, otherwise return the validated data.
     *
     * @return array<string, mixed>
     */
    public function validate(): array
    {
        if ($this->fails()) {
            throw new ValidationException($this->errors);
        }

        return $this->validated();
    }

    /**
     * Only the input keys that had rules.
     *
     * @return array<string, mixed>
     */
    public function validated(): array
    {
        $this->passes();
        $result = [];

        foreach ($this->validatedKeys as $key) {
            if (Arr::has($this->data, $key)) {
                Arr::set($result, $key, Arr::get($this->data, $key));
            }
        }

        return $result;
    }

    protected function run(): void
    {
        $this->ran = true;
        $this->errors = [];
        $this->validatedKeys = [];

        foreach ($this->rules as $key => $rules) {
            $rules = $this->parseRules($rules);

            foreach ($this->expandKey((string) $key) as $attribute) {
                $this->validatedKeys[] = $attribute;
                $this->validateAttribute($attribute, $rules);
            }
        }
    }

    /**
     * @param list<array{0: string|Rule|Closure, 1: list<string>}> $rules
     */
    protected function validateAttribute(string $attribute, array $rules): void
    {
        $present = Arr::has($this->data, $attribute);
        $value = Arr::get($this->data, $attribute);
        $names = array_map(static fn (array $r): string => is_string($r[0]) ? $r[0] : '', $rules);

        if (!$present && in_array('sometimes', $names, true)) {
            return;
        }

        $empty = $this->isEmpty($value);

        foreach ($rules as [$rule, $parameters]) {
            if (is_string($rule) && in_array($rule, self::MARKERS, true)) {
                continue;
            }

            if ($empty && !$this->isImplicit($rule)) {
                continue;
            }

            if ($rule instanceof Rule) {
                if (!$rule->passes($attribute, $value, $this->data)) {
                    $this->addError($attribute, $rule->message(), []);

                    return;
                }

                continue;
            }

            if ($rule instanceof Closure) {
                $failure = null;
                $rule($attribute, $value, function (string $message) use (&$failure): void {
                    $failure = $message;
                });

                if ($failure !== null) {
                    $this->addError($attribute, $failure, []);

                    return;
                }

                continue;
            }

            if (!$this->check($rule, $attribute, $value, $parameters, $names)) {
                $this->addError($attribute, $this->message($attribute, $rule, $value, $names), $this->replacements($rule, $parameters, $value));

                return;
            }
        }
    }

    /**
     * @param list<string> $parameters
     * @param list<string> $names all rule names on the attribute
     */
    protected function check(string $rule, string $attribute, mixed $value, array $parameters, array $names): bool
    {
        if (isset(static::$extensions[$rule])) {
            return (bool) (static::$extensions[$rule]['callback'])($value, $parameters, $this->data, $attribute);
        }

        return match ($rule) {
            'required' => !$this->isEmpty($value),
            'required_if' => !in_array((string) $this->scalar($this->field($parameters, 0)), array_slice($parameters, 1), true) || !$this->isEmpty($value),
            'required_unless' => in_array((string) $this->scalar($this->field($parameters, 0)), array_slice($parameters, 1), true) || !$this->isEmpty($value),
            'required_with' => !$this->anyFilled($parameters) || !$this->isEmpty($value),
            'required_without' => $this->allFilled($parameters) || !$this->isEmpty($value),
            'present' => Arr::has($this->data, $attribute),
            'accepted' => in_array($value, ['yes', 'on', '1', 1, true, 'true'], true),
            'string' => is_string($value),
            'integer' => !is_bool($value) && filter_var($value, FILTER_VALIDATE_INT) !== false,
            'numeric' => !is_bool($value) && is_numeric($value),
            'boolean' => in_array($value, [true, false, 0, 1, '0', '1', 'true', 'false'], true),
            'array' => is_array($value) && ($parameters === [] || array_diff(array_map('strval', array_keys($value)), $parameters) === []),
            'list' => is_array($value) && array_is_list($value),
            'email' => is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'url' => is_string($value) && filter_var($value, FILTER_VALIDATE_URL) !== false
                && in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), $parameters ?: ['http', 'https'], true),
            'ip' => is_string($value) && filter_var($value, FILTER_VALIDATE_IP) !== false,
            'ipv4' => is_string($value) && filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false,
            'ipv6' => is_string($value) && filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false,
            'uuid' => is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $value) === 1,
            'alpha' => is_string($value) && preg_match('/^[\pL\pM]+$/uD', $value) === 1,
            'alpha_num' => (is_string($value) || is_int($value)) && preg_match('/^[\pL\pM\pN]+$/uD', (string) $value) === 1,
            'alpha_dash' => (is_string($value) || is_int($value)) && preg_match('/^[\pL\pM\pN_-]+$/uD', (string) $value) === 1,
            'date' => $this->toDate($value) !== null,
            'date_format' => $this->matchesFormat($value, $parameters[0] ?? ''),
            'before' => $this->compareDates($value, $parameters[0] ?? '', static fn (int $a, int $b): bool => $a < $b),
            'after' => $this->compareDates($value, $parameters[0] ?? '', static fn (int $a, int $b): bool => $a > $b),
            'min' => $this->size($value, $names) >= (float) ($parameters[0] ?? 0),
            'max' => $this->size($value, $names) <= (float) ($parameters[0] ?? 0),
            'between' => ($size = $this->size($value, $names)) >= (float) ($parameters[0] ?? 0) && $size <= (float) ($parameters[1] ?? 0),
            'size' => $this->size($value, $names) == (float) ($parameters[0] ?? 0),
            'gt' => is_numeric($value) && $value > (float) ($parameters[0] ?? 0),
            'gte' => is_numeric($value) && $value >= (float) ($parameters[0] ?? 0),
            'lt' => is_numeric($value) && $value < (float) ($parameters[0] ?? 0),
            'lte' => is_numeric($value) && $value <= (float) ($parameters[0] ?? 0),
            'in' => is_scalar($value) && in_array((string) $this->scalar($value), $parameters, true),
            'not_in' => !is_scalar($value) || !in_array((string) $this->scalar($value), $parameters, true),
            'regex' => (is_string($value) || is_numeric($value)) && preg_match($parameters[0] ?? '', (string) $value) === 1,
            'not_regex' => (is_string($value) || is_numeric($value)) && preg_match($parameters[0] ?? '', (string) $value) === 0,
            'confirmed' => Arr::has($this->data, $attribute . '_confirmation') && Arr::get($this->data, $attribute . '_confirmation') === $value,
            'same' => $this->field($parameters, 0) === $value,
            'different' => $this->field($parameters, 0) !== $value,
            'digits' => is_scalar($value) && preg_match('/^\d+$/D', (string) $value) === 1 && strlen((string) $value) === (int) ($parameters[0] ?? 0),
            'digits_between' => is_scalar($value) && preg_match('/^\d+$/D', (string) $value) === 1
                && strlen((string) $value) >= (int) ($parameters[0] ?? 0) && strlen((string) $value) <= (int) ($parameters[1] ?? 0),
            'json' => is_string($value) && json_validate($value),
            'starts_with' => is_string($value) && array_filter($parameters, static fn (string $p): bool => str_starts_with($value, $p)) !== [],
            'ends_with' => is_string($value) && array_filter($parameters, static fn (string $p): bool => str_ends_with($value, $p)) !== [],
            'unique' => !$this->recordExists($attribute, $value, $parameters, true),
            'exists' => $this->recordExists($attribute, $value, $parameters, false),
            'file' => $value instanceof UploadedFile && $value->isValid(),
            // Raster images only: SVG is excluded because it can carry scripts.
            'image' => $value instanceof UploadedFile && $value->isValid() && in_array($value->mimeType(), self::IMAGE_MIMES, true),
            'mimetypes' => $value instanceof UploadedFile && $value->isValid() && $this->mimeAllowed((string) $value->mimeType(), $parameters),
            'mimes' => $value instanceof UploadedFile && $value->isValid() && $this->extensionAllowed((string) $value->mimeType(), $parameters),
            'dimensions' => $value instanceof UploadedFile && $value->isValid() && $this->dimensionsAllowed($value, $parameters),
            default => throw new InvalidArgumentException("Unknown validation rule [{$rule}]."),
        };
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * @param string|list<string|Rule|Closure> $rules
     * @return list<array{0: string|Rule|Closure, 1: list<string>}>
     */
    protected function parseRules(string|array $rules): array
    {
        $rules = is_string($rules) ? explode('|', $rules) : $rules;
        $parsed = [];

        foreach ($rules as $rule) {
            if ($rule instanceof Rule || $rule instanceof Closure) {
                $parsed[] = [$rule, []];
                continue;
            }

            $rule = trim((string) $rule);

            if ($rule === '') {
                continue;
            }

            [$name, $parameters] = array_pad(explode(':', $rule, 2), 2, null);
            $name = strtolower($name);

            if ($parameters === null) {
                $parameters = [];
            } elseif (in_array($name, ['regex', 'not_regex', 'date_format', 'before', 'after'], true)) {
                $parameters = [$parameters];
            } else {
                $parameters = array_map('trim', str_getcsv($parameters, ',', '"', '\\'));
            }

            $parsed[] = [$name, $parameters];
        }

        return $parsed;
    }

    /**
     * Expand "items.*.id" into the concrete keys present in the data.
     *
     * @return list<string>
     */
    protected function expandKey(string $key): array
    {
        if (!str_contains($key, '*')) {
            return [$key];
        }

        $keys = [''];

        foreach (explode('.', $key) as $segment) {
            $next = [];

            foreach ($keys as $prefix) {
                if ($segment !== '*') {
                    $next[] = $prefix === '' ? $segment : $prefix . '.' . $segment;
                    continue;
                }

                $value = $prefix === '' ? $this->data : Arr::get($this->data, $prefix);

                if (is_array($value)) {
                    foreach (array_keys($value) as $index) {
                        $next[] = $prefix === '' ? (string) $index : $prefix . '.' . $index;
                    }
                }
            }

            $keys = $next;
        }

        return $keys;
    }

    protected function isImplicit(string|Rule|Closure $rule): bool
    {
        if (!is_string($rule)) {
            return false;
        }

        return in_array($rule, self::IMPLICIT, true) || (static::$extensions[$rule]['implicit'] ?? false);
    }

    protected function isEmpty(mixed $value): bool
    {
        return match (true) {
            $value === null, $value === [] => true,
            is_string($value) => trim($value) === '',
            $value instanceof UploadedFile => !$value->isValid(),
            default => false,
        };
    }

    /**
     * @param list<string> $parameters
     */
    protected function field(array $parameters, int $index): mixed
    {
        return Arr::get($this->data, $parameters[$index] ?? '');
    }

    protected function scalar(mixed $value): mixed
    {
        return match (true) {
            $value === true => 'true',
            $value === false => 'false',
            default => is_scalar($value) ? (string) $value : $value,
        };
    }

    /**
     * @param list<string> $fields
     */
    protected function anyFilled(array $fields): bool
    {
        foreach ($fields as $field) {
            if (!$this->isEmpty(Arr::get($this->data, $field))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $fields
     */
    protected function allFilled(array $fields): bool
    {
        foreach ($fields as $field) {
            if ($this->isEmpty(Arr::get($this->data, $field))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Size used by min/max/between/size: number, string length, item count or kilobytes.
     *
     * @param list<string> $names
     */
    protected function size(mixed $value, array $names): float
    {
        return match ($this->sizeType($value, $names)) {
            'numeric' => (float) $value,
            'array' => (float) count($value),
            'file' => $value->size / 1024,
            default => (float) mb_strlen(is_scalar($value) ? (string) $value : ''),
        };
    }

    /**
     * @param list<string> $names
     */
    protected function sizeType(mixed $value, array $names): string
    {
        return match (true) {
            is_array($value) => 'array',
            $value instanceof UploadedFile => 'file',
            is_numeric($value) && (in_array('numeric', $names, true) || in_array('integer', $names, true)) => 'numeric',
            default => 'string',
        };
    }

    protected function toDate(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }

        if (!is_string($value) || trim($value) === '' || strtotime($value) === false) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    protected function matchesFormat(mixed $value, string $format): bool
    {
        if (!is_string($value) || $format === '') {
            return false;
        }

        $date = DateTimeImmutable::createFromFormat('!' . $format, $value);

        return $date !== false && $date->format($format) === $value;
    }

    /**
     * @param Closure(int, int): bool $compare
     */
    protected function compareDates(mixed $value, string $other, Closure $compare): bool
    {
        $date = $this->toDate($value);
        $target = Arr::has($this->data, $other) ? $this->toDate(Arr::get($this->data, $other)) : $this->toDate($other);

        return $date !== null && $target !== null && $compare($date->getTimestamp(), $target->getTimestamp());
    }

    /**
     * @param list<string> $allowed e.g. image/png, image/*
     */
    protected function mimeAllowed(string $mime, array $allowed): bool
    {
        foreach ($allowed as $pattern) {
            if ($pattern === $mime || (str_ends_with($pattern, '/*') && str_starts_with($mime, substr($pattern, 0, -1)))) {
                return true;
            }
        }

        return false;
    }

    /**
     * mimes:jpg,png — judged by the detected content type, not the client's file name.
     *
     * @param list<string> $extensions
     */
    protected function extensionAllowed(string $mime, array $extensions): bool
    {
        $extensions = array_map('strtolower', $extensions);

        return array_intersect(self::MIME_EXTENSIONS[$mime] ?? [], $extensions) !== [];
    }

    /**
     * dimensions:min_width=100,max_width=4000,min_height=100,max_height=4000,width=,height=
     *
     * @param list<string> $constraints
     */
    protected function dimensionsAllowed(UploadedFile $file, array $constraints): bool
    {
        $size = @getimagesize($file->path);

        if ($size === false) {
            return false;
        }

        [$width, $height] = $size;

        foreach ($constraints as $constraint) {
            [$name, $limit] = array_pad(explode('=', $constraint, 2), 2, '');
            $limit = (int) $limit;

            $ok = match (trim($name)) {
                'min_width' => $width >= $limit,
                'max_width' => $width <= $limit,
                'min_height' => $height >= $limit,
                'max_height' => $height <= $limit,
                'width' => $width === $limit,
                'height' => $height === $limit,
                default => throw new InvalidArgumentException("Unknown dimensions constraint [{$name}]."),
            };

            if (!$ok) {
                return false;
            }
        }

        return true;
    }

    /**
     * unique:table[,column[,ignoreValue[,ignoreColumn]]]   exists:table[,column]
     *
     * @param list<string> $parameters
     */
    protected function recordExists(string $attribute, mixed $value, array $parameters, bool $unique): bool
    {
        if (static::$connectionResolver === null) {
            // Without a database the rule cannot be evaluated; fail loudly rather than silently pass.
            throw new InvalidArgumentException('The unique/exists rules need a database connection resolver.');
        }

        $table = $parameters[0] ?? throw new InvalidArgumentException('The unique/exists rules need a table name.');
        $column = ($parameters[1] ?? '') !== '' ? $parameters[1] : (string) preg_replace('/^.*\./', '', $attribute);

        if (!is_scalar($value)) {
            return false;
        }

        $query = (static::$connectionResolver)()->table($table)->where($column, $value);

        if ($unique && ($parameters[2] ?? '') !== '' && strtolower($parameters[2]) !== 'null') {
            $query->where(($parameters[3] ?? '') !== '' ? $parameters[3] : 'id', '<>', $parameters[2]);
        }

        return $query->exists();
    }

    /**
     * @param list<string> $names
     */
    protected function message(string $attribute, string $rule, mixed $value, array $names): string
    {
        $generic = preg_replace('/\.\d+(?=\.|$)/', '.*', $attribute);

        foreach (["{$attribute}.{$rule}", "{$generic}.{$rule}", $attribute, (string) $generic, $rule] as $key) {
            if (isset($this->messages[$key])) {
                return $this->messages[$key];
            }
        }

        $key = in_array($rule, ['min', 'max', 'between', 'size'], true) ? $rule . '.' . $this->sizeType($value, $names) : $rule;

        if (static::$translator?->has('validation.' . $key)) {
            return static::$translator->get('validation.' . $key);
        }

        if (isset(static::$extensions[$rule])) {
            return static::$extensions[$rule]['message'];
        }

        return self::MESSAGES[$key] ?? (static::$translator?->has('validation.invalid') ? static::$translator->get('validation.invalid') : 'The :attribute is invalid.');
    }

    /**
     * @param list<string> $parameters
     * @return array<string, string>
     */
    protected function replacements(string $rule, array $parameters, mixed $value): array
    {
        $first = $parameters[0] ?? '';

        return match ($rule) {
            'min' => [':min' => $first],
            'max' => [':max' => $first],
            'between', 'digits_between' => [':min' => $first, ':max' => $parameters[1] ?? ''],
            'size' => [':size' => $first],
            'digits' => [':digits' => $first],
            'date_format' => [':format' => $first],
            'before', 'after' => [':date' => $first],
            'same', 'different' => [':other' => $this->displayName($first)],
            'required_if' => [':other' => $this->displayName($first), ':value' => implode(', ', array_slice($parameters, 1))],
            'required_unless' => [':other' => $this->displayName($first), ':values' => implode(', ', array_slice($parameters, 1))],
            'required_with', 'required_without' => [':values' => implode(' / ', array_map($this->displayName(...), $parameters))],
            'gt', 'gte', 'lt', 'lte' => [':value' => $first],
            default => [':values' => implode(', ', $parameters)],
        };
    }

    /**
     * @param array<string, string> $replacements
     */
    protected function addError(string $attribute, string $message, array $replacements): void
    {
        $replacements[':attribute'] = $this->displayName($attribute);

        $this->errors[$attribute][] = strtr($message, $replacements);
    }

    protected function displayName(string $attribute): string
    {
        if (isset($this->attributes[$attribute])) {
            return $this->attributes[$attribute];
        }

        $generic = (string) preg_replace('/\.\d+(?=\.|$)/', '.*', $attribute);

        foreach (array_unique([$attribute, $generic]) as $key) {
            if (static::$translator?->has('validation.attributes.' . $key)) {
                return static::$translator->get('validation.attributes.' . $key);
            }
        }

        return str_replace(['_', '.'], ' ', $attribute);
    }
}
