<?php

declare(strict_types=1);

namespace Racin\Http;

class JsonResponse extends Response
{
    public const int DEFAULT_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;

    protected mixed $data = null;

    /**
     * @param array<string, string> $headers
     */
    public function __construct(mixed $data = null, int $status = 200, array $headers = [], protected int $flags = self::DEFAULT_FLAGS)
    {
        parent::__construct('', $status, $headers);

        $this->header('Content-Type', 'application/json; charset=utf-8');
        $this->setData($data);
    }

    public function setData(mixed $data): static
    {
        $this->data = $data;
        $this->content = json_encode($data, $this->flags | JSON_THROW_ON_ERROR);

        return $this;
    }

    public function data(): mixed
    {
        return $this->data;
    }

    /**
     * The JSON body decoded into arrays (handy in tests).
     */
    public function decoded(): mixed
    {
        return json_decode($this->content, true, 512, JSON_THROW_ON_ERROR);
    }
}
