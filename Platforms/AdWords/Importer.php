<?php
/**
 * AOM - Piwik Advanced Online Marketing Plugin
 *
 * @author Daniel Stonies <daniel.stonies@googlemail.com>
 */
namespace Piwik\Plugins\AOM\Platforms\AdWords;

use Piwik\Db;
use Piwik\Plugins\AOM\AOM;
use Piwik\Plugins\AOM\Platforms\ImporterInterface;
use Piwik\Plugins\AOM\Settings;
use ReportUtils;

class Importer extends \Piwik\Plugins\AOM\Platforms\Importer implements ImporterInterface
{
    /**
     * When no period is provided, AdWords (re)imports the last 3 days unless they have been (re)imported today.
     * Today's data is always being reimported.
     *
     * @param string|null $startDate
     * @param string|null $endDate
     * @return mixed|void
     */
    public function setPeriod($startDate = null, $endDate = null)
    {
        // Overwrite default period
        if (null === $startDate || null === $endDate) {

            $startDate = date('Y-m-d');

            // (Re)import the last 3 days unless they have been (re)imported today
            for ($i = -3; $i <= -1; $i++) {
                if (Db::fetchOne('SELECT DATE(MAX(ts_created)) FROM ' . AdWords::getDataTableNameStatic()
                        . ' WHERE date = "' . date('Y-m-d', strtotime($i . ' day', time())) . '"') != date('Y-m-d')
                ) {
                    $startDate = date('Y-m-d', strtotime($i . ' day', time()));
                    break;
                }
            }

            $endDate = date('Y-m-d');
            $this->logger->info('Identified period from ' . $startDate. ' until ' . $endDate . ' to import.');
        }

        parent::setPeriod($startDate, $endDate);
    }

    /**
     * Imports all active accounts day by day.
     */
    public function import()
    {
        $settings = new Settings();
        $configuration = $settings->getConfiguration();

        foreach ($configuration[AOM::PLATFORM_AD_WORDS]['accounts'] as $accountId => $account) {;
            if (array_key_exists('active', $account) && true === $account['active']) {
                foreach (AOM::getPeriodAsArrayOfDates($this->startDate, $this->endDate) as $date) {
                    $this->importAccount($accountId, $account, $date);
                }
            } else {
                $this->logger->info('Skipping inactive account.');
            }
        }
    }

    /**
     * @param string $accountId
     * @param array $account
     * @param string $date
     * @throws \Exception
     */
    private function importAccount($accountId, $account, $date)
    {
        $this->logger->info('Will import account ' . $accountId. ' for date ' . $date . ' now.');
        $this->deleteImportedData(AdWords::getDataTableNameStatic(), $accountId, $account['websiteId'], $date);

        $user = AdWords::getAdWordsUser($account);

        $user->LogAll();

        // Download report (@see https://developers.google.com/adwords/api/docs/appendix/reports?hl=de#criteria)
        // https://developers.google.com/adwords/api/docs/appendix/reports/criteria-performance-report?hl=de
        $xmlString = ReportUtils::DownloadReportWithAwql(
            'SELECT AccountDescriptiveName, AccountCurrencyCode, AccountTimeZoneId, CampaignId, CampaignName, '
            . 'AdGroupId, AdGroupName, Id, Criteria, CriteriaType, AdNetworkType1, AdNetworkType2, AveragePosition, Conversions, '
            . 'QualityScore, CpcBid, Impressions, Clicks, Cost, Date '
            . 'FROM CRITERIA_PERFORMANCE_REPORT WHERE Impressions > 0 DURING '
            . str_replace('-', '', $date) . ','
            . str_replace('-', '', $date),
            null,
            $user,
            'XML',
            [
                'version' => 'v201509',
                'skipReportHeader' => true,
                'skipColumnHeader' => true,
                'skipReportSummary' => true,
            ]
        );
        $xml = simplexml_load_string($xmlString);

        // TODO: Use MySQL transaction to improve performance!
        foreach ($xml->table->row as $row) {

            // TODO: Validate currency and timezone?!
            // TODO: qualityScore, maxCPC, avgPosition?!
            // TODO: Find correct place to log warning, errors, etc. and monitor them!

            // Validation
            if (!in_array(strtolower((string) $row['criteriaType']), AdWords::$criteriaTypes)) {
                var_dump('Criteria type "' . (string) $row['criteriaType'] . '" not supported.');
                continue;
            } else {
                $criteriaType = strtolower((string) $row['criteriaType']);
            }

            if (!in_array((string) $row['networkWithSearchPartners'], array_keys(AdWords::$networks))) {
                var_dump('Network "' . (string) $row['networkWithSearchPartners'] . '" not supported.');
                continue;
            } else {
                $network = AdWords::$networks[(string) $row['networkWithSearchPartners']];
            }

            Db::query(
                'INSERT INTO ' . AdWords::getDataTableNameStatic()
                    . ' (id_account_internal, idsite, date, account, campaign_id, campaign, ad_group_id, ad_group, '
                    . 'keyword_id, keyword_placement, criteria_type, network, impressions, clicks, cost, '
                    . 'conversions, ts_created) '
                    . 'VALUE (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
                [
                    $accountId,
                    $account['websiteId'],
                    $row['day'],
                    $row['account'],
                    $row['campaignID'],
                    $row['campaign'],
                    $row['adGroupID'],
                    $row['adGroup'],
                    $row['keywordID'],
                    $row['keywordPlacement'],
                    $criteriaType,
                    $network,
                    $row['impressions'],
                    $row['clicks'],
                    ($row['cost'] / 1000000),
                    $row['conversions'],
                ]
            );
        }
    }
}