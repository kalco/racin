<?php

declare(strict_types=1);

namespace Racin\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Racin\Database\DatabaseManager;
use Racin\Validation\Rule;
use Racin\Validation\ValidationException;
use Racin\Validation\Validator;

final class Uppercase implements Rule
{
    public function passes(string $attribute, mixed $value, array $data): bool
    {
        return is_string($value) && strtoupper($value) === $value;
    }

    public function message(): string
    {
        return 'The :attribute must be uppercase.';
    }
}

final class ValidatorTest extends TestCase
{
    protected function tearDown(): void
    {
        Validator::setConnectionResolver(null);
    }

    /**
     * @return iterable<string, array{0: mixed, 1: string, 2: bool}>
     */
    public static function ruleCases(): iterable
    {
        yield 'required empty string' => ['  ', 'required', false];
        yield 'required zero' => [0, 'required', true];
        yield 'integer string' => ['42', 'integer', true];
        yield 'integer float' => ['4.2', 'integer', false];
        yield 'integer bool' => [true, 'integer', false];
        yield 'numeric' => ['4.2', 'numeric', true];
        yield 'boolean' => ['false', 'boolean', true];
        yield 'boolean invalid' => ['yes', 'boolean', false];
        yield 'email' => ['a@b.co', 'email', true];
        yield 'email invalid' => ['a@', 'email', false];
        yield 'url' => ['https://x.io/a', 'url', true];
        yield 'url javascript' => ['javascript:alert(1)', 'url', false];
        yield 'uuid' => ['123e4567-e89b-12d3-a456-426614174000', 'uuid', true];
        yield 'alpha_dash' => ['ab-c_1', 'alpha_dash', true];
        yield 'alpha unicode' => ['Ćirilica', 'alpha', true];
        yield 'min string' => ['ab', 'string|min:3', false];
        yield 'max string multibyte' => ['ššš', 'string|max:3', true];
        yield 'min numeric' => ['5', 'integer|min:6', false];
        yield 'between numeric' => [7, 'numeric|between:1,10', true];
        yield 'size array' => [[1, 2], 'array|size:2', true];
        yield 'in' => ['b', 'in:a,b', true];
        yield 'not_in' => ['b', 'not_in:a,b', false];
        yield 'regex with pipe' => ['ab', ['regex:/^(ab|cd)$/'], true];
        yield 'date' => ['2026-10-19', 'date', true];
        yield 'date_format' => ['2026-02-30', 'date_format:Y-m-d', false];
        yield 'after' => ['2030-01-01', 'after:2026-01-01', true];
        yield 'digits' => ['01234', 'digits:5', true];
        yield 'json' => ['{"a":1}', 'json', true];
        yield 'json invalid' => ['{a:1}', 'json', false];
        yield 'starts_with' => ['img_1', 'starts_with:img_,pic_', true];
        yield 'gt' => [5, 'gt:5', false];
        yield 'optional empty skips other rules' => ['', 'email|min:10', true];
        yield 'array allowed keys' => [['a' => 1, 'z' => 2], 'array:a,b', false];
    }

    #[DataProvider('ruleCases')]
    public function testRules(mixed $value, string|array $rules, bool $passes): void
    {
        self::assertSame($passes, Validator::make(['field' => $value], ['field' => $rules])->passes());
    }

    public function testErrorsMessagesAndValidatedData(): void
    {
        $validator = Validator::make(
            ['name' => '', 'email' => 'x', 'age' => '15', 'extra' => 'dropped', 'items' => [['id' => 1], ['id' => 'a']]],
            [
                'name' => 'required|string',
                'email' => 'required|email',
                'age' => 'integer|min:18',
                'items' => 'array',
                'items.*.id' => 'required|integer',
            ],
            ['email.email' => 'Bad email.'],
            ['age' => 'your age'],
        );

        self::assertTrue($validator->fails());
        self::assertSame([
            'name' => ['The name field is required.'],
            'email' => ['Bad email.'],
            'age' => ['The your age must be at least 18.'],
            'items.1.id' => ['The items 1 id must be an integer.'],
        ], $validator->errors());
    }

    public function testValidatedReturnsOnlyRuleKeys(): void
    {
        $data = Validator::make(
            ['name' => 'Ana', 'is_admin' => true, 'tags' => ['a', 'b']],
            ['name' => 'required|string', 'tags.*' => 'string', 'nickname' => 'sometimes|string'],
        )->validate();

        self::assertSame(['name' => 'Ana', 'tags' => ['a', 'b']], $data);
    }

    public function testConditionalRules(): void
    {
        $rules = [
            'password' => 'sometimes|required|confirmed',
            'current_password' => 'required_with:password',
            'company' => 'required_if:type,business',
        ];

        self::assertTrue(Validator::make(['type' => 'person'], $rules)->passes());
        self::assertSame(
            ['password', 'current_password', 'company'],
            array_keys(Validator::make(['type' => 'business', 'password' => 'a', 'password_confirmation' => 'b'], $rules)->errors()),
        );
    }

    public function testCustomRulesClosuresAndExtensions(): void
    {
        Validator::extend('even', fn (mixed $value): bool => (int) $value % 2 === 0, 'The :attribute must be even.');

        $validator = Validator::make(['code' => 'abc', 'n' => 3, 'c' => 'x'], [
            'code' => ['required', new Uppercase()],
            'n' => 'even',
            'c' => [function (string $attribute, mixed $value, \Closure $fail): void {
                if ($value === 'x') {
                    $fail("The {$attribute} cannot be x.");
                }
            }],
        ]);

        self::assertSame([
            'code' => ['The code must be uppercase.'],
            'n' => ['The n must be even.'],
            'c' => ['The c cannot be x.'],
        ], $validator->errors());
    }

    public function testValidateThrows422(): void
    {
        try {
            Validator::make([], ['email' => 'required'])->validate();
            self::fail('Expected exception');
        } catch (ValidationException $e) {
            self::assertSame(422, $e->getStatusCode());
            self::assertSame(['email' => ['The email field is required.']], $e->errors());
        }
    }

    public function testUniqueAndExists(): void
    {
        $db = new DatabaseManager(['default' => 'sqlite', 'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']]]);
        $db->connection()->unprepared('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT)');
        $db->connection()->table('users')->insert([['id' => 1, 'email' => 'a@x.io'], ['id' => 2, 'email' => 'b@x.io']]);
        Validator::setConnectionResolver(fn () => $db->connection());

        self::assertTrue(Validator::make(['email' => 'new@x.io'], ['email' => 'unique:users'])->passes());
        self::assertFalse(Validator::make(['email' => 'a@x.io'], ['email' => 'unique:users,email'])->passes());
        self::assertTrue(Validator::make(['email' => 'a@x.io'], ['email' => 'unique:users,email,1'])->passes(), 'ignores own id');
        self::assertTrue(Validator::make(['user_id' => 2], ['user_id' => 'exists:users,id'])->passes());
        self::assertFalse(Validator::make(['user_id' => 9], ['user_id' => 'exists:users,id'])->passes());
    }
}
