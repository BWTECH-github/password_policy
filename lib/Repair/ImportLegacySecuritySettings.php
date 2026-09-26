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

namespace OCA\PasswordPolicy\Repair;

use OCP\IConfig;
use OCP\ILogger;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Übernimmt die Kennwortregeln der Vorgänger-App "security".
 *
 * "security" (ownCloud bis 10.0.8) verweist in ihrer Beschreibung selbst auf
 * Password Policy als Nachfolger. Sie speicherte ihre Regeln unter
 * appid=security in vier Schlüsseln; diese App kennt dafür die spv_*-Schlüssel.
 * Nach einem Umzug mit einer solchen Datenbank würde diese App ohne Regeln
 * starten.
 *
 * Abbildung (Verhalten von security/lib/PasswordValidator.php):
 *   min_password_length (Standard 8, intval)  -> spv_min_chars
 *   enforce_upper_lower_case (boolval)        -> spv_uppercase + spv_lowercase (je 1)
 *   enforce_numeric_characters (boolval)      -> spv_numbers (1)
 *   enforce_special_characters (boolval)      -> spv_special_chars (1)
 *
 * Unterschied, den der Administrator kennen muss: "security" prüfte nur
 * Benutzerkennwörter, diese App wendet dieselben Regeln auch auf Kennwörter
 * öffentlicher Links an. Das steht deshalb im Protokoll.
 *
 * Die Regeln selbst zählen hier anders als in "security": Die Mindestlänge
 * zählt Zeichen (mb_strlen) statt Bytes (strlen), Groß- und Kleinbuchstaben
 * werden nach Unicode erkannt statt nur im ASCII-Bereich. Ein Kennwort aus
 * sieben Umlauten (14 Bytes) erfüllte bei "security" eine Mindestlänge von 10,
 * hier nicht. Das wirkt erst bei der nächsten Kennwortänderung; bestehende
 * Kennwörter bleiben gültig.
 *
 * Der Schritt läuft nur bei der Erstinstallation (repair-steps/install): War
 * password_policy in der alten Datenbank schon installiert, gilt ihr eigener
 * Stand - auch wenn das "keine Regeln" ist. Übernommen wird außerdem nur,
 * solange diese App noch keinen spv_*-Schlüssel hat; vorhandene Werte werden nie
 * überschrieben, der Altbestand bleibt liegen, ein zweiter Lauf tut nichts.
 */
class ImportLegacySecuritySettings implements IRepairStep {
	public const APP = 'password_policy';
	public const LEGACY_APP = 'security';

	public const LEGACY_KEYS = [
		'min_password_length',
		'enforce_upper_lower_case',
		'enforce_numeric_characters',
		'enforce_special_characters',
	];

	/** Standard von "security", wenn keine Länge gespeichert war */
	public const LEGACY_DEFAULT_MIN_LENGTH = '8';

	/** @var IConfig */
	private $config;

	/** @var ILogger */
	private $logger;

	public function __construct(IConfig $config, ILogger $logger) {
		$this->config = $config;
		$this->logger = $logger;
	}

	public function getName() {
		return 'Import password rules of the predecessor app "security"';
	}

	public function run(IOutput $output) {
		foreach ($this->config->getAppKeys(self::APP) as $key) {
			if (\strpos($key, 'spv_') === 0) {
				// Die App ist bereits eingestellt - nichts übernehmen.
				return;
			}
		}

		$legacy = \array_intersect(self::LEGACY_KEYS, $this->config->getAppKeys(self::LEGACY_APP));
		if ($legacy === []) {
			// Ohne gespeicherten Schlüssel lässt sich nicht unterscheiden, ob
			// "security" je aktiv war - dann wird nichts angenommen.
			return;
		}

		$get = function (string $key, string $default): string {
			return (string)$this->config->getAppValue(self::LEGACY_APP, $key, $default);
		};

		$rules = [];
		$minLength = \intval($get('min_password_length', self::LEGACY_DEFAULT_MIN_LENGTH));
		if ($minLength > 0) {
			$rules['spv_min_chars'] = (string)$minLength;
		}
		if (\boolval($get('enforce_upper_lower_case', '0'))) {
			$rules['spv_uppercase'] = '1';
			$rules['spv_lowercase'] = '1';
		}
		if (\boolval($get('enforce_numeric_characters', '0'))) {
			$rules['spv_numbers'] = '1';
		}
		if (\boolval($get('enforce_special_characters', '0'))) {
			$rules['spv_special_chars'] = '1';
		}

		$imported = [];
		foreach ($rules as $rule => $value) {
			$this->config->setAppValue(self::APP, $rule . '_value', $value);
			$this->config->setAppValue(self::APP, $rule . '_checked', 'on');
			$imported[] = "$rule=$value";
		}

		if ($imported === []) {
			$message = 'The predecessor app "security" had password settings, but none of them was active; nothing imported.';
			$output->info($message);
			$this->logger->info($message, ['app' => self::APP]);
			return;
		}

		$message = 'Imported password rules from the predecessor app "security": ' . \implode(', ', $imported)
			. '. Note: they now also apply to public link passwords. Please review them in the admin settings.';
		$output->info($message);
		// Warnstufe, damit der Hinweis auch beim Standard-Loglevel 2 im
		// Serverprotokoll landet - die Übernahme soll nachvollziehbar sein.
		$this->logger->warning($message, ['app' => self::APP]);
	}
}
