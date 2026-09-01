# Ålesund Skadesenter AS — nettside

Skisse A «Mørk fasade», bygget om fra designutkastet til vanlig HTML, CSS, JavaScript
og én PHP-fil. Ingen rammeverk, ingen byggesteg, ingen database. Filene lastes opp
som de er.

---

## Innhold

```
index.html          Forsiden
personvern.html     Personvernerklæring (lenket fra skjemaet og bunnen)
takk.html           Kvittering — vises bare for besøkende uten JavaScript
send.php            Tar imot skjemaet og sender e-post med bildene som vedlegg
css/style.css       All stil (importerer css/fonts.css)
css/fonts.css       @font-face for Barlow — skriftene ligger lokalt
js/main.js          Bildeopplasting, komprimering, validering
fonts/              Barlow og Barlow Condensed (woff2, 183 kB til sammen)
assets/             Logo, QR-kode, ikoner, delebilde
.htaccess           https, www, sikkerhetsoverskrifter, mellomlagring
.user.ini           Opplastingsgrenser for PHP (FastCGI)
robots.txt          
sitemap.xml         
favicon.ico         
```

## Legg opp hos Domene.no (Web5)

1. Logg inn på Domene.no → **Web5** → filbehandler eller FTP/SFTP.
2. Last opp **innholdet i denne mappen** (ikke mappen selv) til `public_html/`
   eller `www/` — den katalogen som er dokumentrot for askade.no.
   Husk å ta med `.htaccess` og `.user.ini`; mange FTP-program skjuler filer
   som begynner med punktum. Slå på «vis skjulte filer».
3. Sett PHP-versjon til 8.0 eller nyere i kontrollpanelet.
4. Åpne `https://www.askade.no/` og send en testforespørsel gjennom skjemaet.
   Den skal komme i postkassen `post@askade.no` innen et minutt eller to.

### Om e-posten ikke kommer fram

- Sjekk søppelpost først.
- `send.php` sender med avsender `post@askade.no`. Den adressen **må** ligge på
  askade.no, ellers stopper mottakerens SPF-sjekk e-posten. Skal du bytte
  mottaker, endre `$MOTTAKER` øverst i `send.php` — la `$AVSENDER` stå.
- Domene.no krever på noen pakker at PHP sender via deres SMTP-tjener i stedet
  for `mail()`. Da svarer siden «Serveren klarte ikke å sende e-posten».
  Ta kontakt med support og be om at `mail()` er åpen for webhotellet.

### Opplastingsgrenser

Bildene komprimeres i nettleseren til maks 1600 px og ca. 600 kB hver, så seks
bilder havner rundt 3 MB. `.user.ini` setter likevel `upload_max_filesize 8M`
og `post_max_size 20M` som slingringsmonn. Virker ikke `.user.ini` hos
Domene.no, står de samme verdiene i `.htaccess` (`mod_php`), og ellers kan de
settes i kontrollpanelet under PHP-innstillinger.

## Det som gjenstår

- **Bilde fra verkstedhallen.** «Om bedriften» viser en plassholder til du
  legger inn `assets/verksted.jpg`. Bytt så plassholder-`<div>`-en i
  `index.html` (søk etter `photo-placeholder`) med:
  `<img src="assets/verksted.jpg" alt="Verkstedhallen til Ålesund Skadesenter" width="1600" height="1000">`
  Bildet bør være rundt 1600 × 1000 px og under 300 kB.
- **Google Business-profil.** For et verksted gir en oppdatert Google-profil
  med adresse, åpningstider og bilder som regel mer trafikk enn selve
  nettsiden. Lenk til `https://www.askade.no/` derfra.
- **Meld siden inn i Google Search Console** og send inn `sitemap.xml`.

## Endre innhold seinere

Alt innholdet står som vanlig tekst i `index.html`. Telefonnummeret står fire
steder (toppmeny/bunn/kontakt/kvittering) — søk etter `48409912` og
`484 09 912` om det skal byttes. Åpningstider, adresse og tjenestetekstene
ligger i hver sin seksjon med tydelige `id`-er: `#tjenester`, `#om`, `#book`,
`#kontakt`.

## Personvern

Skjemaet lagres ikke på serveren — innholdet går rett videre som e-post.
Kartet fra Google Maps lastes automatisk og setter informasjonskapsler fra
Google. Skriftene ligger lokalt, så det går
ingen forespørsel til Google Fonts. Det er ingen analyse- eller sporingsverktøy
på siden. `personvern.html` beskriver dette — les den gjennom og rett hvis noe
ikke stemmer med hvordan dere faktisk jobber.

`send.php` skriver en liten tellefil i `.skjemalogg/` for å bremse
spam-roboter (maks åtte innsendinger per IP-adresse per time). Katalogen
inneholder bare tidsstempler, ingen personopplysninger, og er sperret for
innsyn i `.htaccess`.
