<?php

declare(strict_types=1);

namespace Racin\Console\Generators;

use InvalidArgumentException;
use Racin\Support\Str;

/**
 * Rails-style naming for a resource: "BlogPost", "blog_post" or "blog_posts" all give
 * model BlogPost, table blog_posts, BlogPostsController, route "blog-posts", $blogPost.
 */
final class Names
{
    public readonly string $model;

    public readonly string $table;

    public readonly string $variable;

    public readonly string $pluralStudly;

    public readonly string $pluralVariable;

    public readonly string $controller;

    public readonly string $route;

    public readonly string $serializer;

    public function __construct(string $name)
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $name) !== 1) {
            throw new InvalidArgumentException("Invalid resource name [{$name}] (letters, digits, underscores).");
        }

        $snakeSingular = Str::singular(Str::snake($name));

        $this->model = Str::studly($snakeSingular);
        $this->table = Str::plural($snakeSingular);
        $this->variable = Str::camel($snakeSingular);
        $this->pluralStudly = Str::studly($this->table);
        $this->pluralVariable = Str::camel($this->table);
        $this->controller = $this->pluralStudly . 'Controller';
        $this->route = str_replace('_', '-', $this->table);
        $this->serializer = $this->model . 'Serializer';
    }
}
