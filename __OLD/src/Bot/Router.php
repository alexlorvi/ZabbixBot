<?php
declare(strict_types=1);

namespace ZbxBot\Bot;

final class Router
{
    /** @var list<array{string,callable,Access}> */
    private array $routes = [];

    public function add(string $pattern, callable $handler, Access $access = Access::User): self
    {
        $this->routes[] = [$pattern, $handler, $access];
        return $this;
    }

    /** @return array{callable,Access,array<int|string,string>}|null */
    public function match(string $text): ?array
    {
        foreach ($this->routes as [$pattern, $handler, $access]) {
            if (preg_match($pattern, $text, $m) === 1) {
                return [$handler, $access, $m];
            }
        }
        return null;
    }
}
