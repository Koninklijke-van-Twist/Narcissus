<?php

/**
 * Kopieer naar web/auth.php op de server en vul in. auth.php staat niet in git
 * en de FTP-deploy overschrijft hem niet.
 *
 * $allowedUsers
 *   E-mailadressen die Narcissus mogen openen (logincheck.php).
 *   Lokaal (php -S op 127.0.0.1) logt Narcissus automatisch in als het eerste adres.
 *
 * $admins
 *   Beheerders. Alleen zij zien de tab "BC Gebruik" en krijgen data van
 *   bc_gebruik.php en api.php?action=bcgebruik / bcgebruik_heatmap.
 *   Iedereen anders krijgt 403. Weglaten of [] → niemand is beheerder (fail-closed).
 *   Logintijden zijn persoonsgegevens: houd deze lijst kort.
 *
 * $mimirApi / $mimirBase
 *   Mímir-sleutel voor de nightly (Users + UserTimeRegisters, alleen lezen).
 *   Zonder $mimirApi slaat de nightly BC Gebruik over en toont de tab een melding.
 *
 * $heatmapIntensityMax
 *   Optioneel: plafond van de heatmap bij Pagina-activiteit (standaard 250 bezoeken).
 */

$allowedUsers = [
    'user@domain.nl',
];

$admins = [
    // 'beheerder@kvt.nl',
];

// --- Mímir ---
// $mimirApi  = 'mimir_…';
// $mimirBase = 'https://sleutels.kvt.nl/mimir/api';

// $heatmapIntensityMax = 250;
