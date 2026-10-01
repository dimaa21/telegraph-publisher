<?php

declare(strict_types=1);

namespace App;

final class Article
{
    public function __construct(
        public readonly string $title,
        public readonly string $author,
        public readonly string $content,
    ) {
    }
}
