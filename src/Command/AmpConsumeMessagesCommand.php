<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\Command;

use PRSW\AmphpBundle\Bridge\Symfony\Messenger\AmpClock;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\Command\ConsumeMessagesCommand;

#[AsCommand(name: 'messenger:consume', description: 'Consume messages (async AMPHP-aware)')]
final class AmpConsumeMessagesCommand extends ConsumeMessagesCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        Clock::set(new AmpClock(new NativeClock()));

        return parent::execute($input, $output);
    }
}
