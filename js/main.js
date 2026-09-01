/* Ålesund Skadesenter AS — bookingskjema, bildekomprimering og kartsamtykke.
   Ingen rammeverk, ingen byggesteg. Fungerer uten JavaScript også, bortsett
   fra bildeopplasting (da får kunden beskjed om å ettersende bilder på e-post). */

(function () {
  "use strict";

  /* ── Bildefelt ─────────────────────────────────────────────────────────── */

  var FELT = [
    "1 · Bilfront med synlig registreringsnummer",
    "2 · Kilometerstand på odometeret",
    "3 · Godkjent slitebane på dekk",
    "4 · Skaden sett på avstand",
    "5 · Nærbilde av skaden",
    "6 · Skaden fra flere vinkler"
  ];

  var MAKS_KANT = 1600;       // px på lengste side
  var MAL_BYTES = 600 * 1024; // ca. 600 kB per bilde
  var MAKS_SUM = 15 * 1024 * 1024;

  var bilder = new Map(); // indeks -> { blob, navn }
  var beholder = document.getElementById("bildefelt");

  function lagSlot(i) {
    var slot = document.createElement("div");
    slot.className = "slot";
    var tekst = FELT[i].replace(/^\d+ · /, "");
    slot.innerHTML =
      '<input type="file" accept="image/*" class="visually-hidden" id="bilde-' + i + '">' +
      '<button type="button" class="slot-btn" aria-label="Legg til bilde: ' + tekst + '">' +
        '<span class="slot-plus" aria-hidden="true">+</span>' +
        '<span aria-hidden="true">Legg til bilde</span>' +
      "</button>" +
      '<div class="slot-preview"><img alt="Forhåndsvisning: ' + tekst + '"></div>' +
      '<button type="button" class="slot-remove" hidden aria-label="Fjern bildet: ' + tekst + '">×</button>' +
      '<div class="slot-caption">' + FELT[i] + "</div>" +
      '<div class="slot-busy">Behandler …</div>';

    var input = slot.querySelector("input");
    var velg = slot.querySelector(".slot-btn");
    var fjern = slot.querySelector(".slot-remove");
    var bilde = slot.querySelector(".slot-preview img");

    velg.addEventListener("click", function () { input.click(); });

    input.addEventListener("change", function () {
      var fil = input.files && input.files[0];
      input.value = "";
      if (!fil) return;
      if (!/^image\//.test(fil.type)) {
        visStatus("«" + fil.name + "» er ikke en bildefil.", true);
        return;
      }
      slot.classList.add("is-busy");
      komprimer(fil).then(function (res) {
        bilder.set(i, { blob: res.blob, navn: filnavn(i, res.type) });
        if (bilde.src.indexOf("blob:") === 0) URL.revokeObjectURL(bilde.src);
        bilde.src = URL.createObjectURL(res.blob);
        slot.classList.add("has-image");
        fjern.hidden = false;
        skjulStatus();
      }).catch(function () {
        visStatus("Klarte ikke å lese bildet. Prøv et annet bilde, eller send det på e-post.", true);
      }).then(function () {
        slot.classList.remove("is-busy");
      });
    });

    fjern.addEventListener("click", function () {
      bilder.delete(i);
      if (bilde.src.indexOf("blob:") === 0) URL.revokeObjectURL(bilde.src);
      bilde.removeAttribute("src");
      slot.classList.remove("has-image");
      fjern.hidden = true;
    });

    return slot;
  }

  function filnavn(i, type) {
    var endelse = type === "image/png" ? "png" : "jpg";
    return "bilde-" + (i + 1) + "." + endelse;
  }

  /* Skalerer ned og komprimerer i nettleseren, slik at seks bilder
     havner godt under vedleggsgrensen i e-post (typisk 10–20 MB). */
  function komprimer(fil) {
    return lesBilde(fil).then(function (kilde) {
      var skala = Math.min(1, MAKS_KANT / Math.max(kilde.width, kilde.height));
      var b = Math.max(1, Math.round(kilde.width * skala));
      var h = Math.max(1, Math.round(kilde.height * skala));

      var lerret = document.createElement("canvas");
      lerret.width = b;
      lerret.height = h;
      var ctx = lerret.getContext("2d");
      ctx.drawImage(kilde, 0, 0, b, h);
      if (kilde.close) kilde.close();

      return trinnvis(lerret, 0.82);
    });
  }

  function trinnvis(lerret, kvalitet) {
    return tilBlob(lerret, kvalitet).then(function (blob) {
      if (!blob) throw new Error("blob");
      if (blob.size <= MAL_BYTES || kvalitet <= 0.45) {
        return { blob: blob, type: "image/jpeg" };
      }
      return trinnvis(lerret, kvalitet - 0.12);
    });
  }

  function tilBlob(lerret, kvalitet) {
    return new Promise(function (ok) {
      lerret.toBlob(function (b) { ok(b); }, "image/jpeg", kvalitet);
    });
  }

  function lesBilde(fil) {
    // createImageBitmap retter opp EXIF-rotasjon fra mobilkamera.
    if (window.createImageBitmap) {
      return createImageBitmap(fil, { imageOrientation: "from-image" })
        .catch(function () { return viaImg(fil); });
    }
    return viaImg(fil);
  }

  function viaImg(fil) {
    return new Promise(function (ok, feil) {
      var url = URL.createObjectURL(fil);
      var img = new Image();
      img.onload = function () { URL.revokeObjectURL(url); ok(img); };
      img.onerror = function () { URL.revokeObjectURL(url); feil(new Error("dekoding")); };
      img.src = url;
    });
  }

  if (beholder) {
    var noscript = beholder.querySelector("noscript");
    if (noscript) noscript.remove();
    for (var i = 0; i < FELT.length; i++) beholder.appendChild(lagSlot(i));
  }

  /* ── Skjema ────────────────────────────────────────────────────────────── */

  var skjema = document.getElementById("bookingSkjema");
  var status = document.getElementById("skjemaStatus");
  var sendKnapp = document.getElementById("sendKnapp");
  var skjemaBoks = document.getElementById("skjemaBoks");
  var kvittering = document.getElementById("kvittering");
  var lastet = document.getElementById("lastet");

  if (lastet) lastet.value = String(Math.floor(Date.now() / 1000));

  var PAAKREVD = {
    navn: "Fyll inn navn.",
    telefon: "Fyll inn telefonnummer så vi kan ringe deg.",
    epost: "Fyll inn e-postadresse.",
    regnr: "Fyll inn registreringsnummer.",
    melding: "Beskriv skaden kort."
  };

  function visStatus(tekst, erFeil) {
    if (!status) return;
    status.textContent = tekst;
    status.classList.toggle("is-error", !!erFeil);
    status.hidden = false;
  }

  function skjulStatus() {
    if (status) status.hidden = true;
  }

  function settFeil(navn, tekst) {
    var felt = document.getElementById(navn);
    var boks = document.getElementById(navn + "-feil");
    if (felt) felt.setAttribute("aria-invalid", tekst ? "true" : "false");
    if (boks) {
      boks.textContent = tekst || "";
      boks.classList.toggle("is-visible", !!tekst);
    }
  }

  function valider() {
    var forste = null;
    Object.keys(PAAKREVD).forEach(function (navn) {
      var felt = document.getElementById(navn);
      var verdi = felt ? felt.value.trim() : "";
      var feil = "";
      if (!verdi) {
        feil = PAAKREVD[navn];
      } else if (navn === "epost" && !/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(verdi)) {
        feil = "Sjekk e-postadressen.";
      } else if (navn === "telefon" && verdi.replace(/\D/g, "").length < 8) {
        feil = "Telefonnummeret ser for kort ut.";
      }
      settFeil(navn, feil);
      if (feil && !forste) forste = felt;
    });
    return forste;
  }

  if (skjema) {
    skjema.addEventListener("submit", function (e) {
      e.preventDefault();

      var forste = valider();
      if (forste) {
        visStatus("Noen felt mangler. Se de merkede feltene over.", true);
        forste.focus();
        return;
      }

      var sum = 0;
      bilder.forEach(function (b) { sum += b.blob.size; });
      if (sum > MAKS_SUM) {
        visStatus("Bildene er til sammen for store. Fjern ett eller to og prøv igjen.", true);
        return;
      }

      var data = new FormData();
      data.append("js", "1");
      ["navn", "telefon", "epost", "regnr", "skadenr", "modell", "melding", "nettsted", "lastet"].forEach(function (n) {
        var felt = document.getElementById(n === "nettsted" ? "nettsted" : n);
        if (felt) data.append(n, felt.value);
      });
      bilder.forEach(function (b, idx) {
        data.append("bilder[]", b.blob, b.navn);
        data.append("bildetekst[]", FELT[idx]);
      });

      sendKnapp.disabled = true;
      var opprinnelig = sendKnapp.textContent;
      sendKnapp.textContent = "Sender …";
      visStatus("Sender forespørselen …", false);

      fetch(skjema.action, { method: "POST", body: data })
        .then(function (svar) {
          return svar.json().catch(function () { throw new Error("svar"); })
            .then(function (json) {
              if (!svar.ok || !json.ok) throw new Error(json.feil || "ukjent");
              return json;
            });
        })
        .then(function () {
          skjemaBoks.hidden = true;
          kvittering.hidden = false;
          kvittering.scrollIntoView({ block: "center" });
          kvittering.querySelector("h3").setAttribute("tabindex", "-1");
          kvittering.querySelector("h3").focus();
        })
        .catch(function (err) {
          visStatus(
            "Forespørselen kom ikke fram (" + (err.message || "feil") + "). " +
            "Ring oss på 484 09 912 eller send e-post til post@askade.no.",
            true
          );
        })
        .then(function () {
          sendKnapp.disabled = false;
          sendKnapp.textContent = opprinnelig;
        });
    });
  }

  var nyKnapp = document.getElementById("nyForesporsel");
  if (nyKnapp) {
    nyKnapp.addEventListener("click", function () {
      skjema.reset();
      bilder.clear();
      Array.prototype.forEach.call(beholder.querySelectorAll(".slot"), function (slot) {
        var img = slot.querySelector(".slot-preview img");
        if (img.src.indexOf("blob:") === 0) URL.revokeObjectURL(img.src);
        img.removeAttribute("src");
        slot.classList.remove("has-image");
        slot.querySelector(".slot-remove").hidden = true;
      });
      Object.keys(PAAKREVD).forEach(function (n) { settFeil(n, ""); });
      skjulStatus();
      if (lastet) lastet.value = String(Math.floor(Date.now() / 1000));
      kvittering.hidden = true;
      skjemaBoks.hidden = false;
      document.getElementById("navn").focus();
    });
  }

  /* ── Kart: lastes først når kunden trykker (ingen Google-kapsler før det) ── */

  var kartboks = document.getElementById("kartboks");
  var visKart = document.getElementById("visKart");
  if (kartboks && visKart) {
    visKart.addEventListener("click", function () {
      var ramme = document.createElement("iframe");
      ramme.src = kartboks.dataset.kart;
      ramme.title = "Kart over Puskholevegen 47B, 6012 Ålesund";
      ramme.loading = "lazy";
      ramme.referrerPolicy = "no-referrer-when-downgrade";
      ramme.setAttribute("allowfullscreen", "");
      kartboks.innerHTML = "";
      kartboks.appendChild(ramme);
      try { localStorage.setItem("kart-ok", "1"); } catch (e) {}
    });

    try {
      if (localStorage.getItem("kart-ok") === "1") visKart.click();
    } catch (e) {}
  }
})();
