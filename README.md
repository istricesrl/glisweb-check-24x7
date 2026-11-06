# glisweb-check-24x7

Controllo di uptime dei siti [glisweb](https://github.com/istricesrl/glisweb) di un cluster di server, indipendente
dal framework.

Ogni server del cluster controlla una volta al minuto tutti i siti glisweb, i suoi e quelli degli altri server,
qualunque profilo abbiano ( chi vuole restringere sceglie i profili per cluster, per deploy e per host ), più i siti
esterni della configurazione; i server si scambiano quello che vedono, e **un sito è giù
solo se lo vedono giù almeno due server**. Tiene il registro dei periodi di down, ne ricava il `DOWNTIME.md` di ogni
progetto e avvisa per mail chi lo amministra, e il referente del sito solo se abilitato. Avvisa solo per i down
confermati: quello che vede un server solo va nel registro, e se un server del cluster smette di rispondere lo dice
una mail sola al giorno.

Sta in un progetto suo, e non dentro un deploy, perché **un controllo di uptime non deve dipendere da quello che
controlla**: un timer appoggiato a un deploy muore con un aggiornamento notturno sbagliato di quel deploy. Dei deploy
legge solo `/status/uptime`, `etc/uptime.yaml` ed `etc/uptime/<host>.yaml`, che il framework fornisce.

## requisiti

- Linux con systemd, PHP 7.3 o più con le estensioni `curl` e `yaml`, `composer`, `git`;
- Apache per servire la vista agli altri server ( con nginx si configura a mano );
- i siti glisweb in `/var/www/<progetto>/<dev|stable|test>/`.

## installazione in breve

```
git clone https://github.com/istricesrl/glisweb-check-24x7.git /opt/glisweb-check-24x7
cd /opt/glisweb-check-24x7
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev
mkdir -p /etc/glisweb && cp esempi/uptime.yaml /etc/glisweb/uptime.yaml   # e poi si compila: server, token, cluster, smtp, avvisa
bin/check --secco                                 # controlla e stampa, senza scrivere e senza avvisare
bin/check --prova-avviso                          # la mail di prova deve arrivare davvero
bin/install install                               # timer systemd ogni minuto e alias Apache della vista
```

Su ogni server del cluster, ognuno col suo `server:`. Il dettaglio, la migrazione dal controllo che stava nel
framework, la configurazione campo per campo, gli avvisi e l'esercizio sono in [docs/MANUALE.md](docs/MANUALE.md).

## comandi

comando                    | cosa fa
---------------------------|------------------------------------------------------------------------------
`bin/check`                | un giro: controlli, registro, `DOWNTIME.md`, avvisi ( lo lancia il timer )
`bin/check --secco`        | controlla e stampa, senza scrivere e senza avvisare
`bin/check --prova-avviso` | manda una mail di prova agli indirizzi `avvisa.noi`
`bin/check --config FILE`  | legge un'altra configurazione al posto di `/etc/glisweb/uptime.yaml`
`bin/install install`      | installa timer, alias della vista, cartella dei dati; migra dal framework
`bin/install remove`       | toglie timer e alias; configurazione e dati restano

## test

```
composer install
vendor/bin/phpunit
```

## sicurezza

Il repository è pubblico: token, credenziali SMTP, indirizzi e l'elenco dei siti esterni stanno solo in
`/etc/glisweb/uptime.yaml` di ogni server ( `root:www-data`, `640` ), mai qui. La vista di un server, che contiene
i nomi dei siti, si vede solo col token del cluster.

## licenza

Distribuito con la licenza del framework GlisWeb: vedi [LICENSE.md](LICENSE.md).
