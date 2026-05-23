<?php

declare(strict_types=1);

namespace ETechFlow\NextDayEligibility\Console\Command;

use ETechFlow\NextDayEligibility\Model\ShippingMethodAvailability;
use Magento\Shipping\Model\Config\Source\Allmethods;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Print every shipping method code visible to NDE's admin multiselect.
 *
 * Solves the "I have 6 shipping options in my admin but only 3 show up in
 * NDE's dropdown" problem. The multiselect is sourced from Magento's
 * `Allmethods` source, which only enumerates carriers that register through
 * the standard `Magento\Shipping\Model\Config\Source\Allmethods` pipeline.
 *
 * Custom shipping modules — Hyvä Shipping Page, third-party rate engines,
 * marketplace shippers — register their methods at runtime (in
 * `collectRates()`) without appearing in Allmethods. To target those
 * methods you must use NDE's *Additional Method Codes* free-text field
 * and paste the `carrier_method` string. This command shows you EVERY
 * code that Allmethods returns AND prompts you to grab the missing
 * codes from a real cart's rate list (via dev/template-hints or the
 * `sales_order_shipping_method` column on a recent order).
 *
 * Run:
 *   bin/magento etechflow:nde:list-methods
 *   bin/magento etechflow:nde:list-methods --format=csv
 */
class ListMethodsCommand extends Command
{
    /**
     * @param Allmethods                 $allmethods
     * @param ShippingMethodAvailability $availability
     */
    public function __construct(
        private readonly Allmethods $allmethods,
        private readonly ShippingMethodAvailability $availability
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('etechflow:nde:list-methods')
            ->setDescription('List every shipping method code visible to NDE\'s Next Day Methods dropdown.')
            ->addOption(
                'format',
                'f',
                \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED,
                'Output format: table (default) or csv',
                'table'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = (string) $input->getOption('format');

        // Allmethods::toOptionArray() returns an optgroup-shaped array:
        //   [ ['label' => 'Flat Rate', 'value' => [ ['value' => 'flatrate_flatrate', 'label' => 'Fixed'] ]], ... ]
        $optgroups = $this->allmethods->toOptionArray();
        $rows      = [];

        foreach ($optgroups as $group) {
            $carrierLabel = (string) ($group['label'] ?? '?');
            $methods      = $group['value'] ?? [];
            if (!is_array($methods)) {
                continue;
            }
            foreach ($methods as $method) {
                $code  = (string) ($method['value'] ?? '');
                $label = (string) ($method['label'] ?? '');
                if ($code === '') {
                    continue;
                }
                $rows[] = ['carrier' => $carrierLabel, 'code' => $code, 'label' => $label];
            }
        }

        if (empty($rows)) {
            $output->writeln('<comment>No shipping methods registered through Magento\'s Allmethods source.</comment>');
            $output->writeln('Check that you have at least one carrier enabled under');
            $output->writeln('  Stores → Configuration → Sales → Shipping Methods');
            return Command::SUCCESS;
        }

        if ($format === 'csv') {
            $output->writeln('carrier,code,label');
            foreach ($rows as $row) {
                $output->writeln(sprintf(
                    '%s,%s,%s',
                    $this->escapeCsv($row['carrier']),
                    $this->escapeCsv($row['code']),
                    $this->escapeCsv($row['label'])
                ));
            }
        } else {
            $table = new Table($output);
            $table->setHeaders(['Carrier', 'Method Code (use this in NDE)', 'Label']);
            foreach ($rows as $row) {
                $table->addRow([$row['carrier'], $row['code'], $row['label']]);
            }
            $table->render();

            // v1.6.2 mismatch detection — surface silent no-op misconfigs
            // before customers complain. Read the three NDE method-code
            // config fields and diff against the active set built above.
            $this->renderMismatchReport($output);

            $output->writeln('');
            $output->writeln('<info>How to use:</info>');
            $output->writeln(' 1. Copy the codes you want NDE to REMOVE from ineligible carts (next-day / express).');
            $output->writeln(' 2. Stores → Configuration → eTechFlow → Next Day Eligibility → General Settings →');
            $output->writeln('    <comment>Next Day Shipping Methods</comment> (multi-select) OR');
            $output->writeln('    <comment>Additional Next Day Codes</comment> (comma-separated text input).');
            $output->writeln('');
            $output->writeln('<comment>Missing your custom carrier?</comment> Some custom shipping modules');
            $output->writeln('(Hyvä Shipping Page, marketplace shippers, etc.) register their methods');
            $output->writeln('at runtime instead of via Allmethods — those won\'t appear in the list above.');
            $output->writeln('To find their codes, complete a test checkout and check the');
            $output->writeln('<comment>sales_order.shipping_method</comment> column for a recent order,');
            $output->writeln('OR enable <comment>bin/magento dev:template-hints:enable</comment> and inspect');
            $output->writeln('the rate radio inputs on the checkout page. Paste those into the');
            $output->writeln('<comment>Additional Method Codes</comment> field.');
        }

        return Command::SUCCESS;
    }

    /**
     * Print a coloured diff of configured-vs-active for each NDE rule.
     */
    private function renderMismatchReport(OutputInterface $output): void
    {
        $all = $this->availability->analyzeAll();
        $hasAnyConfig = false;
        $hasAnyMismatch = false;

        foreach ($all as $analysis) {
            if (!empty($analysis['configured'])) {
                $hasAnyConfig = true;
            }
            if (!empty($analysis['unmatched'])) {
                $hasAnyMismatch = true;
            }
        }

        if (!$hasAnyConfig) {
            return;
        }

        $output->writeln('');
        $output->writeln('<info>NDE configuration vs active methods:</info>');

        foreach ($all as $type => $analysis) {
            if (empty($analysis['configured'])) {
                continue;
            }
            $label = match ($type) {
                ShippingMethodAvailability::TYPE_NEXTDAY       => 'Next Day Methods',
                ShippingMethodAvailability::TYPE_STANDARD      => 'Standard Methods',
                ShippingMethodAvailability::TYPE_CLICK_COLLECT => 'Click & Collect Methods',
                default                                        => $type,
            };

            $output->writeln(sprintf('  <comment>%s</comment>', $label));

            foreach ($analysis['matched'] as $code) {
                $output->writeln(sprintf('    <info>[OK]</info>   %s', $code));
            }
            foreach ($analysis['unmatched'] as $code) {
                $output->writeln(sprintf('    <error>[WARN]</error> %s — not an active method on this store', $code));
            }
        }

        if ($hasAnyMismatch) {
            $output->writeln('');
            $output->writeln('<error>One or more configured codes do NOT match any active method.</error>');
            $output->writeln('  These rules silently do nothing. Fix at Stores → Configuration →');
            $output->writeln('  eTechFlow → Next Day Eligibility → General Settings.');
        }
    }

    /**
     * @param string $value
     * @return string
     */
    private function escapeCsv(string $value): string
    {
        if (strpbrk($value, ",\"\n") !== false) {
            return '"' . str_replace('"', '""', $value) . '"';
        }
        return $value;
    }
}
