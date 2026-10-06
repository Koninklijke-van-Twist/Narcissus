# Narcissus

Interne analytics-pagina op sleutels.kvt.nl. `web/` is de page root en gaat via FTP naar de server (zie `.github/workflows/deploy-ftp.yml`).

## Tabs

- **Pagina-activiteit** (`web/index.php`): bezoeken per pagina en per gebruiker uit de `analytics.sqlite` van de andere apps.
- **BC Gebruik** (`web/bc_gebruik.php`, alleen beheerders): welke BC-gebruikers met licentie Business Central weinig of juist veel gebruiken.

## BC Gebruik

Bronnen, alleen lezen via Mímir (`POST /mimir/api/query.php`, `top: 0`):

| Bron | BC-page | Bedrijf | Velden |
| --- | --- | --- | --- |
| `Users` | 9800 | Koninklijke van Twist (niet per bedrijf) | `User_Security_ID`, `User_Name`, `Full_Name`, `State`, `License_Type`, `Authentication_Email` |
| `UserTimeRegisters` | 71 | Koninklijke van Twist, Hunter van Twist, KVT Gas | `User_ID`, `Date`, `Minutes` |

- Join: `UserTimeRegisters.User_ID` = `Users.User_Name` (hoofdletterongevoelig, getrimd). Minuten per gebruiker per dag worden over de drie bedrijven opgeteld.
- Alleen `State` = Enabled en `License_Type` in Full User, Limited User, Device Only User.
- Venster: 26 hele weken t/m de huidige week (`NARCISSUS_BC_USAGE_WINDOW_WEEKS`). Filter naar Mímir: `Date ge <vensterbegin>` als filterboom (Mímir maakt er `Date ge 2026-04-13` van, Edm.Date zonder quotes).
- Minuten zijn **inclusief idle-tijd** en tellen alleen waar **Register Time** aan staat. Een dag kan boven 24 uur uitkomen (sessie over meerdere dagen open, of meerdere bedrijven tegelijk); live is dat ~6% van de actieve dagen.
- Weinig gebruik: minder dan 4 actieve dagen in de laatste 30 dagen, of laatste registratie meer dan 30 dagen geleden. Drempels staan als constanten in `web/bc_usage.php`.
- Heatmap per gebruiker (weekdag × week, minuten per dag), lineaire kleurschaal. Kleurplafond = P99 (`NARCISSUS_BC_USAGE_CEILING_PERCENTILE`) van alle dagwaarden in het venster, zonder dagen boven 24:00 (`NARCISSUS_BC_USAGE_OUTLIER_MINUTES`): die zijn onmogelijk voor één persoon (sessies of bedrijven over elkaar heen). Live (okt 2026) is het plafond 23:13; het maximum t/m 24:00 is vrijwel altijd 24:00 zelf, P99 volgt de data. Uitschieters tellen alleen voor het plafond niet mee: in totalen, gemiddelden en de heatmap tellen alle minuten. Dagen boven het plafond zijn geel tot oranje, dezelfde kleur als bij Pagina-activiteit. Hover toont de echte waarde (HH:MM).

Ophalen gebeurt alleen in `web/nightly.php` (Mímir `max_age` 4 uur). De UI leest alleen het bestand `web/data/bc_gebruik.php`: aggregaten (gebruikersnaam, volledige naam, licentietype, minuten per dag), zonder SID of e-mail. Het bestand begint met een PHP-guard (direct opvragen geeft 403) en de nightly zet een `.htaccess` met `Require all denied` in `web/data/`.

Faalt een bron (bijv. 404 = niet gepubliceerd), dan staat er per bron een melding op de tab. Mislukt `Users` of mislukken alle drie de `UserTimeRegisters`, dan blijven de vorige gegevens staan.

### Privacy en toegang

Logintijden zijn persoonsgegevens. Alleen adressen in `$admins` (auth.php) zien de tab en krijgen data van `bc_gebruik.php` en `api.php?action=bcgebruik` / `bcgebruik_heatmap`. Iedereen anders krijgt 403. Lege of ontbrekende `$admins` = niemand.

## auth.php

Niet in git. Kopieer `web/auth_TEMPLATE.php` naar `web/auth.php` en vul `$allowedUsers`, `$admins` en `$mimirApi` in.

## Tests

```sh
php tests/heatmap_range_test.php
node tests/heatmap_layout_smoke.js
php tests/bc_usage_test.php
node tests/bc_usage_smoke.js
php tests/bc_usage_access_test.php   # start php -S op een tijdelijke kopie van web/
```

De fixture (`tests/fixtures/bc_usage_fixture.php`) is synthetisch: 199 rijen in Users, 160 gelicentieerd, 6 maanden UserTimeRegisters over drie bedrijven.
