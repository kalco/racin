<?php

declare(strict_types=1);

namespace Racin\Console;

use Racin\Application;
use RuntimeException;
use Throwable;

final class Kernel
{
    /** @var list<class-string<Command>> */
    private const array COMMANDS = [
        Commands\ServeCommand::class,
        Commands\RouteListCommand::class,
        Commands\RouteCacheCommand::class,
        Commands\RouteClearCommand::class,
        Commands\ConfigCacheCommand::class,
        Commands\ConfigClearCommand::class,
        Commands\CacheClearCommand::class,
        Commands\KeyGenerateCommand::class,
        Commands\DbImportCommand::class,
        Commands\MakeControllerCommand::class,
        Commands\MakeModelCommand::class,
        Commands\MakeMiddlewareCommand::class,
        Commands\MakeCommandCommand::class,
        Commands\MakeMigrationCommand::class,
        Commands\MigrateCommand::class,
        Commands\MigrateRollbackCommand::class,
        Commands\MigrateFreshCommand::class,
        Commands\MigrateStatusCommand::class,
        Commands\DbSeedCommand::class,
        Commands\QueueWorkCommand::class,
        Commands\QueueFailedCommand::class,
        Commands\QueueRetryCommand::class,
        Commands\QueueForgetCommand::class,
        Commands\QueueFlushCommand::class,
        Commands\MailTestCommand::class,
        Commands\WebSocketServeCommand::class,
        Commands\InitCommand::class,
        Commands\GenerateCommand::class,
        Commands\DestroyCommand::class,
    ];

    /** @var array<string, Command>|null */
    private ?array $commands = null;

    public function __construct(private readonly Application $app, private ?Output $output = null)
    {
    }

    /**
     * @param list<string> $argv full argv including the script name
     */
    public function run(array $argv): int
    {
        $output = $this->output ??= new Output();
        $name = $argv[1] ?? 'list';
        $input = Input::parse(array_slice($argv, 2));

        if (in_array($name, ['list', '--help', '-h'], true)) {
            $this->list($output);

            return Command::SUCCESS;
        }

        if ($name === '--version' || $name === '-V') {
            $output->line('Racin Framework ' . Application::VERSION);

            return Command::SUCCESS;
        }

        if ($name === 'help') {
            return $this->help($input->argument(0) ?? 'list', $output);
        }

        $command = $this->find($name);

        if ($command === null) {
            $output->error("Command \"{$name}\" is not defined. Run \"php racin list\".");

            return Command::FAILURE;
        }

        try {
            return $command->handle($input, $output);
        } catch (Throwable $e) {
            $output->error($e::class . ': ' . $e->getMessage());

            if ($this->app->isDebug()) {
                $output->error($e->getFile() . ':' . $e->getLine());
            }

            return Command::FAILURE;
        }
    }

    /**
     * A command by name or alias.
     */
    public function find(string $name): ?Command
    {
        foreach ($this->commands() as $command) {
            if ($command->name() === $name || in_array($name, $command->aliases(), true)) {
                return $command;
            }
        }

        return null;
    }

    /**
     * @return array<string, Command>
     */
    public function commands(): array
    {
        if ($this->commands !== null) {
            return $this->commands;
        }

        $this->commands = [];
        $classes = [...self::COMMANDS, ...(array) $this->app->config()->get('app.commands', [])];

        foreach ($classes as $class) {
            $command = $this->app->make($class);

            if (!$command instanceof Command) {
                throw new RuntimeException("[{$class}] must extend " . Command::class . '.');
            }

            $this->commands[$command->name()] = $command;
        }

        // Commands without a "group:" prefix first, then alphabetical.
        uksort($this->commands, static fn (string $a, string $b): int => [str_contains($a, ':'), $a] <=> [str_contains($b, ':'), $b]);

        return $this->commands;
    }

    private function list(Output $output): void
    {
        $output->line('Racin Framework ' . $output->color(Application::VERSION, '32'));
        $output->line();
        $output->comment('Usage:');
        $output->line('  php racin <command> [arguments] [--options]');
        $output->line();
        $output->comment('Available commands:');

        $commands = $this->commands();
        $width = max(array_map('strlen', array_keys($commands))) + 2;
        $group = null;

        foreach ($commands as $name => $command) {
            $prefix = str_contains($name, ':') ? strstr($name, ':', true) : '';

            if ($prefix !== $group && $prefix !== '') {
                $output->comment(' ' . $prefix);
            }

            $group = $prefix;
            $aliases = $command->aliases() === [] ? '' : ' (' . implode(', ', $command->aliases()) . ')';
            $output->line('  ' . $output->color(str_pad($name . $aliases, $width + 6), '32') . $command->description());
        }
    }

    private function help(string $name, Output $output): int
    {
        $command = $this->find($name);

        if ($command === null) {
            $this->list($output);

            return Command::SUCCESS;
        }

        $output->comment('Description:');
        $output->line('  ' . $command->description());
        $output->line();
        $output->comment('Usage:');
        $output->line('  php racin ' . ($command->usage() !== '' ? $command->usage() : $command->name()));

        return Command::SUCCESS;
    }
}
