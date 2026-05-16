<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\Runtime\Bridge;

use Symfony\Contracts\Service\ResetInterface;

final readonly class RequestResetter implements ResetInterface
{
    /** @var list<PriorityResetInterface> */
    private array $sorted;

    /**
     * @param PriorityResetInterface[] $resetters
     */
    public function __construct(iterable $resetters)
    {
        $sorted = \iterator_to_array($resetters);
        \usort($sorted, static fn(
            PriorityResetInterface $a,
            PriorityResetInterface $b,
        ): int => $b->getPriority() <=> $a->getPriority());
        $this->sorted = $sorted;
    }

    public function reset(): void
    {
        foreach ($this->sorted as $resetter) {
            $resetter->reset();
        }
    }
}
