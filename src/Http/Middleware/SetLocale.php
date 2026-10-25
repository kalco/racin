<?php

declare(strict_types=1);

namespace Racin\Http\Middleware;

use Closure;
use Racin\Http\Middleware;
use Racin\Http\Request;
use Racin\Http\Response;
use Racin\Translation\Translator;

/**
 * Picks the response language from "?lang=mk" or the Accept-Language header
 * (q-values honoured), limited to the supported locales, and sets Content-Language.
 */
final class SetLocale implements Middleware
{
    public function __construct(private readonly Translator $translator)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->fromQuery($request) ?? $this->fromHeader((string) $request->header('Accept-Language', ''));

        // Always set it: in long-running processes the previous request's language must not leak.
        $this->translator->setLocale($locale ?? $this->translator->defaultLocale());

        $request->setAttribute('locale', $this->translator->getLocale());

        return $next($request)->header('Content-Language', $this->translator->getLocale());
    }

    private function fromQuery(Request $request): ?string
    {
        $lang = $request->query('lang');

        return is_string($lang) && $this->translator->isSupported(strtolower($lang)) ? strtolower($lang) : null;
    }

    private function fromHeader(string $header): ?string
    {
        $candidates = [];

        foreach (explode(',', $header) as $index => $part) {
            [$tag, $params] = array_pad(explode(';', trim($part), 2), 2, '');
            $tag = strtolower(trim($tag));
            $quality = preg_match('/q=([0-9.]+)/', $params, $m) === 1 ? (float) $m[1] : 1.0;

            if ($tag !== '' && $quality > 0) {
                $candidates[] = [$tag, $quality, $index];
            }
        }

        usort($candidates, static fn (array $a, array $b): int => [$b[1], $a[2]] <=> [$a[1], $b[2]]);

        foreach ($candidates as [$tag]) {
            foreach ([$tag, explode('-', $tag)[0]] as $locale) {
                if ($this->translator->isSupported($locale)) {
                    return $locale;
                }
            }
        }

        return null;
    }
}
