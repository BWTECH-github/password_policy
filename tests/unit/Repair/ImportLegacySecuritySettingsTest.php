<?php
/**
 * @copyright Copyright (c) 2026, BW-Tech GmbH
 * @license GPL-2.0
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\PasswordPolicy\Tests\Repair;

use OCA\PasswordPolicy\ConfigProvider;
use OCA\PasswordPolicy\Repair\ImportLegacySecuritySettings;
use OCP\IConfig;
use OCP\ILogger;
use OCP\Migration\IOutput;
use Test\TestCase;

/**
 * Prüft die Übernahme der Kennwortregeln aus der Vorgänger-App "security"
 * gegen die echte App-Konfiguration (oc_appconfig), so wie sie nach einem
 * Umzug aus der alten Datenbank vorliegt.
 *
 * @group DB
 */
class ImportLegacySecuritySettingsTest extends TestCase {
	private const LEGACY_KEYS = [
		'min_password_length',
		'enforce_upper_lower_case',
		'enforce_numeric_characters',
		'enforce_special_characters',
	];

	/** @var IConfig */
	private $config;

	/** @var array<string, array<string, string>> Stand vor dem Test, wird in tearDown zurückgeschrieben */
	private $backup = [];

	public function setUp(): void {
		parent::setUp();
		$this->config = \OC::$server->getConfig();
		foreach ($this->touchedKeys() as $app => $keys) {
			foreach ($keys as $key) {
				$value = $this->config->getAppValue($app, $key, null);
				if ($value !== null) {
					$this->backup[$app][$key] = $value;
				}
				$this->config->deleteAppValue($app, $key);
			}
		}
	}

	public function tearDown(): void {
		foreach ($this->touchedKeys() as $app => $keys) {
			foreach ($keys as $key) {
				$this->config->deleteAppValue($app, $key);
			}
		}
		foreach ($this->backup as $app => $values) {
			foreach ($values as $key => $value) {
				$this->config->setAppValue($app, $key, $value);
			}
		}
		parent::tearDown();
	}

	/**
	 * @return array<string, string[]>
	 */
	private function touchedKeys(): array {
		$spvKeys = \array_values(\array_filter(
			$this->config->getAppKeys('password_policy'),
			static function ($key) {
				return \strpos($key, 'spv_') === 0;
			}
		));
		return [
			'security' => self::LEGACY_KEYS,
			'password_policy' => \array_unique(\array_merge(
				$spvKeys,
				\array_keys(\OCA\PasswordPolicy\Controller\SettingsController::DEFAULTS)
			)),
		];
	}

	private function runStep(): void {
		$step = new ImportLegacySecuritySettings(
			$this->config,
			$this->createMock(ILogger::class)
		);
		$step->run($this->createMock(IOutput::class));
	}

	/**
	 * Die Regeln, die die App nach dem Lauf tatsächlich anwendet.
	 *
	 * @return array<string, string>
	 */
	private function activeRequirements(): array {
		$requirements = (new ConfigProvider($this->config))->getActivePasswordRequirements();
		\ksort($requirements);
		return $requirements;
	}

	/**
	 * @return string[]
	 */
	private function ownSpvKeys(): array {
		$keys = \array_values(\array_filter(
			$this->config->getAppKeys('password_policy'),
			static function ($key) {
				return \strpos($key, 'spv_') === 0;
			}
		));
		\sort($keys);
		return $keys;
	}

	public function testImportsCompleteLegacyPolicy(): void {
		$this->config->setAppValue('security', 'min_password_length', '10');
		$this->config->setAppValue('security', 'enforce_upper_lower_case', '1');
		$this->config->setAppValue('security', 'enforce_numeric_characters', '1');
		$this->config->setAppValue('security', 'enforce_special_characters', '1');

		$this->runStep();

		$this->assertSame([
			'spv_lowercase_value' => '1',
			'spv_min_chars_value' => '10',
			'spv_numbers_value' => '1',
			'spv_special_chars_value' => '1',
			'spv_uppercase_value' => '1',
		], $this->activeRequirements());
	}

	public function testUsesLegacyDefaultLengthWhenOnlyTogglesWereSaved(): void {
		// "security" erzwang ohne gespeicherten Wert mindestens 8 Zeichen.
		$this->config->setAppValue('security', 'enforce_numeric_characters', '1');

		$this->runStep();

		$this->assertSame([
			'spv_min_chars_value' => '8',
			'spv_numbers_value' => '1',
		], $this->activeRequirements());
	}

	public function testDisabledLegacyTogglesStayDisabled(): void {
		$this->config->setAppValue('security', 'min_password_length', '12');
		$this->config->setAppValue('security', 'enforce_upper_lower_case', '0');
		$this->config->setAppValue('security', 'enforce_numeric_characters', '0');
		$this->config->setAppValue('security', 'enforce_special_characters', '0');

		$this->runStep();

		$this->assertSame(['spv_min_chars_value' => '12'], $this->activeRequirements());
	}

	public function testLegacyLengthWithoutEffectCreatesNoLengthRule(): void {
		// intval('abc') === 0: "security" hat dann gar keine Mindestlänge geprüft.
		$this->config->setAppValue('security', 'min_password_length', 'abc');
		$this->config->setAppValue('security', 'enforce_special_characters', '1');

		$this->runStep();

		$this->assertSame(['spv_special_chars_value' => '1'], $this->activeRequirements());
	}

	public function testKeepsExistingSettingsUntouched(): void {
		// Schon ein einziger eigener Schlüssel heißt: die neue App wurde
		// eingestellt (auch "alles aus" ist eine Einstellung).
		$this->config->setAppValue('password_policy', 'spv_min_chars_checked', '');
		$this->config->setAppValue('security', 'min_password_length', '10');
		$this->config->setAppValue('security', 'enforce_numeric_characters', '1');

		$this->runStep();

		$this->assertSame(['spv_min_chars_checked'], $this->ownSpvKeys());
	}

	public function testSecondRunIsNoop(): void {
		$this->config->setAppValue('security', 'min_password_length', '10');
		$this->config->setAppValue('security', 'enforce_numeric_characters', '1');
		$this->runStep();
		$afterFirstRun = $this->activeRequirements();

		$this->config->setAppValue('security', 'min_password_length', '20');
		$this->config->setAppValue('security', 'enforce_upper_lower_case', '1');
		$this->runStep();

		$this->assertSame($afterFirstRun, $this->activeRequirements());
	}

	public function testDoesNothingWithoutLegacyData(): void {
		$this->runStep();

		$this->assertSame([], $this->ownSpvKeys());
	}

	public function testLeavesLegacyValuesInPlace(): void {
		$this->config->setAppValue('security', 'min_password_length', '10');

		$this->runStep();

		$this->assertSame('10', $this->config->getAppValue('security', 'min_password_length', null));
	}

	public function testStepIsRegisteredAsInstallRepairStep(): void {
		$info = \OC::$server->getAppManager()->getAppInfo('password_policy');

		$this->assertContains(ImportLegacySecuritySettings::class, $info['repair-steps']['install']);
	}

	public function testStepIsNotRunOnUpdates(): void {
		$info = \OC::$server->getAppManager()->getAppInfo('password_policy');

		$this->assertNotContains(ImportLegacySecuritySettings::class, $info['repair-steps']['post-migration']);
	}
}
