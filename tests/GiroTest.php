<?php

    /**
     * test d'integrazione: la vista, i controlli e il giro completo
     *
     * Avvia il server PHP integrato su una porta libera di 127.0.0.1 col router di tests/server/, che serve la vista
     * vera ( www/vista.php ), un sito su, un sito lento, la vista finta di un secondo server e un 404 per tutto il
     * resto; un terzo server del cluster punta a un indirizzo che dà 404, cioè non risponde. Il giro gira davvero, con
     * bin/check e una configurazione temporanea: dati e radice dei deploy stanno in una cartella temporanea, l'SMTP
     * punta a una porta chiusa, quindi gli avvisi falliscono e finiscono nell'errore standard, dove li si legge.
     *
     */

    use PHPUnit\Framework\TestCase;

    require_once dirname( __DIR__ ) . '/src/uptime.php';

    class GiroTest extends TestCase {

        private static $server;
        private static $url;
        private static $cartella;
        private static $config;
        private static $altro;

        // il server integrato e la configurazione temporanea
        public static function setUpBeforeClass(): void {

            self::$cartella = sys_get_temp_dir() . '/glisweb-check-test-' . getmypid() . '/';
            mkdir( self::$cartella . 'dati', 0750, true );
            mkdir( self::$cartella . 'radice', 0750, true );
            self::$config = self::$cartella . 'uptime.yaml';
            self::$altro = self::$cartella . 'altro.json';

            $s = stream_socket_server( 'tcp://127.0.0.1:0' );
            $porta = parse_url( 'tcp://' . stream_socket_get_name( $s, false ), PHP_URL_PORT );
            fclose( $s );
            self::$url = 'http://127.0.0.1:' . $porta;

            self::scriviConfig( '/assente' );

            // NOTA il comando è una stringa e non un array, che proc_open() accetta solo da PHP 7.4; exec fa sì che
            // proc_terminate() fermi il server e non la shell che lo ha lanciato
            self::$server = proc_open(
                'exec ' . escapeshellarg( PHP_BINARY ) . ' -S 127.0.0.1:' . $porta . ' ' . escapeshellarg( __DIR__ . '/server/router.php' ),
                array( 1 => array( 'file', '/dev/null', 'w' ), 2 => array( 'file', '/dev/null', 'w' ) ),
                $pipe, NULL,
                array_merge( getenv(), array( 'GLISWEB_CHECK_CONFIG' => self::$config, 'TEST_ALTRO' => self::$altro ) )
            );

            for( $i = 0; $i < 50 && @file_get_contents( self::$url . '/ok' ) !== 'OK'; $i++ ) {
                usleep( 100000 );
            }

        }

        // si spegne il server e si toglie la cartella temporanea
        public static function tearDownAfterClass(): void {

            proc_terminate( self::$server );
            proc_close( self::$server );
            exec( 'rm -rf ' . escapeshellarg( self::$cartella ) );

        }

        // la configurazione del cluster di prova: giu.test punta a $giu, il resto è fisso
        private static function scriviConfig( $giu, $doppio = false ) {

            file_put_contents( self::$config, yaml_emit( array(
                'server'    => 'test1',
                'token'     => 'token-di-prova',
                'dati'      => self::$cartella . 'dati',
                'radice'    => self::$cartella . 'radice',
                'cluster'   => array(
                    'test1' => self::$url . '/glisweb-check-24x7/vista.php',
                    'test2' => self::$url . '/altro',
                    'test3' => self::$url . '/assente'
                ),
                'assenza'   => 2,
                'smtp'      => array( 'host' => '127.0.0.1', 'port' => 1, 'from' => 'uptime@example.com' ),
                'avvisa'    => array( 'noi' => array( 'noi@example.com' ) ),
                'esterni'   => array_merge( array(
                    'giu.test'  => array( 'url' => self::$url . $giu, 'timeout' => 2 ),
                    'su.test'   => array( 'url' => self::$url . '/ok', 'testo' => 'OK', 'timeout' => 2 )
                ), ( $doppio ) ? array( 'giu2.test' => array( 'url' => self::$url . $doppio, 'timeout' => 2 ) ) : array() )
            ) ) );

        }

        // un giro con bin/check: codice di uscita, uscita standard, errore standard
        private function giro( $opzioni = '' ) {

            $p = proc_open( dirname( __DIR__ ) . '/bin/check --config ' . escapeshellarg( self::$config ) . ' ' . $opzioni,
                array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipe );
            $out = stream_get_contents( $pipe[1] );
            $err = stream_get_contents( $pipe[2] );

            return array( proc_close( $p ), $out, $err );

        }

        // una richiesta alla vista, con o senza token
        private function vista( $token = NULL ) {

            $ch = curl_init( self::$url . '/glisweb-check-24x7/vista.php' );
            curl_setopt_array( $ch, array( CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ( $token === NULL ) ? array() : array( 'X-Glisweb-Uptime: ' . $token ) ) );
            $corpo = curl_exec( $ch );
            $codice = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
            curl_close( $ch );

            return array( $codice, $corpo );

        }

        // uptimeControlla() -> su, codice sbagliato, testo assente, timeout
        public function testControlla() {

            $base = array( 'testo' => NULL, 'codice' => 200, 'timeout' => 1 );
            $esiti = uptimeControlla( array(
                'ok'        => array_replace( $base, array( 'url' => self::$url . '/ok', 'testo' => 'OK' ) ),
                'assente'   => array_replace( $base, array( 'url' => self::$url . '/assente' ) ),
                'testo'     => array_replace( $base, array( 'url' => self::$url . '/ok', 'testo' => 'ALTRO' ) ),
                'qualunque' => array_replace( $base, array( 'url' => self::$url . '/assente', 'codice' => NULL ) ),
                'lento'     => array_replace( $base, array( 'url' => self::$url . '/lento' ) )
            ), 2 );

            $this->assertTrue( $esiti['ok']['ok'] );
            $this->assertSame( 'codice 404', $esiti['assente']['errore'] );
            $this->assertSame( 'testo atteso assente', $esiti['testo']['errore'] );
            $this->assertTrue( $esiti['qualunque']['ok'] );                                 // senza codice atteso, sotto il 500 va bene
            $this->assertFalse( $esiti['lento']['ok'] );
            $this->assertStringContainsString( 'imeout', $esiti['lento']['errore'] );

        }

        // la vista: 403 senza token o con quello sbagliato, 404 finché il giro non l'ha scritta
        public function testVistaSenzaToken() {

            $this->assertSame( 403, $this->vista()[0] );
            $this->assertSame( '', $this->vista()[1] );
            $this->assertSame( 403, $this->vista( 'token-sbagliato' )[0] );
            $this->assertSame( 404, $this->vista( 'token-di-prova' )[0] );

        }

        // --prova-avviso -> con l'SMTP chiuso non parte, esce con 1 e dice perché
        public function testProvaAvviso() {

            list( $uscita, $out ) = $this->giro( '--prova-avviso' );
            $this->assertSame( 1, $uscita );
            $this->assertStringContainsString( 'prova a noi@example.com: NON inviata', $out );

        }

        // il giro completo: isteresi, consenso con un altro server, registro, vista, avvisi, ritorno su
        public function testGiro() {

            // test2 vede giù giu.test e ha una sezione esterni: diversa
            file_put_contents( self::$altro, json_encode( array( 'server' => 'test2', 'impronta' => 'diversa', 'siti' => array(),
                'vista' => array( 'giu.test' => array( 'locale' => 'giu' ) ) ) ) );

            // primo giro: niente di giù ancora, ma la divergenza degli esterni si segnala
            list( $uscita, $out, $err ) = $this->giro();
            $this->assertSame( 0, $uscita );
            $this->assertSame( '', $out );
            $this->assertStringContainsString( 'avviso non inviato: [uptime] esterni: diversi fra test1 e test2', $err );
            $stato = json_decode( file_get_contents( self::$cartella . 'dati/stato.json' ), true );
            $this->assertSame( 1, $stato['vista']['giu.test']['fallimenti'] );
            $this->assertSame( 'su', $stato['vista']['giu.test']['globale'] );
            $this->assertSame( array(), $stato['siti'] );                                   // gli esterni non si pubblicano

            // la vista adesso c'è, col token
            list( $codice, $corpo ) = $this->vista( 'token-di-prova' );
            $this->assertSame( 200, $codice );
            $this->assertSame( 'test1', json_decode( $corpo, true )['server'] );

            // test3 non dà la vista da due giri ( assenza: 2 ): una mail sola, che dice che manca il secondo parere
            $this->assertStringNotContainsString( 'secondo parere', $err );
            list( $uscita, $out, $err ) = $this->giro();
            $this->assertStringContainsString( 'avviso non inviato: [uptime] test1: cluster senza secondo parere', $err );
            $this->assertSame( 2, json_decode( file_get_contents( self::$cartella . 'dati/stato.json' ), true )['assenti']['test3'] );
            $this->assertSame( 0, json_decode( file_get_contents( self::$cartella . 'dati/stato.json' ), true )['assenti']['test2'] );

            // al terzo fallimento è giù, confermato da test2: inizio nel registro e avviso a noi; l'assenza non si ripete
            list( $uscita, $out, $err ) = $this->giro();
            $this->assertSame( "inizio giu.test\n", $out );
            $this->assertStringContainsString( "avviso non inviato: [uptime] giu.test GIU'", $err );
            $this->assertStringNotContainsString( 'secondo parere', $err );
            $registro = array_map( 'json_decode', file( self::$cartella . 'dati/registro.jsonl' ) );
            $this->assertSame( 'inizio', $registro[0]->evento );
            $this->assertTrue( $registro[0]->confermato );
            $this->assertSame( array( 'test1', 'test2' ), $registro[0]->server );

            // a secco stampa e non scrive
            $prima = file_get_contents( self::$cartella . 'dati/stato.json' );
            list( $uscita, $out ) = $this->giro( '--secco' );
            $this->assertSame( 0, $uscita );
            $this->assertMatchesRegularExpression( '/^giu\.test\s+giu\s+4 /m', $out );
            $this->assertMatchesRegularExpression( '/^su\.test\s+su\s+0 /m', $out );
            $this->assertStringContainsString( 'vista di test2: 0 siti', $out );
            $this->assertStringContainsString( 'vista di test3: NON ricevuta da 4 giri', $out );
            $this->assertSame( $prima, file_get_contents( self::$cartella . 'dati/stato.json' ) );

            // torna su: fine nel registro, e l'avviso a chi era stato avvisato
            self::scriviConfig( '/ok' );
            list( $uscita, $out, $err ) = $this->giro();
            $this->assertSame( "fine giu.test\n", $out );
            $this->assertStringContainsString( 'avviso non inviato: [uptime] giu.test di nuovo su', $err );
            $this->assertCount( 2, file( self::$cartella . 'dati/registro.jsonl' ) );

        }

        // un down che vede solo questo server: nel registro e nella vista come non confermato, ma senza mail
        public function testNonConfermato() {

            // si riparte da zero, e test2 adesso non controlla giu.test: nessun secondo parere
            @unlink( self::$cartella . 'dati/stato.json' );
            @unlink( self::$cartella . 'dati/registro.jsonl' );
            file_put_contents( self::$altro, json_encode( array( 'server' => 'test2', 'impronta' => uptimeImpronta( array() ), 'siti' => array(), 'vista' => array() ) ) );
            self::scriviConfig( '/assente' );

            $this->giro();
            $this->giro();
            list( $uscita, $out, $err ) = $this->giro();
            $this->assertSame( 0, $uscita );
            $this->assertSame( "inizio giu.test\n", $out );
            $this->assertStringNotContainsString( 'giu.test GIU', $err );
            $registro = array_map( 'json_decode', file( self::$cartella . 'dati/registro.jsonl' ) );
            $this->assertFalse( $registro[0]->confermato );
            $this->assertSame( array( 'test1' ), $registro[0]->server );
            $stato = json_decode( file_get_contents( self::$cartella . 'dati/stato.json' ), true );
            $this->assertSame( 'nonconfermato', $stato['vista']['giu.test']['globale'] );
            $this->assertArrayNotHasKey( 'avvisato', $stato['vista']['giu.test'] );

            // torna su: fine nel registro, e nessun "di nuovo su", perché nessuno era stato avvisato
            self::scriviConfig( '/ok' );
            list( $uscita, $out, $err ) = $this->giro();
            $this->assertSame( "fine giu.test\n", $out );
            $this->assertStringNotContainsString( 'di nuovo su', $err );

        }

        // si avvisa solo quando cambia lo stato del gruppo: giù col primo sito che cade, di nuovo tutto su quando torna
        // l'ultimo, col downtime; i siti che cadono o tornano in mezzo non mandano mail
        public function testGruppo() {

            @unlink( self::$cartella . 'dati/stato.json' );
            @unlink( self::$cartella . 'dati/registro.jsonl' );
            file_put_contents( self::$altro, json_encode( array( 'server' => 'test2', 'impronta' => 'diversa', 'siti' => array(),
                'vista' => array( 'giu.test' => array( 'locale' => 'giu' ), 'giu2.test' => array( 'locale' => 'giu' ) ) ) ) );

            // giu.test cade da solo: una mail col suo nome
            self::scriviConfig( '/assente', '/ok' );
            $this->giro();
            $this->giro();
            list( $uscita, $out, $err ) = $this->giro();
            $this->assertStringContainsString( "avviso non inviato: [uptime] giu.test GIU'", $err );

            // cade anche giu2.test, a gruppo già giù: nessuna mail
            self::scriviConfig( '/assente', '/assente' );
            $this->giro();
            $this->giro();
            list( $uscita, $out, $err ) = $this->giro();
            $this->assertSame( "inizio giu2.test\n", $out );
            $this->assertStringNotContainsString( 'GIU', $err );

            // torna giu.test e giu2.test resta giù: nessuna mail
            self::scriviConfig( '/ok', '/assente' );
            list( $uscita, $out, $err ) = $this->giro();
            $this->assertSame( "fine giu.test\n", $out );
            $this->assertStringNotContainsString( 'di nuovo', $err );

            // torna anche giu2.test: una mail sola, per il gruppo, col downtime
            self::scriviConfig( '/ok', '/ok' );
            list( $uscita, $out, $err ) = $this->giro();
            $this->assertSame( "fine giu2.test\n", $out );
            $this->assertSame( 1, substr_count( $err, 'avviso non inviato: [uptime] esterni: di nuovo tutto su dopo 1 minuto' ) );
            $this->assertArrayNotHasKey( 'esterni', array_column( json_decode( file_get_contents( self::$cartella . 'dati/stato.json' ), true )['gruppi'], 'gruppo', 'gruppo' ) );

        }

        // due siti dello stesso gruppo che cadono insieme: una mail sola, che li conta, e una sola al ritorno
        public function testRaggruppati() {

            @unlink( self::$cartella . 'dati/stato.json' );
            @unlink( self::$cartella . 'dati/registro.jsonl' );
            file_put_contents( self::$altro, json_encode( array( 'server' => 'test2', 'impronta' => 'diversa', 'siti' => array(),
                'vista' => array( 'giu.test' => array( 'locale' => 'giu' ), 'giu2.test' => array( 'locale' => 'giu' ) ) ) ) );
            self::scriviConfig( '/assente', '/assente' );

            $this->giro();
            $this->giro();
            list( $uscita, $out, $err ) = $this->giro();
            $this->assertSame( 1, substr_count( $err, "avviso non inviato: [uptime] esterni: 2 siti GIU'" ) );
            $this->assertStringNotContainsString( "giu.test GIU'", $err );
            $this->assertStringNotContainsString( "giu2.test GIU'", $err );

            self::scriviConfig( '/ok', '/ok' );
            list( $uscita, $out, $err ) = $this->giro();
            $this->assertSame( 1, substr_count( $err, 'avviso non inviato: [uptime] esterni: di nuovo tutto su dopo' ) );
            $this->assertStringNotContainsString( 'giu.test di nuovo su', $err );

        }

    }
