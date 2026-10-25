<?php

declare(strict_types=1);

namespace Racin\Console;

/**
 * Base console command. Dependencies are injected into the constructor.
 *
 *     final class PruneTokens extends Command
 *     {
 *         protected string $name = 'tokens:prune';
 *         protected string $description = 'Delete expired tokens';
 *
 *         public function handle(Input $input, Output $output): int
 *         {
 *             $output->info('Done.');
 *             return self::SUCCESS;
 *         }
 *     }
 */
abstract class Command
{
    public const int SUCCESS = 0;

    public const int FAILURE = 1;

    protected string $name = '';

    /** @var list<string> other names for the command ("g" for "generate") */
    protected array $aliases = [];

    protected string $description = '';

    /** Shown by "php racin help <command>", e.g. "<name> [--force]" */
    protected string $usage = '';

    abstract public function handle(Input $input, Output $output): int;

    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return list<string>
     */
    public function aliases(): array
    {
        return $this->aliases;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function usage(): string
    {
        return $this->usage;
    }
}
