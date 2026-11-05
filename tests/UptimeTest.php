<?php

    /**
     * test delle funzioni pure di src/uptime.php
     *
     * Non scrivono niente e non toccano la rete, tranne uptimePubblico() e uptimeSiti(), che chiedono al DNS pubblico:
     * localhost e un dominio .invalid non si risolvono, example.com sì ( è riservato dalla IANA proprio agli esempi ).
     *
     */

    use PHPUnit\Framework\TestCase;

    require_once dirname( __DIR__ ) . '/src/uptime.php';

    class UptimeTest extends TestCase {

        // uptimeLeggiConfig() -> i default dei campi facoltativi, la barra in fondo ai dati, il vecchio deploy: ignorato
        public function testLeggiConfig() {

            $file = tempnam( sys_get_temp_dir(), 'uptime' );
            file_put_contents( $file, "server: web03\ndeploy: /var/www/vecchio/stable\ndati: /tmp/dati\n" );
            $conf = uptimeLeggiConfig( $file );
            $this->assertSame( 'web03', $conf['server'] );
            $this->assertSame( '/tmp/dati/', $conf['dati'] );
            $this->assertSame( '/var/www/', $conf['radice'] );
            $this->assertSame( 4, $conf['paralleli'] );
            $this->assertSame( array(), $conf['esterni'] );
            $this->assertSame( array( 'PROD*' ), $conf['profili'] );

            // senza server: la configurazione non vale
            file_put_contents( $file, "token: x\n" );
            $this->assertNull( uptimeLeggiConfig( $file ) );
            unlink( $file );
            $this->assertNull( uptimeLeggiConfig( $file ) );

        }

        // uptimePubblico() -> un vhost di prova solo locale ( /etc/hosts verso 127.0.0.1 ) non si controlla
        public function testPubblico() {

            $this->assertTrue( uptimePubblico( '8.8.8.8' ) );
            $this->assertFalse( uptimePubblico( '10.0.0.1' ) );
            $this->assertFalse( uptimePubblico( 'localhost' ) );
            $this->assertFalse( uptimePubblico( 'nessuno.invalid' ) );

        }

        // uptimeProfilo() -> il profilo che il bootstrap glisweb darebbe all'host: si controllano solo quelli PROD*
        public function testProfilo() {

            $sites = array(
                1 => array( 'domains' => array( 'DEV' => 'istricesrl.it', 'PROD' => 'esempio.it' ), 'hosts' => array( 'DEV' => 'esempio', 'PROD' => 'www' ) ),
                2 => array( 'domains' => array( 'PRODWMS' => 'interno.esempio.it' ), 'hosts' => array( 'PRODWMS' => 'magazzino' ) ),
                3 => array( 'domains' => array( 'PROD' => 'altro.org' ), 'hosts' => array( 'PROD' => 'shop' ), 'alias' => array( 'domains' => array( 'PROD' => 'altro.com' ) ) )
            );

            $this->assertSame( 'DEV', uptimeProfilo( 'esempio.istricesrl.it', $sites ) );
            $this->assertSame( 'PROD', uptimeProfilo( 'www.esempio.it', $sites ) );
            $this->assertSame( 'PROD', uptimeProfilo( 'esempio.it', $sites ) );                 // con www fra gli host, il www è facoltativo
            $this->assertSame( 'PROD', uptimeProfilo( 'WWW.Esempio.IT', $sites ) );             // come Apache, senza distinguere le maiuscole
            $this->assertSame( 'PRODWMS', uptimeProfilo( 'magazzino.interno.esempio.it', $sites ) );
            $this->assertSame( 'PROD', uptimeProfilo( 'shop.altro.com', $sites ) );             // alias del dominio
            $this->assertNull( uptimeProfilo( 'altro.org', $sites ) );                          // senza www l'host è obbligatorio
            $this->assertNull( uptimeProfilo( 'altro.it', $sites ) );

            // la regola è la site2regexp() del framework, copiata: questa è la sua forma
            $this->assertSame( '/^(?:www){0,1}(?:\.)*(esempio\.it){1}/i', uptimeRegexp( $sites[1], 'PROD' ) );
            $this->assertNull( uptimeRegexp( $sites[1], 'TEST' ) );

        }

        // uptimeSiti() -> i siti di produzione dei deploy, dai virtual host e da etc/uptime/<host>.yaml
        public function testSiti() {

            $radice = sys_get_temp_dir() . '/uptime-radice-' . getmypid();
            $nuovo = $radice . '/nuovo/stable';
            $vecchio = $radice . '/vecchio/stable';
            foreach( array( $nuovo . '/_src/_api/_status', $nuovo . '/src', $nuovo . '/etc/uptime', $vecchio . '/_src', $vecchio . '/src' ) as $d ) {
                mkdir( $d, 0755, true );
            }

            // un deploy aggiornato, con /status/uptime, un host DEV, uno spento e uno dichiarato dal deploy
            touch( $nuovo . '/_src/_config.php' );
            touch( $nuovo . '/_src/_api/_status/_uptime.php' );
            file_put_contents( $nuovo . '/src/config.json', json_encode( array( 'sites' => array( 1 => array(
                'domains' => array( 'DEV' => 'istricesrl.it', 'PROD' => 'example.com' ), 'hosts' => array( 'DEV' => 'nuovo', 'PROD' => 'www' ) ) ) ) ) );
            file_put_contents( $nuovo . '/etc/uptime/spento.example.com.yaml', "attivo: false\n" );
            file_put_contents( $nuovo . '/etc/uptime/www.example.com.yaml', "lento: 7\n" );

            // un deploy vecchio, senza /status/uptime: si guarda la home, senza testo e senza codice
            touch( $vecchio . '/_src/_config.php' );
            file_put_contents( $vecchio . '/src/config.json', json_encode( array( 'sites' => array( 1 => array(
                'domains' => array( 'PRODSITO' => 'example.org' ), 'hosts' => array( 'PRODSITO' => 'www' ) ) ) ) ) );

            $vhost = array(
                $nuovo => array( 'example.com', 'nuovo.istricesrl.it', 'spento.example.com' ),
                $vecchio => array( 'example.org', 'escluso.example.org' )
            );
            $scartati = array();
            $siti = uptimeSiti( $radice, $vhost, array( 'escluso.example.org' ), $scartati );

            $this->assertSame( array( 'example.com', 'example.org', 'www.example.com' ), array_keys( $siti ) );
            $this->assertSame( 'https://example.com/status/uptime', $siti['example.com']['url'] );
            $this->assertSame( 'OK', $siti['example.com']['testo'] );
            $this->assertSame( $nuovo . '/', $siti['example.com']['deploy'] );
            $this->assertFalse( $siti['example.com']['dichiarato'] );
            $this->assertSame( 7, $siti['www.example.com']['lento'] );
            $this->assertTrue( $siti['www.example.com']['dichiarato'] );
            $this->assertSame( 'https://example.org/', $siti['example.org']['url'] );
            $this->assertNull( $siti['example.org']['testo'] );
            $this->assertNull( $siti['example.org']['codice'] );
            $this->assertSame( 'profilo DEV', $scartati['nuovo.istricesrl.it'] );

            exec( 'rm -rf ' . escapeshellarg( $radice ) );

        }

        // uptimeProfiloAmmesso() -> modelli alla fnmatch, e l'host senza profilo vale come DEV
        public function testProfiloAmmesso() {

            $this->assertTrue( uptimeProfiloAmmesso( 'PRODWMS', array( 'PROD*' ) ) );
            $this->assertFalse( uptimeProfiloAmmesso( 'DEV', array( 'PROD*' ) ) );
            $this->assertTrue( uptimeProfiloAmmesso( 'TEST', array( 'PROD*', 'TEST*' ) ) );
            $this->assertFalse( uptimeProfiloAmmesso( 'DEVELOP', array( 'DEV' ) ) );
            $this->assertTrue( uptimeProfiloAmmesso( 'DEV', array( '*' ) ) );
            $this->assertTrue( uptimeProfiloAmmesso( NULL, array( 'DEV' ) ) );
            $this->assertFalse( uptimeProfiloAmmesso( NULL, array( 'PROD*' ) ) );
            $this->assertFalse( uptimeProfiloAmmesso( 'PROD', array() ) );

        }

        // uptimeSiti() -> quali profili si controllano: il cluster, poi il deploy, poi l'host
        public function testSitiProfili() {

            $radice = sys_get_temp_dir() . '/uptime-profili-' . getmypid();
            $dev = $radice . '/progetto/dev';
            mkdir( $dev . '/_src', 0755, true );
            mkdir( $dev . '/src', 0755, true );
            mkdir( $dev . '/etc/uptime', 0755, true );
            touch( $dev . '/_src/_config.php' );
            file_put_contents( $dev . '/src/config.json', json_encode( array( 'sites' => array( 1 => array(
                'domains' => array( 'DEV' => 'example.net', 'TEST' => 'example.org', 'PROD' => 'example.com' ),
                'hosts' => array( 'DEV' => 'www', 'TEST' => 'www', 'PROD' => 'www' ) ) ) ) ) );
            $vhost = array( $dev => array( 'example.com', 'example.net', 'example.org' ) );

            // di default solo la produzione
            $siti = uptimeSiti( $radice, $vhost );
            $this->assertSame( array( 'example.com' ), array_keys( $siti ) );
            $this->assertSame( 'PROD', $siti['example.com']['profilo'] );

            // il cluster ne chiede di più
            $this->assertSame( array( 'example.com', 'example.org' ), array_keys( uptimeSiti( $radice, $vhost, array(), $scartati, array( 'PROD*', 'TEST*' ) ) ) );
            $this->assertSame( array( 'example.com', 'example.net', 'example.org' ), array_keys( uptimeSiti( $radice, $vhost, array(), $scartati, array( '*' ) ) ) );

            // il deploy sostituisce il cluster
            file_put_contents( $dev . '/etc/uptime.yaml', "profili: [ DEV ]\n" );
            $scartati = array();
            $this->assertSame( array( 'example.net' ), array_keys( uptimeSiti( $radice, $vhost, array(), $scartati, array( 'PROD*', 'TEST*' ) ) ) );
            $this->assertSame( 'profilo PROD', $scartati['example.com'] );

            // l'host vince sul deploy, in tutti e due i versi, e escludi: del cluster vince su tutto
            file_put_contents( $dev . '/etc/uptime/example.org.yaml', "attivo: true\n" );
            file_put_contents( $dev . '/etc/uptime/example.net.yaml', "attivo: false\n" );
            $this->assertSame( array( 'example.org' ), array_keys( uptimeSiti( $radice, $vhost ) ) );
            $this->assertSame( array(), uptimeSiti( $radice, $vhost, array( 'example.org' ) ) );

            exec( 'rm -rf ' . escapeshellarg( $radice ) );

        }

        // uptimeEsterni() -> i siti esterni hanno i default, codice 200 e nessun testo atteso, e non hanno deploy
        public function testEsterni() {

            $esterni = uptimeEsterni( array( 'www.esterno.it' => array( 'url' => 'https://www.esterno.it/', 'lento' => 5, 'avvisa' => array( 'abilitato' => true ) ), 'senzaurl.it' => array() ) );
            $this->assertSame( array( 'www.esterno.it' ), array_keys( $esterni ) );
            $this->assertSame( 200, $esterni['www.esterno.it']['codice'] );
            $this->assertNull( $esterni['www.esterno.it']['testo'] );
            $this->assertSame( 5, $esterni['www.esterno.it']['lento'] );
            $this->assertSame( 3, $esterni['www.esterno.it']['soglia'] );
            $this->assertNull( $esterni['www.esterno.it']['deploy'] );

        }

        // uptimeImpronta() -> l'impronta della sezione esterni: non cambia con l'ordine delle chiavi, cambia col contenuto
        public function testImpronta() {

            $a = uptimeImpronta( array( 'a.it' => array( 'url' => 'https://a.it/', 'lento' => 5 ), 'b.it' => array( 'url' => 'https://b.it/' ) ) );
            $b = uptimeImpronta( array( 'b.it' => array( 'url' => 'https://b.it/' ), 'a.it' => array( 'lento' => 5, 'url' => 'https://a.it/' ) ) );
            $c = uptimeImpronta( array( 'a.it' => array( 'url' => 'https://a.it/', 'lento' => 6 ), 'b.it' => array( 'url' => 'https://b.it/' ) ) );
            $this->assertSame( $a, $b );
            $this->assertNotSame( $a, $c );

        }

        // uptimeVhost() -> solo i virtual host sulla 443, col primo nome, e la document root senza barra finale
        public function testVhost() {

            $vhost = uptimeVhost( array( __DIR__ . '/vhost/' ) );
            $this->assertSame( array( 'uno.example.it' ), array_values( $vhost['/var/www/uno/dev'] ) );
            $this->assertSame( array( 'due.example.it' ), array_values( $vhost['/var/www/due/dev'] ) );
            $this->assertArrayNotHasKey( '/var/www/tre/dev', $vhost );

        }

        // uptimeAggiorna() -> giù al terzo fallimento consecutivo, su al primo controllo riuscito
        public function testIsteresi() {

            $ko = array( 'sito.it' => array( 'ok' => false, 'codice' => 0, 'tempo' => 10, 'certificato' => NULL, 'errore' => 'timeout' ) );
            $ok = array( 'sito.it' => array( 'ok' => true, 'codice' => 200, 'tempo' => 0.5, 'certificato' => 60, 'errore' => '' ) );
            $stato = uptimeAggiorna( array(), $ko, 3 );
            $stato = uptimeAggiorna( $stato, $ko, 3 );
            $this->assertSame( 'su', $stato['sito.it']['locale'] );
            $stato = uptimeAggiorna( $stato, $ko, 3 );
            $this->assertSame( 'giu', $stato['sito.it']['locale'] );
            $stato = uptimeAggiorna( $stato, $ok, 3 );
            $this->assertSame( 'su', $stato['sito.it']['locale'] );
            $this->assertSame( 0, $stato['sito.it']['fallimenti'] );

        }

        // uptimeAggiorna() -> lento solo dopo tre giri di fila oltre la soglia, che si sceglie per sito
        public function testLento() {

            $ok = array( 'sito.it' => array( 'ok' => true, 'codice' => 200, 'tempo' => 0.5, 'certificato' => 60, 'errore' => '' ) );
            $adagio = array( 'sito.it' => array( 'ok' => true, 'codice' => 200, 'tempo' => 6.5, 'certificato' => 60, 'errore' => '' ) );
            $stato = uptimeAggiorna( array(), $adagio, 3, array( 'sito.it' => 3 ) );
            $stato = uptimeAggiorna( $stato, $ok, 3, array( 'sito.it' => 3 ) );
            $this->assertFalse( $stato['sito.it']['lento'] );
            $stato = uptimeAggiorna( $stato, $adagio, 3, array( 'sito.it' => 3 ) );
            $stato = uptimeAggiorna( $stato, $adagio, 3, array( 'sito.it' => 3 ) );
            $this->assertFalse( $stato['sito.it']['lento'] );
            $stato = uptimeAggiorna( $stato, $adagio, 3, array( 'sito.it' => 3 ) );
            $this->assertTrue( $stato['sito.it']['lento'] );
            $this->assertFalse( uptimeAggiorna( $stato, $adagio, 3, array( 'sito.it' => 10 ) )['sito.it']['lento'] );

        }

        // uptimeConsenso() -> giù solo se lo vede giù anche un altro server, e avvisa il primo in ordine alfabetico
        public function testConsenso() {

            $c = uptimeConsenso( 'web03', 'giu', array( 'web04' => 'giu' ) );
            $this->assertSame( array( 'giu', true ), array( $c['stato'], $c['mittente'] ) );
            $c = uptimeConsenso( 'web03', 'giu', array( 'web02' => 'giu' ) );
            $this->assertSame( array( 'giu', false ), array( $c['stato'], $c['mittente'] ) );
            $this->assertSame( array( 'web02', 'web03' ), $c['giu'] );
            $c = uptimeConsenso( 'web03', 'giu', array( 'web02' => 'su' ) );
            $this->assertSame( 'su', $c['stato'] );
            $c = uptimeConsenso( 'web03', 'giu', array( 'web02' => NULL ) );
            $this->assertSame( array( 'nonconfermato', true ), array( $c['stato'], $c['mittente'] ) );
            $c = uptimeConsenso( 'web03', 'su', array( 'web02' => 'giu' ) );
            $this->assertSame( array( 'su', false ), array( $c['stato'], $c['mittente'] ) );

        }

        // uptimeTransizione() e uptimeDowntime() -> un evento all'inizio e uno alla fine, e la riga del periodo
        public function testDowntime() {

            $t = mktime( 10, 0, 0, 11, 4, 2026 );
            $sito = array( 'globale' => 'su', 'errore' => 'timeout' );
            list( $sito, $inizio ) = uptimeTransizione( 'sito.it', $sito, 'giu', $t, array( 'web02', 'web03' ) );
            list( $sito, $niente ) = uptimeTransizione( 'sito.it', $sito, 'giu', $t + 60 );
            list( $sito, $fine ) = uptimeTransizione( 'sito.it', $sito, 'su', $t + 600 );
            $this->assertSame( 'inizio', $inizio['evento'] );
            $this->assertTrue( $inizio['confermato'] );
            $this->assertNull( $niente );
            $this->assertSame( 'fine', $fine['evento'] );
            $this->assertSame( 600, $fine['durata'] );
            $this->assertArrayNotHasKey( 'dal', $sito );

            $md = uptimeDowntime( array( $inizio, $fine ), array( 'sito.it' ) );
            $this->assertStringContainsString( '| 2026-11-04 10:00 | 2026-11-04 10:10 | 10 min | sito.it | sì | timeout |', $md );
            $this->assertStringNotContainsString( 'sito.it |', uptimeDowntime( array( $inizio, $fine ), array( 'altro.it' ) ) );

            // un periodo aperto è in corso
            $this->assertStringContainsString( '| in corso |', uptimeDowntime( array( $inizio ), array( 'sito.it' ) ) );

        }

        // uptimeAvvisa() -> senza destinatari o con un SMTP che non risponde non parte, e dice perché
        public function testAvvisaFallito() {

            $this->assertFalse( uptimeAvvisa( array( 'from' => 'uptime@example.com' ), array(), 'oggetto', 'corpo', $errore ) );
            $this->assertSame( 'nessun destinatario', $errore );
            $this->assertFalse( uptimeAvvisa( array( 'host' => '127.0.0.1', 'port' => 1, 'from' => 'uptime@example.com' ), array( 'noi@example.com' ), 'oggetto', 'corpo', $errore ) );
            $this->assertNotEmpty( $errore );

        }

    }
