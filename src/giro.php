<?php

    /**
     * giro di un minuto del controllo di uptime
     *
     * Questo file è l'entry point PHP di bin/check e non va lanciato direttamente.
     *
     * introduzione
     * ============
     * Legge la configurazione del cluster da /etc/glisweb/uptime.yaml, prende le viste degli altri server, controlla i
     * siti glisweb di questo server e quelli che gli altri pubblicano, decide chi è giù, scrive il registro e il
     * DOWNTIME.md dei progetti coinvolti e manda gli avvisi. La vista di questo server resta in stato.json, nella
     * cartella dei dati, e www/vista.php la serve agli altri server del cluster.
     *
     * opzioni
     * =======
     * opzione              | effetto
     * ---------------------|---------------------------------------------------------------
     * --secco              | controlla e stampa, senza scrivere niente e senza avvisare
     * --prova-avviso       | manda una mail di prova agli indirizzi avvisa.noi e si ferma
     * --config FILE        | legge la configurazione da FILE invece che da /etc/glisweb/uptime.yaml
     *
     * dipendenze
     * ==========
     * Non usa niente del framework glisweb né di un deploy: è il punto del progetto, un controllo di uptime non deve
     * dipendere da quello che controlla. Servono src/uptime.php e, per gli avvisi, l'autoload di composer.
     *
     * licenza
     * =======
     * Questo file fa parte del progetto glisweb-check-24x7 (https://github.com/istricesrl/glisweb-check-24x7) ed è
     * distribuito con la licenza del framework GlisWeb, in LICENSE.md.
     *
     */

    // librerie
    if( file_exists( dirname( __DIR__ ) . '/vendor/autoload.php' ) ) {
        require_once dirname( __DIR__ ) . '/vendor/autoload.php';
    }
    require_once __DIR__ . '/uptime.php';

    // opzioni
    $opt = getopt( '', array( 'secco', 'prova-avviso', 'config:' ) );
    $secco = isset( $opt['secco'] );
    $file = isset( $opt['config'] ) ? $opt['config'] : UPTIME_CONFIG;

    // configurazione del cluster
    if( ! function_exists( 'yaml_parse_file' ) ) {
        fwrite( STDERR, "manca l'estensione yaml di PHP\n" );
        exit( 1 );
    }
    $conf = uptimeLeggiConfig( $file );
    if( $conf === NULL ) {
        fwrite( STDERR, "configurazione assente o senza server: $file\n" );
        exit( 1 );
    }
    $io = $conf['server'];
    $noi = isset( $conf['avvisa']['noi'] ) ? $conf['avvisa']['noi'] : array();

    // prova degli avvisi
    if( isset( $opt['prova-avviso'] ) ) {
        $esito = uptimeAvvisa( $conf['smtp'], $noi, '[uptime] prova da ' . $io, 'Mail di prova di glisweb-check-24x7, mandata da ' . $io . ' il ' . date( 'd/m/Y H:i' ) . '.', $errore );
        echo 'prova a ' . implode( ', ', (array) $noi ) . ': ' . ( $esito ? 'inviata' : 'NON inviata ( ' . $errore . ' )' ) . PHP_EOL;
        exit( $esito ? 0 : 1 );
    }

    // stato del giro precedente
    $cartella = $conf['dati'];
    $ora = time();
    $prima = is_readable( $cartella . 'stato.json' ) ? json_decode( file_get_contents( $cartella . 'stato.json' ), true ) : array();
    $vista = isset( $prima['vista'] ) ? $prima['vista'] : array();

    // viste degli altri server, coi giri consecutivi in cui ognuno non l'ha data
    $altri = array();
    $assenti = array();
    foreach( $conf['cluster'] as $nome => $url ) {
        if( $nome != $io ) {
            $altri[ $nome ] = uptimeVista( $url, $conf['token'] );
            $assenti[ $nome ] = ( $altri[ $nome ] !== NULL ) ? 0 : ( isset( $prima['assenti'][ $nome ] ) ? $prima['assenti'][ $nome ] : 0 ) + 1;
        }
    }

    // i siti di questo server e quelli che pubblicano gli altri
    $scartati = array();
    $locali = uptimeSiti( $conf['radice'], uptimeVhost(), (array) $conf['escludi'], $scartati, (array) $conf['profili'] );
    $siti = array_replace( uptimeEsterni( $conf['esterni'] ), $locali );
    foreach( $altri as $nome => $a ) {
        if( $a !== NULL ) {
            foreach( $a['siti'] as $h => $s ) {
                if( ! isset( $siti[ $h ] ) ) {
                    $siti[ $h ] = array_replace( UPTIME_DEFAULT, $s, array( 'deploy' => NULL, 'origine' => $nome ) );
                }
            }
        }
    }

    // controlli e isteresi
    $esiti = uptimeControlla( $siti, $conf['paralleli'] );
    $prima_vista = $vista;
    $vista = uptimeAggiorna( $vista, $esiti, array_combine( array_keys( $siti ), array_column( $siti, 'soglia' ) ), array_combine( array_keys( $siti ), array_column( $siti, 'lento' ) ) );

    // gli avvisi di servizio ( certificato, DNS ) li manda il primo dei server che rispondono
    $presenti = array( $io );
    foreach( $altri as $nome => $a ) {
        if( $a !== NULL ) {
            $presenti[] = $nome;
        }
    }
    sort( $presenti );
    $primo = ( reset( $presenti ) === $io );

    // consenso, registro, avvisi
    $eventi = array();

    // un sito uscito dal controllo mentre era giù chiude il suo periodo, altrimenti resterebbe in corso per sempre
    foreach( array_diff_key( $prima_vista, $vista ) as $h => $v ) {
        if( isset( $v['globale'] ) && $v['globale'] !== 'su' && isset( $v['dal'] ) ) {
            $eventi[] = array( 'evento' => 'fine', 'sito' => $h, 'ora' => date( 'c', $ora ), 'inizio' => date( 'c', $v['dal'] ),
                'durata' => $ora - $v['dal'], 'confermato' => ! empty( $v['confermato'] ), 'rimosso' => true );
        }
    }
    $posta = array();

    // gli avvisi dei siti si raccolgono per tipo, destinatari e deploy, e partono una mail per gruppo: un multisito
    // con 30 siti che cade insieme manda una mail sola ( 41 siti GIMBE giù insieme tre volte l'08/11/2026 )
    $gruppi = array();
    $raccogli = function( $tipo, $a, $h, $riga ) use( &$gruppi, &$siti ) {
        $g = ! empty( $siti[ $h ]['deploy'] ) ? $siti[ $h ]['deploy'] : ( ! empty( $siti[ $h ]['esterno'] ) ? 'esterni' : ( isset( $siti[ $h ]['origine'] ) ? $siti[ $h ]['origine'] : 'altri' ) );
        $k = $tipo . '|' . json_encode( array_values( (array) $a ) ) . '|' . $g;
        if( ! isset( $gruppi[ $k ] ) ) {
            $gruppi[ $k ] = array( 'tipo' => $tipo, 'a' => $a, 'gruppo' => $g, 'righe' => array() );
        }
        $gruppi[ $k ]['righe'][ $h ] = $riga;
    };

    foreach( $vista as $h => $v ) {

        $viste = array();
        foreach( $altri as $nome => $a ) {
            $viste[ $nome ] = ( $a !== NULL && isset( $a['vista'][ $h ]['locale'] ) ) ? $a['vista'][ $h ]['locale'] : NULL;
        }

        $c = uptimeConsenso( $io, $v['locale'], $viste );
        list( $vista[ $h ], $e ) = uptimeTransizione( $h, $v, $c['stato'], $ora, $c['giu'] );
        if( $e !== NULL ) {
            $eventi[] = $e;
        }

        $avvisa = array_replace( $conf['avvisa'], isset( $siti[ $h ]['avvisa'] ) ? (array) $siti[ $h ]['avvisa'] : array() );
        $noiSito = isset( $avvisa['noi'] ) ? $avvisa['noi'] : array();
        $referente = ( ! empty( $avvisa['abilitato'] ) && ! empty( $avvisa['referente'] ) ) ? $avvisa['referente'] : array();
        $ritardo = isset( $avvisa['ritardo'] ) ? $avvisa['ritardo'] : UPTIME_DEFAULT['ritardo'];
        $s = $vista[ $h ];

        // giù confermato: un down che vede un server solo va nel registro e nella vista, senza mail, perché quasi
        // sempre è il server a non uscire in rete e non il sito ( 57 mail su 61 dal solo web02, 05-06/11/2026 )
        // NOTA si confronta con 'giu' e non con empty(): uno stato.json di prima può avere ancora 'nonconfermato'
        if( $c['mittente'] && $c['stato'] === 'giu' && ( ! isset( $s['avvisato'] ) || $s['avvisato'] !== 'giu' ) ) {
            $raccogli( 'giu', $noiSito, $h, $h . ' non risponde dal ' . date( 'd/m/Y H:i', $s['dal'] ) . ': ' . $s['errore'] . PHP_EOL
                . 'visto giu\' da: ' . implode( ', ', $c['giu'] ) . PHP_EOL );
            $vista[ $h ]['avvisato'] = $c['stato'];
        }

        // il referente, solo se abilitato e dopo il ritardo di down confermato
        if( $c['mittente'] && $c['stato'] === 'giu' && ! empty( $referente ) && empty( $s['referente'] ) && $ora - $s['dal'] >= $ritardo * 60 ) {
            $raccogli( 'referente', $referente, $h, 'Il sito ' . $h . ' non risponde dal ' . date( 'd/m/Y H:i', $s['dal'] ) . '. Ce ne stiamo gia\' occupando.' . PHP_EOL );
            $vista[ $h ]['referente'] = true;
        }

        // di nuovo su: lo dice chi aveva avvisato, a chi era stato avvisato
        if( $e !== NULL && $e['evento'] === 'fine' && ! empty( $v['avvisato'] ) ) {
            $corpo = $h . ' risponde di nuovo dal ' . date( 'd/m/Y H:i', $ora ) . ', dopo ' . ceil( $e['durata'] / 60 ) . ' minuti.' . PHP_EOL;
            $raccogli( 'su', $noiSito, $h, $corpo );
            if( ! empty( $v['referente'] ) ) {
                $raccogli( 'raggiungibile', $referente, $h, $corpo );
            }
        }

        // lento: inizio e fine vanno solo nel registro, senza mail
        $era = ! empty( $prima_vista[ $h ]['lento'] );
        if( $s['lento'] !== $era ) {
            $eventi[] = array( 'evento' => ( $s['lento'] ) ? 'lento' : 'normale', 'sito' => $h, 'ora' => date( 'c', $ora ), 'tempo' => $s['tempo'] );
        }

        // certificato in scadenza: una volta al giorno, solo a noi
        if( $primo && $s['certificato'] !== NULL && $s['certificato'] < $siti[ $h ]['certificato'] && ( ! isset( $s['avvertito'] ) || $s['avvertito'] !== date( 'Y-m-d', $ora ) ) ) {
            $raccogli( 'certificato', $noiSito, $h, 'Il certificato di ' . $h . ' scade fra ' . $s['certificato'] . ' giorni.' . PHP_EOL );
            $vista[ $h ]['avvertito'] = date( 'Y-m-d', $ora );
        }

    }

    // un gruppo di un sito solo tiene l'oggetto di sempre, uno di più siti li conta nell'oggetto e li elenca nel corpo
    $oggetti = array( 'giu' => array( ' GIU\'', 'siti GIU\'' ), 'referente' => array( ' non raggiungibile', 'siti non raggiungibili' ),
        'su' => array( ' di nuovo su', 'siti di nuovo su' ), 'raggiungibile' => array( ' di nuovo raggiungibile', 'siti di nuovo raggiungibili' ),
        'certificato' => array( ': certificato in scadenza', 'certificati in scadenza' ) );
    foreach( $gruppi as $gr ) {
        $n = count( $gr['righe'] );
        if( $n === 1 ) {
            $h = key( $gr['righe'] );
            $oggetto = ( $gr['tipo'] === 'certificato' ) ? '[uptime] ' . $h . ': il certificato scade fra ' . $vista[ $h ]['certificato'] . ' giorni' : '[uptime] ' . $h . $oggetti[ $gr['tipo'] ][0];
        } else {
            $oggetto = '[uptime] ' . ( ( $gr['tipo'] === 'referente' || $gr['tipo'] === 'raggiungibile' ) ? '' : $gr['gruppo'] . ': ' ) . $n . ' ' . $oggetti[ $gr['tipo'] ][1];
        }
        $posta[] = array( $gr['a'], $oggetto, implode( ( $n === 1 ) ? '' : PHP_EOL, $gr['righe'] ) );
    }

    // se il server ha dei siti ma nessuno risulta pubblico, il problema è più probabilmente il DNS del server che i
    // siti: senza questo avviso il controllo si spegnerebbe in silenzio
    $irrisolti = array_keys( $scartati, 'dns' );
    if( empty( $locali ) && ! empty( $irrisolti ) && $primo && ( ! isset( $prima['dns'] ) || $prima['dns'] !== date( 'Y-m-d', $ora ) ) ) {
        $posta[] = array( $noi, '[uptime] ' . $io . ': nessun sito risolto nel DNS',
            'Nessuno dei ' . count( $irrisolti ) . ' siti da controllare di ' . $io . ' si risolve nel DNS pubblico: controllare il resolver del server.' . PHP_EOL );
        $dns = date( 'Y-m-d', $ora );
    } else {
        $dns = isset( $prima['dns'] ) ? $prima['dns'] : NULL;
    }

    // la sezione esterni: deve essere uguale su tutti i server: se un altro ne pubblica un'impronta diversa, una volta al giorno
    $impronta = uptimeImpronta( $conf['esterni'] );
    $diversi = array();
    foreach( $altri as $nome => $a ) {
        if( $a !== NULL && isset( $a['impronta'] ) && $a['impronta'] !== $impronta ) {
            $diversi[] = $nome;
        }
    }
    if( ! empty( $diversi ) && $primo && ( ! isset( $prima['divergenza'] ) || $prima['divergenza'] !== date( 'Y-m-d', $ora ) ) ) {
        $posta[] = array( $noi, '[uptime] esterni: diversi fra ' . $io . ' e ' . implode( ', ', $diversi ),
            'La sezione esterni: di ' . $file . ' di ' . $io . ' non e\' uguale a quella di ' . implode( ', ', $diversi ) . ': i siti esterni non li controllano tutti allo stesso modo. Va riscritta uguale su tutti i server.' . PHP_EOL );
        $divergenza = date( 'Y-m-d', $ora );
    } else {
        $divergenza = isset( $prima['divergenza'] ) ? $prima['divergenza'] : NULL;
    }

    // un server che non dà la sua vista da assenza giri lascia il cluster senza secondo parere: i down restano non
    // confermati e non avvisano, quindi lo si dice una volta al giorno, invece di una mail per sito
    $soli = array_keys( array_filter( $assenti, function( $n ) use( $conf ) { return $n >= $conf['assenza']; } ) );
    if( ! empty( $soli ) && $primo && ( ! isset( $prima['isolamento'] ) || $prima['isolamento'] !== date( 'Y-m-d', $ora ) ) ) {
        $posta[] = array( $noi, '[uptime] ' . $io . ': cluster senza secondo parere',
            implode( ', ', $soli ) . ' non da\' la sua vista a ' . $io . ' da ' . $conf['assenza'] . ' giri o piu\': i down che ' . $io . ' vede da solo restano non confermati e non mandano mail. Controllare che ' . implode( ', ', $soli ) . ' sia acceso e raggiungibile, e la rete di ' . $io . '.' . PHP_EOL );
        $isolamento = date( 'Y-m-d', $ora );
    } else {
        $isolamento = isset( $prima['isolamento'] ) ? $prima['isolamento'] : NULL;
    }

    // a secco si stampa e ci si ferma
    if( $secco ) {
        foreach( $vista as $h => $v ) {
            echo str_pad( $h, 40 ) . ' ' . str_pad( $v['globale'], 14 ) . ' ' . $v['fallimenti'] . ' ' . $v['tempo'] . 's ' . ( $v['certificato'] === NULL ? '' : $v['certificato'] . 'gg ' ) . $v['errore'] . PHP_EOL;
        }
        foreach( $scartati as $h => $motivo ) {
            echo 'scartato ( ' . $motivo . ' ): ' . $h . PHP_EOL;
        }
        foreach( $altri as $nome => $a ) {
            echo 'vista di ' . $nome . ': ' . ( $a === NULL ? 'NON ricevuta da ' . $assenti[ $nome ] . ' giri' : count( $a['siti'] ) . ' siti' ) . PHP_EOL;
        }
        echo 'esterni: impronta ' . $impronta . ( empty( $diversi ) ? '' : ', diversa da ' . implode( ', ', $diversi ) ) . PHP_EOL;
        foreach( $eventi as $e ) {
            echo 'evento: ' . json_encode( $e ) . PHP_EOL;
        }
        foreach( $posta as $p ) {
            echo 'avviso a ' . implode( ', ', (array) $p[0] ) . ': ' . $p[1] . PHP_EOL;
        }
        exit( 0 );
    }

    // la cartella dei dati: la legge anche Apache, per www/vista.php
    if( ! is_dir( $cartella ) ) {
        mkdir( $cartella, 0750, true );
        @chgrp( $cartella, 'www-data' );
    }

    // la vista di questo server, per gli altri: pubblica solo i siti propri, così un sito tolto non resta in giro
    $pubblici = array();
    foreach( $locali as $h => $s ) {
        unset( $s['deploy'], $s['dichiarato'], $s['avvisa'] );
        $pubblici[ $h ] = $s;
    }
    file_put_contents( $cartella . 'stato.json', json_encode( array( 'server' => $io, 'ora' => date( 'c', $ora ), 'dns' => $dns, 'impronta' => $impronta, 'divergenza' => $divergenza, 'assenti' => $assenti, 'isolamento' => $isolamento, 'siti' => $pubblici, 'vista' => $vista ), JSON_PRETTY_PRINT ), LOCK_EX );
    @chgrp( $cartella . 'stato.json', 'www-data' );
    @chmod( $cartella . 'stato.json', 0640 );

    // registro e DOWNTIME.md dei progetti di questo server toccati da un evento
    if( ! empty( $eventi ) ) {
        $righe = '';
        foreach( $eventi as $e ) {
            $righe .= json_encode( $e ) . PHP_EOL;
            echo $e['evento'] . ' ' . $e['sito'] . PHP_EOL;
        }
        file_put_contents( $cartella . 'registro.jsonl', $righe, FILE_APPEND | LOCK_EX );
        $registro = array_map( function( $r ) { return json_decode( $r, true ); }, file( $cartella . 'registro.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) );
        $deploy = array();
        foreach( $eventi as $e ) {
            if( ! empty( $locali[ $e['sito'] ]['deploy'] ) ) {
                $deploy[ $locali[ $e['sito'] ]['deploy'] ] = true;
            }
        }
        foreach( array_keys( $deploy ) as $dev ) {
            $host = array_keys( array_filter( $locali, function( $s ) use( $dev ) { return $s['deploy'] === $dev; } ) );
            file_put_contents( dirname( $dev ) . '/DOWNTIME.md', uptimeDowntime( $registro, $host ) );
        }
    }

    // avvisi: uno fallito si scrive nel journal e non si ritenta
    foreach( $posta as $p ) {
        if( ! uptimeAvvisa( $conf['smtp'], $p[0], $p[1], $p[2], $errore ) ) {
            fwrite( STDERR, 'avviso non inviato: ' . $p[1] . ' ( ' . $errore . ' )' . PHP_EOL );
        }
    }
