# Passwortrichtlinie

Legt fest, wie Passwörter aussehen müssen — für Benutzerkonten ebenso wie für
Passwörter auf öffentlichen Links. Dazu kommen Ablauffristen, ein
Passwortverlauf und Erinnerungen per E-Mail.

## Was geprüft wird

**Aufbau des Passworts**

| Regel | Schlüssel | Bedeutung |
| --- | --- | --- |
| Mindestlänge | `spv_min_chars_value` | wie viele Zeichen mindestens |
| Kleinbuchstaben | `spv_lowercase_value` | wie viele mindestens |
| Großbuchstaben | `spv_uppercase_value` | wie viele mindestens |
| Ziffern | `spv_numbers_value` | wie viele mindestens |
| Sonderzeichen | `spv_special_chars_value` | wie viele mindestens |
| Erlaubte Sonderzeichen | `spv_def_special_chars_value` | welche Zeichen als Sonderzeichen gelten |

Jede Regel hat einen zugehörigen Schalter `…_checked`; ohne ihn bleibt der Wert
wirkungslos.

**Verlauf und Ablauf**

| Regel | Schlüssel | Bedeutung |
| --- | --- | --- |
| Passwortverlauf | `spv_password_history_value` | wie viele frühere Passwörter gesperrt bleiben |
| Ablauf des Benutzerpassworts | `spv_user_password_expiration_value` | nach wie vielen Tagen ein neues Passwort fällig wird |
| Ablauf öffentlicher Links mit Passwort | `spv_expiration_password_value` | Höchstdauer in Tagen |
| Ablauf öffentlicher Links ohne Passwort | `spv_expiration_nopassword_value` | Höchstdauer in Tagen |

Die getrennten Fristen für Links mit und ohne Passwort sind der eigentliche
Gewinn: Wer ein Passwort setzt, darf den Link länger leben lassen.

## Voraussetzungen

* owncloud.online 11.x
* PHP 8.4
* laufender Cron für die Ablauf-Benachrichtigungen

## Installation

Über den Market, oder von Hand:

```bash
cd /var/www/owncloud.online/apps
git clone https://github.com/BWTECH-github/password_policy.git
chown -R www-data:www-data password_policy
sudo -u www-data php8.4 ../occ app:enable password_policy
```

## Einstellungen

Einstellungen → Sicherheit. Jede Regel wird einzeln eingeschaltet und mit einem
Wert versehen. Alternativ per Kommandozeile:

```bash
sudo -u www-data php8.4 occ config:app:set password_policy spv_min_chars_checked --value=on
sudo -u www-data php8.4 occ config:app:set password_policy spv_min_chars_value --value=12
```

## Umzug von einer älteren Instanz

Regeln (`spv_*` in `oc_appconfig`), Kennwortverlauf (`oc_user_password_history`)
und die Merker je Konto (`oc_preferences`, `appid=password_policy`) werden nach
einem Umzug der Datenbank unverändert weiter gelesen. Die Einträge im
Kennwortverlauf sind mit `password_hash` gebildet und hängen an keinem Wert der
`config.php`.

Kommt die Datenbank noch aus der Zeit der Vorgänger-App **security** (ownCloud
bis 10.0.8), stehen deren Regeln unter `appid=security`
(`min_password_length`, `enforce_upper_lower_case`,
`enforce_numeric_characters`, `enforce_special_characters`). Wird
password_policy auf so einer Datenbank zum ersten Mal installiert und hat noch
keinen `spv_*`-Schlüssel, übernimmt sie diese Regeln (Reparaturschritt
`ImportLegacySecuritySettings`, Hinweis im Serverprotokoll). Ohne gespeicherte
Länge gilt wie bei security die Mindestlänge 8. Eigene Werte werden nie
überschrieben, der Altbestand bleibt liegen. War die App in der alten Datenbank
schon installiert, gilt ihr eigener Stand.

Unterschiede zu security, die der Administrator kennen muss:

- security prüfte nur Kontokennwörter, password_policy wendet die Regeln auch
  auf Kennwörter öffentlicher Links an.
- Die Mindestlänge zählt hier Zeichen, security zählte Bytes. Kennwörter mit
  Umlauten oder anderen Nicht-ASCII-Zeichen können die Länge deshalb verfehlen,
  die sie bei security erfüllt haben (sieben Umlaute sind 7 Zeichen, aber 14
  Bytes).
- Groß- und Kleinbuchstaben werden hier nach Unicode erkannt, security kannte
  nur A–Z und a–z.

Das wirkt erst bei der nächsten Kennwortänderung; niemand wird ausgesperrt.

Die App ist nicht standardmäßig eingeschaltet. Kennt die alte Datenbank sie
noch nicht, installiert `occ upgrade` sie deshalb nicht; die Übernahme läuft
erst, wenn sie nach dem Upgrade eingeschaltet wird:

```bash
sudo -u www-data php8.4 occ app:enable password_policy
```

Kommt die Datenbank von der Enterprise-Fassung 1.x (gleiche App-ID), ist das
der Update-Weg: 1.x verwendete dieselben `spv_*`-Schlüssel im selben Format
(Mindestlänge, Großbuchstaben, Ziffern, Sonderzeichen samt erlaubter Auswahl,
Ablauf von Links mit und ohne Kennwort), sie werden unverändert weiter gelesen.
Die Regel für Kleinbuchstaben gab es dort noch nicht; die Tabelle für den
Kennwortverlauf legt das Update an.

## Kommandozeile

```bash
# Passwort eines Kontos sofort als abgelaufen markieren
sudo -u www-data php8.4 occ user:expire-password <benutzer>
```

Beim nächsten Anmelden muss dieses Konto ein neues Passwort setzen.

Der Hintergrundauftrag `OCA\PasswordPolicy\Jobs\PasswordExpirationNotifierJob`
verschickt die Erinnerungen vor dem Ablauf. Er braucht einen funktionierenden
Cron.

## Geltungsbereich

Die Regeln gelten für lokale Konten — also für Konten, die Administratoren
anlegen, und für Gastkonten. Konten aus LDAP oder anderen Backends bringen ihre
eigenen Regeln mit; dort greift diese App nicht.

## Fehlersuche

| Symptom | Ursache | Abhilfe |
| --- | --- | --- |
| Regel wirkt nicht | zugehöriger `…_checked`-Schalter fehlt | Schalter setzen |
| Keine Ablauf-E-Mails | Cron läuft nicht oder E-Mail-Versand ist nicht eingerichtet | `occ background:cron`, Mail-Einstellungen prüfen |
| LDAP-Nutzer unbetroffen | so gewollt | Regeln im Verzeichnisdienst setzen |

## Ein Wort zur Abwägung

Sehr strenge Regeln erzeugen Zettel am Monitor. Die Empfehlungen des NIST
(SP 800-63B) raten zu Länge statt zu Zeichenklassen-Akrobatik und zu
Ablauffristen nur bei begründetem Verdacht. Wer die Regeln setzt, sollte das
mit Blick auf die eigenen Vorgaben abwägen.

## Herkunft

Fork der gleichnamigen ownCloud-App, gepflegt von der BW-Tech GmbH für
owncloud.online und PHP 8.4. Lizenz: AGPLv3.
