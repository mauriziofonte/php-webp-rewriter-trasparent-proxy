<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Fixtures\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command written with the API subset that symfony/console 5.4 to 8.x share
 * (plan section 4): the name is set in configure(), not through the removed
 * $defaultName property, and execute() returns an int.
 */
final class GreetCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('fixture:greet')
            ->setDescription('Greets someone.')
            ->addArgument('who', InputArgument::REQUIRED, 'Who to greet')
            ->addOption('fail', null, InputOption::VALUE_NONE, 'Exit with the failure code');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $who = $input->getArgument('who');
        $output->writeln('Hello ' . (is_string($who) ? $who : ''));

        return $input->getOption('fail') === true ? Command::FAILURE : Command::SUCCESS;
    }
}
