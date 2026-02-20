<?php

declare(strict_types=1);

namespace App\Command\Integration;

use App\Integration\Infrastructure\Http\Shopware\ShopwareAdminApiClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'integration:shopware:ping',
    description: 'Ping Shopware Admin API (health-check + optional version).'
)]
final class ShopwarePingCommand extends Command
{
    public function __construct(
        private readonly ShopwareAdminApiClient $client,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $health = $this->client->requestOrFail('GET', '/api/_info/health-check');
        $this->printResult($output, 'health-check', $health['status'], $health['body']);

        // Optional: version endpoint; 401 is fine (reachable but auth required)
        $version = $this->client->request('GET', '/api/_info/version', [], [], [], true);
        $this->printResult($output, 'version', $version['status'], $version['body']);

        return Command::SUCCESS;
    }

    private function printResult(OutputInterface $output, string $label, int $status, string $body): void
    {
        if ($status >= 200 && $status < 300) {
            $output->writeln(sprintf('<info>%s: %d (OK)</info>', $label, $status));
            return;
        }

        if ($status === 401 || $status === 403) {
            $output->writeln(sprintf('<comment>%s: %d (reachable, auth required)</comment>', $label, $status));
            return;
        }

        $snippet = $this->snippet($body);
        $output->writeln(sprintf('<error>%s: %d</error>', $label, $status));
        if ($snippet !== '') {
            $output->writeln('  ' . $snippet);
        }
    }

    private function snippet(mixed $text, int $max = 250): string
    {
        if (is_array($text)) {
            $text = json_encode($text, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        } elseif (!is_string($text)) {
            $text = (string) $text;
        }

        $t = trim($text);
        if ($t === '') {
            return '';
        }

        if (mb_strlen($t) <= $max) {
            return $t;
        }

        return mb_substr($t, 0, $max) . '…';
    }
}
