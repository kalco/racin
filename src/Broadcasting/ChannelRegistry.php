<?php

declare(strict_types=1);

namespace Racin\Broadcasting;

use Closure;

/**
 * Who may subscribe to which channel. Defined in routes/channels.php:
 *
 *     $channels->channel('user.{id}', fn (User $user, string $id): bool => $user->id === (int) $id);
 *     $channels->channel('admin.orders', fn (User $user): bool => $user->hasPermission('orders.view-all'));
 *
 * Channels named "public.*" are open to everyone, including guests.
 * Any other channel without a matching definition is refused.
 */
final class ChannelRegistry
{
    /** @var list<array{regex: string, callback: Closure}> */
    private array $channels = [];

    public function channel(string $pattern, Closure $authorize): void
    {
        $regex = '#^' . preg_replace_callback(
            '/\\\{([A-Za-z_][A-Za-z0-9_]*)\\\}/',
            static fn (array $m): string => '(?P<' . $m[1] . '>[^.]+)',
            preg_quote($pattern, '#'),
        ) . '$#';

        $this->channels[] = ['regex' => $regex, 'callback' => $authorize];
    }

    public function authorize(mixed $user, string $channel): bool
    {
        if (preg_match('/^[A-Za-z0-9_.:-]{1,190}$/', $channel) !== 1) {
            return false;
        }

        if (str_starts_with($channel, 'public.')) {
            return true;
        }

        if ($user === null) {
            return false;
        }

        foreach ($this->channels as $definition) {
            if (preg_match($definition['regex'], $channel, $matches) === 1) {
                $parameters = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

                return (bool) ($definition['callback'])($user, ...array_values($parameters));
            }
        }

        return false;
    }
}
