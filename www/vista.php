<?php

    /**
     * la vista di questo server per gli altri server del cluster
     *
     * Restituisce lo stato.json della cartella dei dati a chi presenta nell'header X-Glisweb-Uptime il token di
     * /etc/glisweb/uptime.yaml; a tutti gli altri risponde 403 senza dire niente, perché la vista contiene i nomi dei
     * siti dei clienti. La serve Apache con l'alias /glisweb-check-24x7/ che scrive bin/install: non passa da nessun
     * deploy, così risponde anche se il deploy che ospita quel nome di host è rotto o ha il database giù.
     *
     * La variabile d'ambiente GLISWEB_CHECK_CONFIG sostituisce il file di configurazione: la usano i test.
     *
     * licenza
     * =======
     * Questo file fa parte del progetto glisweb-check-24x7 (https://github.com/istricesrl/glisweb-check-24x7) ed è
     * distribuito con la licenza del framework GlisWeb, in LICENSE.md.
     *
     */

    // libreria
    require_once dirname( __DIR__ ) . '/src/uptime.php';

    // nessuna cache, mai
    header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0, s-maxage=0' );

    // configurazione e token
    $conf = uptimeLeggiConfig( getenv( 'GLISWEB_CHECK_CONFIG' ) ? getenv( 'GLISWEB_CHECK_CONFIG' ) : UPTIME_CONFIG );
    $token = isset( $_SERVER['HTTP_X_GLISWEB_UPTIME'] ) ? (string) $_SERVER['HTTP_X_GLISWEB_UPTIME'] : '';

    if( $conf === NULL || empty( $conf['token'] ) || ! hash_equals( (string) $conf['token'], $token ) ) {
        http_response_code( 403 );
        exit;
    }

    // la vista
    if( ! is_readable( $conf['dati'] . 'stato.json' ) ) {
        http_response_code( 404 );
        exit;
    }

    header( 'Content-type: application/json' );
    readfile( $conf['dati'] . 'stato.json' );
