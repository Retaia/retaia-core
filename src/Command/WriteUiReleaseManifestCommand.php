<?php

namespace App\Command;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;

#[AsCommand(name: 'app:release:write-ui-manifest', description: 'Write UI release manifest JSON for updater ping/download flow.')]
final class WriteUiReleaseManifestCommand extends Command
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('ui-version', null, InputOption::VALUE_REQUIRED, 'Released UI version (example: 1.0.3)')
            ->addOption('asset-url', null, InputOption::VALUE_REQUIRED, 'Download URL for the released UI artifact')
            ->addOption('sha256', null, InputOption::VALUE_REQUIRED, 'SHA-256 checksum of the released UI artifact')
            ->addOption('notes-url', null, InputOption::VALUE_OPTIONAL, 'Optional release notes URL', '')
            ->addOption('channel', null, InputOption::VALUE_OPTIONAL, 'Release channel', 'stable')
            ->addOption(
                'output',
                null,
                InputOption::VALUE_OPTIONAL,
                'Manifest output path (absolute or project-relative)',
                'public/releases/latest.json'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $version = trim((string) $input->getOption('ui-version'));
        $assetUrl = trim((string) $input->getOption('asset-url'));
        $sha256 = strtolower(trim((string) $input->getOption('sha256')));
        $notesUrl = trim((string) $input->getOption('notes-url'));
        $channel = trim((string) $input->getOption('channel'));
        $outputPath = trim((string) $input->getOption('output'));

        if ($version === '' || $assetUrl === '' || $sha256 === '' || $channel === '' || $outputPath === '') {
            $output->writeln('<error>Missing required option(s). Use --ui-version, --asset-url, --sha256 and --output.</error>');

            return Command::INVALID;
        }

        if (!preg_match('/^[a-f0-9]{64}$/', $sha256)) {
            $output->writeln('<error>Option --sha256 must be a 64-character hexadecimal SHA-256.</error>');

            return Command::INVALID;
        }

        $resolvedPath = $this->resolveOutputPath($outputPath);
        $manifest = [
            'version' => $version,
            'channel' => $channel,
            'asset_url' => $assetUrl,
            'sha256' => $sha256,
            'notes_url' => $notesUrl,
            'generated_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM),
        ];

        $filesystem = new Filesystem();
        $filesystem->mkdir(dirname($resolvedPath));
        $filesystem->dumpFile(
            $resolvedPath,
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n"
        );

        $output->writeln(sprintf('<info>UI release manifest written to %s</info>', $resolvedPath));

        return Command::SUCCESS;
    }

    private function resolveOutputPath(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return rtrim($this->projectDir, '/').'/'.ltrim($path, '/');
    }
}
