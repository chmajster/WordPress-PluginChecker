# Plan Integration — WordPress Plugin Checker

Samodzielna wtyczka WordPress integrująca aplikację Plan z WordPressem i aktualizowana bezpośrednio z GitHub Releases repozytorium `chmajster/WordPress-PluginChecker`.

## Wymagania

- WordPress 6.0 lub nowszy; testowane pod WordPress 7.1.
- PHP 8.2, 8.3 lub 8.4.
- Brak zależności wymagających ręcznego `composer install` lub `npm install` po instalacji ZIP.

## Instalacja

1. Pobierz `plan-integration.zip` z wybranego GitHub Release.
2. WordPress → Wtyczki → Dodaj nową → Wyślij wtyczkę na serwer.
3. Wskaż `plan-integration.zip`.
4. Zainstaluj i aktywuj.
5. Skonfiguruj URL aplikacji w Ustawienia → Plan Integration.

ZIP zawsze zawiera katalog główny `plan-integration/`, dlatego wynikowa ścieżka to `wp-content/plugins/plan-integration/`.

## Mechanizm aktualizacji

Updater korzysta z WordPress HTTP API i endpointu GitHub Releases. Nie sprawdza commitów na `main` i nie używa WordPress.org.

Zasady kanału `stable`:

- pobierane są Releases repozytorium `chmajster/WordPress-PluginChecker`;
- Draft są ignorowane;
- Prerelease są ignorowane;
- tag musi mieć format `vX.Y.Z`;
- wersje są porównywane przez SemVer;
- akceptowany jest wyłącznie asset `plan-integration.zip`;
- brak tego assetu blokuje aktualizację zamiast używania `Source code (zip)`;
- wynik jest cache'owany przez 12 godzin;
- błędy GitHub API są cache'owane krótko i nie powodują błędu krytycznego WordPress.

W Ustawienia → Plan Integration dostępne są status, wersja lokalna i zdalna, czas ostatniego sprawdzenia, URL Release oraz bezpieczna diagnostyka.

## Publiczne i prywatne repozytorium

Dla repozytorium publicznego token nie jest wymagany.

Dla repozytorium prywatnego ustaw token poza kodem wtyczki, np. w `wp-config.php`:

```php
define('PLAN_GITHUB_TOKEN', 'github_pat_...');
```

Alternatywnie ustaw zmienną środowiskową `PLAN_GITHUB_TOKEN`.

Token nie jest prezentowany w UI i nie jest zapisywany przez wtyczkę. Dla prywatnych assetów updater pobiera plik przez GitHub Assets API z nagłówkiem `Authorization` przekazywanym wyłącznie w żądaniu HTTP.

## Proces wydania

1. Wszystkie zmiany trafiają do `main` przez Pull Request.
2. Po merge uruchom testy CI.
3. Zmień `Version:` w `plan-integration.php` i `Stable tag:` w `readme.txt` w PR przygotowującym wydanie.
4. Po merge utwórz tag, np. `v1.2.0`.
5. Workflow `.github/workflows/wordpress-plugin-release.yml` sprawdzi zgodność tagu z nagłówkiem pluginu.
6. Workflow uruchomi testy, przygotuje `plan-integration/`, utworzy deterministyczny `plan-integration.zip`, utworzy lub uzupełni GitHub Release i doda ZIP jako asset.

Release zostanie przerwany, jeśli tag i wersja wtyczki nie są identyczne.

## Zawartość ZIP

```text
plan-integration/
├── plan-integration.php
├── includes/
├── assets/
├── languages/
├── vendor/
├── readme.txt
└── uninstall.php
```

Do paczki nie trafiają `.git`, `.github`, `tests`, pliki buildu ani dokumentacja repozytorium.

## Rollback

Automatyczny rollback nie jest wykonywany.

1. Otwórz poprzedni GitHub Release.
2. Pobierz jego `plan-integration.zip`.
3. W WordPress wybierz Wtyczki → Dodaj nową → Wyślij wtyczkę na serwer.
4. Wskaż poprzedni ZIP.
5. Potwierdź zastąpienie obecnej wersji.
6. Zweryfikuj aktywację i konfigurację po cofnięciu.

## Testy

```bash
php tests/run.php
find . -type f -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
```

CI wykonuje testy na PHP 8.2, 8.3 i 8.4. Testy obejmują tę samą wersję, nowszą wersję, starszą wersję, prerelease, draft oraz brak wymaganego assetu.

## Architektura

- `plan-integration.php` — bootstrap.
- `includes/class-plan-version.php` — czysta logika SemVer i wyboru stabilnego Release.
- `includes/class-plan-updater.php` — GitHub API, cache, WordPress update transient i pobieranie prywatnych assetów.
- `includes/class-plan-settings.php` — konfiguracja i diagnostyka w panelu WordPress.
- `includes/class-plan-integration.php` — punkt rozszerzenia dla przyszłego SSO, mapowania kont/rol i API aplikacji Plan.
