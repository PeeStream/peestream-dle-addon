# PeeStream Video Player & Tools - DataLife Engine (DLE) Addon

Zvanični modul i dodatak za integraciju **PeeStream** video platforme u **DataLife Engine (DLE)** CMS sisteme (verzije 13.0 do 17.3+).

---

## 1. Mogućnosti dodatka
- **Prikaz video plejera:** Brza i responzivna reprodukcija preko `https://peeplayer.com` domena (16:9 fluidni omotač).
- **Potpuna monetizacija:** Svi embedovani videi automatski prikazuju PeeStream reklame (VAST pre-roll, popunder sloj, anti-adblock zaštita) sa atribucijom prihoda uploaderu.
- **Jednostavno povezivanje:** Unos PeeStream API ključa automatski povlači stanje balansa, broj videa, ukupan broj pregleda i listu foldera.
- **Kompatibilnost:** Podržava standardne DLE xfield (dodatna polja) i šablonske oznake.

---

## 2. Načini instalacije

### Metod A: Automatska instalacija kroz DLE Plugin Manager (Preporučeno)
1. Prijavite se u DLE administratorski panel.
2. Otvorite meni **Управление плагинами (Plugin Management)**.
3. Kliknite na dugme **Загрузить плагин (Upload plugin)** i izaberite fajl `peestream.xml`.
4. Kopirajte sadržaj foldera `upload/` na vaš server (u root direktorijum sajta).
5. Plugin je instaliran! U meniju admin panela pojaviće se stavka **PeeStream Player**.

### Metod B: Ručna instalacija (FTP)
1. Prekopirajte sadržaj foldera `upload/` u korijenski (root) direktorijum vaše DLE instalacije.
2. Podesite CHMOD dozvole za fajl `engine/data/peestream.conf.php` na `0666` (ili `0777`).
3. Pristupite modulu direktno putem adrese:  
   `https://vas-sajt.com/admin.php?mod=peestream`

---

## 3. Podešavanja u administratorskom panelu
Otvorite **PeeStream Player** u administratorskom panelu i podesite:
1. **API ključ:** Unesite vaš lični API ključ (preuzima se na `https://peestream.com/settings#api`).
2. **Domen plejera:** `https://peeplayer.com` (podrazumijevano).
3. **Naziv dodatnog polja (XField):** Naziv polja u DLE vijestima u koje upisujete kod videa (npr. `peestream`).
4. **Responzivni plejer:** Uključeno (preporučeno, prilagođava se mobilnim telefonima i računarima).

---

## 4. Postavljanje u šablonu sajta (Template Tags)
Otvorite fajl vašeg šablona `templates/{vaša_tema}/fullstory.tpl` i na željeno mjesto postavite:

```html
[xfgiven_peestream]
<div class="peestream-video-wrapper">
    {peestream_player}
</div>
[/xfgiven_peestream]
```

### Podržane oznake:
- `{peestream_player}` - Prikazuje kompletan video plejer u definisanim dimenzijama.
- `[peestream] ... [/peestream]` - Uslovni blok koji se prikazuje samo ako vijest sadrži video.
- `[!peestream] ... [/!peestream]` - Uslovni blok koji se prikazuje samo ako vijest NEMA video.
- `{peestream_code}` - Prikazuje samo alfanumerički kod videa (korisno ako vaša tema ima prilagođene tabove za više servera).
- `{peestream_url}` - Prikazuje puni direktni embed link videa.

---

## 5. Auto-Migrator (Zamjena mrtvih linkova jednim klikom)
Ukoliko na sajtu imate hiljade članaka sa obrisanim ili problematičnim linkovima sa servisa koji propadaju, otvorite tab **Auto-Migrator**:
1. Kliknite na **Skeniraj bazu vijesti** - sistem automatski analizira tekst članaka i dodatna polja (xfields) i pronalazi linkove sa:
   - MixDrop (`mixdrop.*`)
   - Doodstream (`doodstream.com`, `dood.*`, `ds2play.com`)
   - Streamtape (`streamtape.*`, `streamta.pe`)
   - Filemoon (`filemoon.*`)
   - VOE (`voe.sx`)
   - Vidguard, Luluvido i Upstream.
2. Kliknite na **Pokreni automatsku migraciju** - DLE modul šalje linkove na PeeStream API za preuzimanje, a u bazi vašeg sajta automatski zamjenjuje stare linkove funkcionalnim PeeStream plejerom bez prekida u radu i bez gubitka posjetilaca!

---

## 6. Auto-Publish Webhook (Automatski prijem novih epizoda i filmova)
PeeStream omogućava automatsko objavljivanje novih epizoda serija bez ručnog rada:
1. U vašem PeeStream uploader panelu (u podešavanjima serije ili foldera) unesite Webhook URL:
   `https://vas-sajt.com/engine/ajax/peestream_webhook.php?secret=VAŠ_SECRET`
2. Čim PeeServer obradi novu epizodu, DLE modul automatski pronalazi odgovarajući članak po IMDb ID-u i ažurira plejer novom epizodom uz osvježavanje datuma!


