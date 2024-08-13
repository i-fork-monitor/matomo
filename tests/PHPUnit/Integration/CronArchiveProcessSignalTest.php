<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Tests\Integration;

use Piwik\CliMulti\CliPhp;
use Piwik\Common;
use Piwik\DataAccess\Model;
use Piwik\Db;
use Piwik\Option;
use Piwik\Tests\Fixtures\OneVisit;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * @group Archiver
 * @group CronArchive
 * @group CronArchiveProcessSignal
 */
class CronArchiveProcessSignalTest extends IntegrationTestCase
{
    public const ENV_TRIGGER = 'MATOMO_TEST_CRON_ARCHIVE_PROCESS_SIGNAL';

    public const OPTION_ARCHIVE = 'CronArchiveProcessSignalTest.archive';
    public const OPTION_START = 'CronArchiveProcessSignalTest.start';

    /**
     * @var OneVisit
     */
    public static $fixture;

    public function testSigterm(): void
    {
        if (!extension_loaded('pcntl') || !function_exists('pcntl_signal')) {
            $this->markTestSkipped('signal test cannot run without ext-pcntl');
        }

        $cliPhp = new CliPhp();
        $phpBinary = $cliPhp->findPhpBinary();

        // prepending "exec" is required
        // otherwise the proc_get_status pid is off by one
        $command = sprintf('exec %s %s/tests/PHPUnit/proxy/console core:archive', $phpBinary, PIWIK_INCLUDE_PATH);

        $processPipes = [];
        $process = proc_open(
            $command,
            [
                ['pipe', 'r'],
                ['pipe', 'w'], // stdout
                ['pipe', 'w'], // stderr
            ],
            $processPipes,
            null,
            [
                self::ENV_TRIGGER => '1'
            ],
            [
                'suppress_errors' => true,
                'bypass_shell' => true
            ]
        );

        $processStatus = proc_get_status($process);

        self::assertTrue($processStatus['running']);
        self::assertNotNull($processStatus['pid']);

        Option::set(self::OPTION_START, true);

        $dataAccessModel = new Model();

        for ($i = 0; $i < 10; $i++) {
            $invalidationsInProgress = $dataAccessModel->getInvalidationsInProgress(self::$fixture->idSite);

            if ([] !== $invalidationsInProgress) {
                break;
            }

            sleep(1);
        }

        self::assertNotEmpty($invalidationsInProgress);
        self::assertSame(4, $this->getArchiveInvalidationCount(self::$fixture->idSite));

        //Option::set(self::OPTION_ARCHIVE, true);

        $processStatus = proc_get_status($process);

        self::assertTrue($processStatus['running']);

        proc_terminate($process, SIGTERM);

        for ($i = 0; $i < 10; $i++) {
            $processStatus = proc_get_status($process);

            if (!$processStatus['running']) {
                break;
            }

            sleep(1);
        }

        self::assertFalse($processStatus['running']);
        self::assertSame(0, $processStatus['exitcode']);

        $invalidationsInProgress = $dataAccessModel->getInvalidationsInProgress(self::$fixture->idSite);

        self::assertEmpty($invalidationsInProgress);
        self::assertSame(4, $this->getArchiveInvalidationCount(self::$fixture->idSite));
    }

    private function getArchiveInvalidationCount(int $idSite): int
    {
        return Db::fetchOne(
            'SELECT COUNT(*) FROM ' . Common::prefixTable('archive_invalidations') . ' WHERE idsite = ?',
            [
                $idSite
            ]
        );
    }
}

CronArchiveProcessSignalTest::$fixture = new OneVisit();
