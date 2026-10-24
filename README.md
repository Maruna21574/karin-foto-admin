# Karin foto admin

Galérie pre klientov fotografky Attelier Kay. Fotografka nahrá fotky do galérie (môže ich deliť do sekcií),
klient dostane odkaz a na ňom fotky prezrie, označí obľúbené a stiahne (jednu fotku, celú galériu, sekciu alebo len obľúbené).

Čistý PHP bez frameworku a bez nutnosti inštalovať čokoľvek cez Composer. Databáza je jeden súbor SQLite.

## Požiadavky

- PHP 8.1 alebo novšie
- rozšírenia PHP: `pdo_sqlite`, `gd` (s podporou **WebP** a **FreeType**), `zip`, `mbstring`
- webový server s prepisovaním adries (Apache s `.htaccess`, alebo Nginx s ekvivalentným pravidlom)

## Nasadenie

1. Nahraj projekt na server mimo verejného koreňa a nasmeruj doménu (napr. `photos.attelierkay.sk`)
   na priečinok **`public/`**. Priečinky `app/`, `views/` a `storage/` nesmú byť dostupné z webu.
2. Skopíruj `config.example.php` ako `config.php` a nastav `admin_hash` (postup je v komentári v súbore).
3. Priečinok `storage/` musí byť zapisovateľný pre PHP. Vytvorí sa v ňom databáza `db.sqlite`,
   fotky, náhľady a obrázky pre zdieľanie. Je v `.gitignore`, takže sa nikdy nedostane do gitu.
4. Zapni HTTPS. Na HTTPS sa cookie administrácie automaticky označí ako `secure`.
5. Otvor `/admin` a prihlás sa e-mailom a heslom z `config.php`.

Kontrola, či server spĺňa požiadavky: dočasne dať do `public/` súbor `info.php` s `<?php phpinfo();`,
pozrieť sekcie `pdo_sqlite`, `gd`, `zip`, `mbstring` a súbor hneď zmazať.

## Ako to funguje

| Adresa | Čo je |
|---|---|
| `/admin` | administrácia (e-mail + heslo) |
| `/g/<token>` | odkaz pre klienta (dlhý náhodný kód, nedá sa uhádnuť) |
| `/<vlastna-adresa>` | voliteľná krátka adresa galérie, nastaví sa v administrácii |
| `/<adresa>/og.jpg` | obrázok pre náhľad pri zdieľaní odkazu (logo, názov, telefón) |

- Fotky sa zmenšia už v prehliadači pred nahratím (najdlhšia strana `max_full_px`), server z nich robí
  WebP náhľady. Pôvodný obrovský súbor sa neukladá.
- Prihlásenie je chránené: po 5 zlých pokusoch z jednej adresy sa zamkne na 15 minút.
- Galéria nemá heslo. Kto má odkaz, vidí fotky. Pri krátkej vlastnej adrese použi „náhodný kód“.

## Zálohovanie

Všetko dôležité je v priečinku `storage/` (databáza aj fotky). Stačí ho pravidelne kopírovať.

## Štruktúra

```
app/        logika (bootstrap, admin, client, og) a písma pre obrázok na zdieľanie
views/      šablóny stránok
public/     verejný koreň: index.php (router), assets/ (css, js, logo)
storage/    dáta (mimo gitu)
config.php  nastavenia a heslo (mimo gitu, vzor je config.example.php)
```
