<?php
/**
 * Ålesund Skadesenter AS — mottak av bookingskjema.
 *
 * Tar imot skjemaet fra index.html, komprimerte bilder inkludert, og sender
 * alt som én e-post med vedlegg til verkstedet. Ingen tredjepartstjeneste og
 * ingen lagring på serveren — dataene går rett videre til postkassen.
 *
 * Krever bare PHP 7.0+ med mail(). Ingen Composer, ingen utvidelser utover
 * standard. Last opp sammen med resten av filene.
 */

/* ── Innstillinger ───────────────────────────────────────────────────────── */

$MOTTAKER      = 'post@askade.no';
$AVSENDER      = 'post@askade.no';          // må ligge på askade.no for at SPF skal godkjenne
$AVSENDER_NAVN = 'Ålesund Skadesenter — nettskjema';
$EMNE_PREFIKS  = 'Forespørsel om taksttime';
$KVITTERING    = 'takk.html';               // brukes bare uten JavaScript

$MAKS_FILER      = 6;
$MAKS_PER_FIL    = 5 * 1024 * 1024;         // 5 MB
$MAKS_SUM        = 15 * 1024 * 1024;        // 15 MB til sammen
$MIN_SEKUNDER    = 3;                       // raskere enn dette = robot
$MAKS_PER_TIME   = 8;                       // per IP-adresse

/* ── Oppsett ─────────────────────────────────────────────────────────────── */

$erJs = isset($_POST['js']) && $_POST['js'] === '1';

function svar($ok, $melding = '', $kode = 200) {
    global $erJs, $KVITTERING;
    if ($erJs) {
        http_response_code($kode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($ok ? ['ok' => true] : ['ok' => false, 'feil' => $melding],
            JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($ok) {
        header('Location: ' . $KVITTERING, true, 303);
        exit;
    }
    http_response_code($kode);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="nb"><head><meta charset="utf-8">'
       . '<title>Forespørselen kom ikke fram</title></head>'
       . '<body style="font-family:system-ui;max-width:40em;margin:10vh auto;padding:0 1.5em">'
       . '<h1>Forespørselen kom ikke fram</h1><p>' . htmlspecialchars($melding, ENT_QUOTES, 'UTF-8') . '</p>'
       . '<p>Ring oss på <a href="tel:+4748409912">484 09 912</a> eller send e-post til '
       . '<a href="mailto:post@askade.no">post@askade.no</a>.</p>'
       . '<p><a href="index.html">Tilbake til skjemaet</a></p></body></html>';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    svar(false, 'Skjemaet må sendes inn på nytt.', 405);
}

/* ── Spamfilter ──────────────────────────────────────────────────────────── */

// Honningkrukke: feltet er skjult for folk, men roboter fyller det gjerne ut.
// Vi later som alt gikk bra, slik at roboten ikke prøver på nytt.
if (trim((string)post('nettsted')) !== '') {
    svar(true);
}

$lastet = (int)post('lastet');
if ($lastet > 0 && (time() - $lastet) < $MIN_SEKUNDER) {
    svar(true);
}

if (!innenforGrense($MAKS_PER_TIME)) {
    svar(false, 'For mange forespørsler fra samme nettverk. Prøv igjen om en time, eller ring oss.', 429);
}

/* ── Feltene ─────────────────────────────────────────────────────────────── */

$navn     = reintekst(post('navn'), 120);
$telefon  = reintekst(post('telefon'), 40);
$epost    = reintekst(post('epost'), 160);
$regnr    = reintekst(post('regnr'), 20);
$skadenr  = reintekst(post('skadenr'), 60);
$modell   = reintekst(post('modell'), 120);
$melding  = trim((string)post('melding'));

$mangler = [];
if ($navn === '')    $mangler[] = 'navn';
if ($telefon === '') $mangler[] = 'telefon';
if ($regnr === '')   $mangler[] = 'registreringsnummer';
if ($melding === '') $mangler[] = 'beskrivelse av skaden';
if (!filter_var($epost, FILTER_VALIDATE_EMAIL)) $mangler[] = 'gyldig e-postadresse';

if ($mangler) {
    svar(false, 'Mangler ' . implode(', ', $mangler) . '.', 422);
}

if (mb_strlen($melding, 'UTF-8') > 5000) {
    $melding = mb_substr($melding, 0, 5000, 'UTF-8') . ' […]';
}

/* ── Vedlegg ─────────────────────────────────────────────────────────────── */

$tekster  = isset($_POST['bildetekst']) && is_array($_POST['bildetekst']) ? $_POST['bildetekst'] : [];
$vedlegg  = [];
$sum      = 0;

if (isset($_FILES['bilder']) && is_array($_FILES['bilder']['tmp_name'])) {
    $antall = count($_FILES['bilder']['tmp_name']);
    for ($i = 0; $i < $antall; $i++) {
        if (count($vedlegg) >= $MAKS_FILER) break;
        if ($_FILES['bilder']['error'][$i] !== UPLOAD_ERR_OK) {
            if ($_FILES['bilder']['error'][$i] === UPLOAD_ERR_INI_SIZE
             || $_FILES['bilder']['error'][$i] === UPLOAD_ERR_FORM_SIZE) {
                svar(false, 'Ett av bildene var for stort for serveren. Se README-drift.md om opplastingsgrensen.', 413);
            }
            continue;
        }

        $sti  = $_FILES['bilder']['tmp_name'][$i];
        $stor = (int)$_FILES['bilder']['size'][$i];
        if (!is_uploaded_file($sti) || $stor <= 0 || $stor > $MAKS_PER_FIL) continue;

        // Godtar bare det som faktisk er et bilde, ikke bare noe som heter .jpg
        $info = @getimagesize($sti);
        if ($info === false) continue;
        $type = $info['mime'];
        if (!in_array($type, ['image/jpeg', 'image/png', 'image/webp'], true)) continue;

        $sum += $stor;
        if ($sum > $MAKS_SUM) {
            svar(false, 'Bildene er til sammen for store. Fjern ett eller to og prøv igjen.', 413);
        }

        $endelse = $type === 'image/png' ? 'png' : ($type === 'image/webp' ? 'webp' : 'jpg');
        $vedlegg[] = [
            'navn'  => 'bilde-' . (count($vedlegg) + 1) . '-' . slugg($regnr) . '.' . $endelse,
            'type'  => $type,
            'data'  => file_get_contents($sti),
            'tekst' => isset($tekster[$i]) ? reintekst($tekster[$i], 80) : '',
        ];
    }
}

/* ── E-posten ────────────────────────────────────────────────────────────── */

$linjer = [
    'Ny forespørsel om taksttime fra askade.no',
    str_repeat('=', 42),
    '',
    'Navn:                ' . $navn,
    'Telefon:             ' . $telefon,
    'E-post:              ' . $epost,
    'Registreringsnummer: ' . strtoupper($regnr),
    'Skadenummer:         ' . ($skadenr !== '' ? $skadenr : '—'),
    'Bilmerke og modell:  ' . ($modell !== '' ? $modell : '—'),
    '',
    'Beskrivelse av skaden',
    str_repeat('-', 42),
    $melding,
    '',
];

if ($vedlegg) {
    $linjer[] = 'Vedlagte bilder (' . count($vedlegg) . ')';
    $linjer[] = str_repeat('-', 42);
    foreach ($vedlegg as $n => $v) {
        $linjer[] = ($n + 1) . '. ' . $v['navn'] . ($v['tekst'] !== '' ? '  —  ' . $v['tekst'] : '');
    }
} else {
    $linjer[] = 'Ingen bilder lagt ved.';
}

$linjer[] = '';
$linjer[] = 'Sendt ' . date('d.m.Y H:i') . ' · Svar på denne e-posten for å svare kunden direkte.';

$tekst = implode("\r\n", $linjer);
$emne  = $EMNE_PREFIKS . ' — ' . strtoupper($regnr) . ' — ' . $navn;

$grense   = '=_ask_' . bin2hex(random_bytes(12));
$overskr  = [
    'MIME-Version: 1.0',
    'Content-Type: multipart/mixed; boundary="' . $grense . '"',
    'From: ' . mimeOrd($AVSENDER_NAVN) . ' <' . $AVSENDER . '>',
    'Reply-To: ' . mimeOrd($navn) . ' <' . $epost . '>',
    'X-Mailer: askade-skjema',
];

$kropp  = "--$grense\r\n";
$kropp .= "Content-Type: text/plain; charset=UTF-8\r\n";
$kropp .= "Content-Transfer-Encoding: base64\r\n\r\n";
$kropp .= chunk_split(base64_encode($tekst)) . "\r\n";

foreach ($vedlegg as $v) {
    $kropp .= "--$grense\r\n";
    $kropp .= 'Content-Type: ' . $v['type'] . '; name="' . $v['navn'] . "\"\r\n";
    $kropp .= "Content-Transfer-Encoding: base64\r\n";
    $kropp .= 'Content-Disposition: attachment; filename="' . $v['navn'] . "\"\r\n\r\n";
    $kropp .= chunk_split(base64_encode($v['data'])) . "\r\n";
}
$kropp .= "--$grense--\r\n";

$sendt = @mail(
    $MOTTAKER,
    mimeOrd($emne),
    $kropp,
    implode("\r\n", $overskr),
    '-f' . $AVSENDER
);

if (!$sendt) {
    error_log('askade: mail() feilet for ' . $epost);
    svar(false, 'Serveren klarte ikke å sende e-posten.', 500);
}

tellOpp();
svar(true);

/* ── Småting ─────────────────────────────────────────────────────────────── */

function post($navn) {
    return isset($_POST[$navn]) && is_string($_POST[$navn]) ? $_POST[$navn] : '';
}

/** Fjerner linjeskift (hindrer innsmugling av e-postoverskrifter) og kutter lengden. */
function reintekst($verdi, $maks) {
    $verdi = str_replace(["\r", "\n", "\0"], ' ', (string)$verdi);
    $verdi = trim(preg_replace('/\s+/u', ' ', $verdi));
    return mb_substr($verdi, 0, $maks, 'UTF-8');
}

function slugg($verdi) {
    $verdi = preg_replace('/[^A-Za-z0-9]+/', '', $verdi);
    return $verdi !== '' ? strtolower($verdi) : 'ukjent';
}

/** UTF-8 i emne og navn må kodes for at æ, ø og å skal vises riktig. */
function mimeOrd($tekst) {
    if (preg_match('/^[\x20-\x7E]*$/', $tekst)) return $tekst;
    return '=?UTF-8?B?' . base64_encode($tekst) . '?=';
}

function tellerFil() {
    $ip  = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0';
    $dir = is_writable(__DIR__) ? __DIR__ . '/.skjemalogg' : sys_get_temp_dir() . '/askade-skjema';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    return is_dir($dir) && is_writable($dir) ? $dir . '/' . sha1($ip) . '.txt' : null;
}

function innenforGrense($maks) {
    $fil = tellerFil();
    if ($fil === null || !is_file($fil)) return true;
    $tider = array_filter(array_map('intval', explode(',', (string)@file_get_contents($fil))));
    $siste = array_filter($tider, function ($t) { return $t > time() - 3600; });
    return count($siste) < $maks;
}

function tellOpp() {
    $fil = tellerFil();
    if ($fil === null) return;
    $tider = is_file($fil)
        ? array_filter(array_map('intval', explode(',', (string)@file_get_contents($fil))))
        : [];
    $tider = array_filter($tider, function ($t) { return $t > time() - 3600; });
    $tider[] = time();
    @file_put_contents($fil, implode(',', $tider), LOCK_EX);
}
