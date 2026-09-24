<?php

declare(strict_types=1);

namespace Danielgnh\StatamicMcp;

use InvalidArgumentException;
use Laravel\Mcp\Server\Tool;

class ToolRegistry
{
    /** @var list<class-string<Tool>> */
    protected array $tools;

    /**
     * @param  array<int, class-string<Tool>>  $tools
     */
    public function __construct(array $tools = [])
    {
        $this->tools = array_values($tools);
    }

    /**
     * @param  class-string<Tool>  ...$tools
     */
    public function add(string ...$tools): static
    {
        foreach ($tools as $tool) {
            $this->ensureTool($tool);

            if (! in_array($tool, $this->tools, true)) {
                $this->tools[] = $tool;
            }
        }

        return $this;
    }

    /**
     * @param  class-string<Tool>  $tool
     * @param  class-string<Tool>  $with
     */
    public function replace(string $tool, string $with): static
    {
        $this->ensureTool($with);

        $index = array_search($tool, $this->tools, true);

        if ($index === false) {
            throw new InvalidArgumentException("Cannot replace [{$tool}]: it is not a registered tool.");
        }

        $this->tools[$index] = $with;

        $this->tools = array_values(array_unique($this->tools));

        return $this;
    }

    /**
     * @param  class-string<Tool>  ...$tools
     */
    public function remove(string ...$tools): static
    {
        $this->tools = array_values(array_diff($this->tools, $tools));

        return $this;
    }

    /**
     * @return list<class-string<Tool>>
     */
    public function all(): array
    {
        return $this->tools;
    }

    protected function ensureTool(string $tool): void
    {
        if (! is_subclass_of($tool, Tool::class)) {
            throw new InvalidArgumentException("[{$tool}] is not a ".Tool::class.' subclass.');
        }
    }
}
