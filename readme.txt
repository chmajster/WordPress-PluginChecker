=== Plan Integration ===
Contributors: chmajster
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.1.0
License: GPLv2 or later

Integracja WordPress z aplikacją Plan z bezpiecznymi aktualizacjami dostarczanymi bezpośrednio przez GitHub Releases.

== Description ==

Wtyczka nie korzysta z WordPress.org jako źródła aktualizacji. Aktualizacje są wykrywane na podstawie GitHub Releases repozytorium chmajster/WordPress-PluginChecker.

Funkcje:
* kanały Stable oraz Beta/RC,
* wymagany asset plan-integration.zip,
* wymagana suma plan-integration.zip.sha256,
* weryfikacja SHA-256 przed instalacją,
* możliwość wyłączenia aktualizacji lub ustawienia maksymalnej wersji,
* backup przed aktualizacją i ręczny rollback,
* historia operacji i diagnostyki,
* test połączenia z aplikacją Plan,
* konfiguracja mapowania ról WordPress -> Plan.

Kanał Stable ignoruje Draft i Prerelease. Kanał Beta/RC nadal ignoruje Draft, ale może korzystać z prerelease zgodnych z obsługiwanym SemVer.

== Installation ==

1. Pobierz plan-integration.zip z GitHub Release.
2. W WordPress przejdź do Wtyczki -> Dodaj nową -> Wyślij wtyczkę na serwer.
3. Zainstaluj i aktywuj wtyczkę.
4. Ustaw URL aplikacji Plan w Ustawienia -> Plan Integration.
5. Skonfiguruj kanał aktualizacji i mapowanie ról.

== Changelog ==

= 1.1.0 =
* Dodano weryfikację SHA-256 paczek aktualizacji.
* Dodano kanały Stable oraz Beta/RC.
* Dodano możliwość wyłączenia aktualizacji i przypięcia maksymalnej wersji.
* Dodano backup przed aktualizacją i ręczny rollback.
* Dodano historię aktualizacji i diagnostyki.
* Dodano test połączenia z aplikacją Plan.
* Dodano mapowanie ról WordPress do ról Plan.
* Rozbudowano panel diagnostyczny.

= 1.0.0 =
* Pierwsze wydanie.
