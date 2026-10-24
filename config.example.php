<?php
// Skopíruj tento súbor ako config.php a doplň heslo. Skutočný config.php sa do gitu nedáva.
//
// Heslo do administrácie sa ukladá len ako hash. Vygeneruješ ho príkazom:
//   php -r "echo password_hash('tvoje-heslo', PASSWORD_DEFAULT);"
// a výsledok vložíš do 'admin_hash' (v jednoduchých úvodzovkách).
return [
    'site_name' => 'Attelier Kay',
    'website'   => 'https://www.attelierkay.sk/',

    // Kontakt, ktorý klient vidí pod fotkami a v náhľade pri zdieľaní odkazu
    'phone'     => '0919 192 708',
    'email'     => 'info@attelierkay.sk',
    'booking_url' => 'https://www.attelierkay.sk/kontakt/',
    'instagram' => 'https://www.instagram.com/attelierkay/',
    'facebook'  => 'https://www.facebook.com/people/Attelier-Kay/61579286870394/',

    // Najdlhšia strana stiahnuteľnej fotky v px. Väčšie fotky sa zmenšia (už v prehliadači pri nahrávaní).
    'max_full_px' => 4000,

    // Prihlásenie do administrácie
    'admin_email' => 'info@attelierkay.sk',
    'admin_hash'  => '',
];
