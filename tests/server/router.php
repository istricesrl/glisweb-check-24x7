<?php

    /**
     * router del server PHP integrato usato dai test d'integrazione
     *
     * /glisweb-check-24x7/vista.php    la vista vera, www/vista.php, con la configurazione di GLISWEB_CHECK_CONFIG
     * /ok                              un sito su, che risponde OK
     * /lento                           un sito su che risponde OK dopo un secondo e mezzo
     * /altro                           la vista finta di un altro server, il file di TEST_ALTRO
     * tutto il resto                   404
     *
     */

    $percorso = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );

    if( $percorso === '/glisweb-check-24x7/vista.php' ) {
        require dirname( __DIR__, 2 ) . '/www/vista.php';
    } elseif( $percorso === '/ok' ) {
        echo 'OK';
    } elseif( $percorso === '/lento' ) {
        usleep( 1500000 );
        echo 'OK';
    } elseif( $percorso === '/altro' && getenv( 'TEST_ALTRO' ) && is_readable( getenv( 'TEST_ALTRO' ) ) ) {
        header( 'Content-type: application/json' );
        readfile( getenv( 'TEST_ALTRO' ) );
    } else {
        http_response_code( 404 );
        echo 'non trovato';
    }
