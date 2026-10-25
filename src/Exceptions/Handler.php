<?php

declare(strict_types=1);

namespace Racin\Exceptions;

use Psr\Log\LoggerInterface;
use Racin\Http\Exceptions\HttpException;
use Racin\Http\JsonResponse;
use Racin\Http\Request;
use Racin\Http\Response;
use Racin\Translation\Translator;
use Racin\Validation\ValidationException;
use Throwable;

/**
 * Turns exceptions into JSON responses and logs the ones that matter.
 *
 * Error body: {"message": "...", "errors": {...}} (+ exception details when debug is on).
 * Extend this class and bind it in a service provider to customise.
 */
class Handler
{
    /** @var list<class-string<Throwable>> never logged */
    protected array $dontReport = [];

    public function __construct(
        protected bool $debug = false,
        protected ?LoggerInterface $logger = null,
        protected ?Translator $translator = null,
    ) {
    }

    public function shouldReport(Throwable $e): bool
    {
        foreach ($this->dontReport as $type) {
            if ($e instanceof $type) {
                return false;
            }
        }

        return !$e instanceof HttpException || $e->getStatusCode() >= 500;
    }

    public function report(Throwable $e): void
    {
        if (!$this->shouldReport($e) || $this->logger === null) {
            return;
        }

        try {
            $this->logger->error($e->getMessage(), ['exception' => $e]);
        } catch (Throwable) {
            // Logging must never break error handling.
        }
    }

    public function render(?Request $request, Throwable $e): Response
    {
        $status = $e instanceof HttpException ? $e->getStatusCode() : 500;
        $headers = $e instanceof HttpException ? $e->getHeaders() : [];

        $message = $e instanceof HttpException || $this->debug ? $e->getMessage() : HttpException::MESSAGES[500];

        // Default status messages ("Not found.", ...) are translated via lang/<locale>/http.php.
        if (($e instanceof HttpException || !$this->debug) && $message === (HttpException::MESSAGES[$status] ?? null)
            && $this->translator?->has("http.{$status}")) {
            $message = $this->translator->get("http.{$status}");
        }

        $body = ['message' => $message];

        if ($e instanceof ValidationException) {
            $body['errors'] = $e->errors();
        }

        if ($this->debug && $status >= 500) {
            $body['exception'] = $e::class;
            $body['file'] = $e->getFile() . ':' . $e->getLine();
            $body['trace'] = array_slice(array_map(
                static fn (array $frame): string => ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '')
                    . (isset($frame['file']) ? ' @ ' . $frame['file'] . ':' . ($frame['line'] ?? 0) : ''),
                $e->getTrace(),
            ), 0, 25);

            if ($e->getPrevious() !== null) {
                $body['previous'] = $e->getPrevious()::class . ': ' . $e->getPrevious()->getMessage();
            }
        }

        try {
            return new JsonResponse($body, $status, $headers);
        } catch (Throwable) {
            // e.g. invalid UTF-8 in an exception message
            return new JsonResponse(['message' => HttpException::MESSAGES[500]], $status, $headers);
        }
    }

    /**
     * Report + render; used for errors that escape the HTTP kernel.
     */
    public function handle(?Request $request, Throwable $e): Response
    {
        $this->report($e);

        return $this->render($request, $e);
    }
}
