<?php

declare(strict_types=1);

namespace Racin\Console\Commands;

use Racin\Console\Command;
use Racin\Console\Input;
use Racin\Console\Output;
use Racin\Mail\Mailer;
use Racin\Mail\Message;

final class MailTestCommand extends Command
{
    protected string $name = 'mail:test';

    protected string $description = 'Send a test email with the configured mailer';

    protected string $usage = 'mail:test <to@example.com>';

    public function __construct(private readonly Mailer $mailer)
    {
    }

    public function handle(Input $input, Output $output): int
    {
        $to = $input->argument(0);

        if ($to === null || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            $output->error('Usage: php racin ' . $this->usage);

            return self::FAILURE;
        }

        $this->mailer->send((new Message())
            ->to($to)
            ->subject('Racin test email ✓')
            ->html('<h1>It works</h1><p>This message was sent by <strong>php racin mail:test</strong> at ' . date('Y-m-d H:i:s') . '.</p>'));

        $output->info("Test email sent to {$to}.");

        return self::SUCCESS;
    }
}
