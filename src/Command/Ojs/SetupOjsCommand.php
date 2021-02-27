<?php

namespace ScieloScrapping\Command\Ojs;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;

class SetupOjsCommand extends Command
{
    protected static $defaultName = 'ojs:setup-ojs';

    protected function configure()
    {
        $this->setDescription('Setup OJS, only use if you don\'t have OJS installed.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->commandExist('git')) {
            $output->writeln('<error>Command git is necessary. Install git, please!</error>');
            return Command::FAILURE;
        }

        $helper = $this->getHelper('question');

        do {
            $question = new Question('Path to download OJS, default [' . getcwd() . '/ojs]: ', 'ojs');
            $path = $helper->ask($input, $output, $question);
            if (strpos($path, '/') !== 0) {
                $path = getcwd() . DIRECTORY_SEPARATOR . $path;
            }
            if (is_dir($path)) {
                $yes = 'n';
                $output->writeln('<info>' . $path . '</info> is a existing directory.');
                continue;
            } elseif (is_file($path)) {
                $yes = 'n';
                $output->writeln('<info>' . $path . '</info> is a file.');
                continue;
            }
            $question = new ChoiceQuestion(
                'Confirm the path <info>' . $path . '</info>? ',
                ['y' => 'yes', 'n' => 'no']
            );
            $question->setErrorMessage('Response [%s] is invalid, only "y" or "n".');
            $yes = $helper->ask($input, $output, $question);
        } while ($yes != 'y');

        $question = new Question('Version of OJS: ');
        $question->setValidator(function ($value) {
            $return = shell_exec('git ls-remote --tags https://github.com/pkp/ojs.git refs/tags/' . escapeshellarg($value));
            if (!$return) {
                throw new \Exception('Invalid version');
            }
            return $value;
        });
        $version = $helper->ask($input, $output, $question);

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        $output->writeln('Cloning [<info>' . $version . '</info>] into [<info>' . $path . '</info>]...');
        shell_exec(
            'git clone --progress -b "' . $version . '" --single-branch --depth 1 --recurse-submodules -j 4 https://github.com/pkp/ojs ' . $path
        );
        $output->writeln('Installing <info>Composer</info> dependencies...');
        shell_exec(
            "composer --working-dir=$path/lib/pkp install && " .
            "composer --working-dir=$path/plugins/paymethod/paypal install && " .
            "composer --working-dir=$path/plugins/generic/citationStyleLanguage install"
        );
        $output->writeln('Installing <info>OJS</info>...');
        shell_exec('cp ' . $path . '/config.TEMPLATE.inc.php ' . $path . '/config.inc.php');
        require_once($path . '/tools/install.php');

        return Command::SUCCESS;
    }

    protected function commandExist(string $cmd)
    {
        $return = shell_exec(sprintf("which %s", escapeshellarg($cmd)));
        return !empty($return);
    }
}
