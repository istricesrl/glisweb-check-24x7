<?php

    /**
     * test dei comandi di bin/
     *
     * bin/install scrive in systemd e in Apache, e qui non si lancia: se ne prova la sintassi, e che rifiuti un'azione
     * che non conosce senza toccare niente. bin/check lo prova GiroTest.
     *
     */

    use PHPUnit\Framework\TestCase;

    class ScriptTest extends TestCase {

        // la sintassi di bash di tutti e due i comandi
        public function testSintassi() {

            foreach( array( 'check', 'install' ) as $comando ) {
                exec( 'bash -n ' . escapeshellarg( dirname( __DIR__ ) . '/bin/' . $comando ) . ' 2>&1', $out, $uscita );
                $this->assertSame( 0, $uscita, $comando . ': ' . implode( PHP_EOL, $out ) );
            }

        }

        // un'azione sconosciuta stampa l'uso e non fa niente
        public function testInstallUso() {

            exec( 'id -u', $id );
            $out = array();
            exec( escapeshellarg( dirname( __DIR__ ) . '/bin/install' ) . ' prova 2>&1', $out );
            $this->assertStringContainsString( ( $id[0] === '0' ) ? 'install|remove' : 'va lanciato da root', implode( PHP_EOL, $out ) );

        }

    }
