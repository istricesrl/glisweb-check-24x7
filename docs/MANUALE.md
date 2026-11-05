# glisweb-check-24x7 — manuale

> **nota** — il controllo di uptime è nato il 04/11/2026 dentro il framework glisweb, al posto di Site24x7, non
> rinnovato; il 05/11/2026 è uscito dal framework ed è diventato questo progetto. Le decisioni di partenza sono di
> Fabio Mosti.

Come i server di un cluster si accorgono che un sito glisweb è giù, chi avvisano, come si installa,
come si configura e come si esercita.

1. [cos'è e perché sta da solo](#cosè-e-perché-sta-da-solo)
2. [architettura](#architettura)
3. [cosa fornisce un deploy glisweb](#cosa-fornisce-un-deploy-glisweb)
4. [installazione](#installazione)
5. [configurazione](#configurazione)
6. [avvisi](#avvisi)
7. [esercizio](#esercizio)
8. [problemi noti e casi limite](#problemi-noti-e-casi-limite)
9. [sviluppo e test](#sviluppo-e-test)
10. [decisioni](#decisioni)

cos'è e perché sta da solo
==========================
È un controllo di disponibilità fatto in casa, per un gruppo di server che ospitano siti glisweb:

- gira su ogni server del cluster, e **ogni server controlla tutti i siti**: si controllano a vicenda, così un
  server che non raggiunge niente non manda falsi allarmi;
- di default controlla **ogni host servito**, qualunque profilo gli dia il deploy ( `PROD`, `TEST`, `DEV`... ): si
  controlla per url. Chi vuole restringere sceglie i profili per cluster, per deploy e per singolo host ( vedi
  [quali profili si controllano](#quali-profili-si-controllano) );
- controlla anche i **siti esterni**, che non stanno sui server del cluster ma interessano lo stesso;
- tiene un registro dei periodi di down e ne ricava il `DOWNTIME.md` di ogni progetto;
- **avvisa noi** per primi, e il referente del sito solo se abilitato e solo a down confermato.

Sta in un progetto suo, installato in `/opt/glisweb-check-24x7`, e non dentro un deploy, per un motivo solo: **un
controllo di uptime non deve dipendere da quello che controlla**. Quando stava nel framework il timer partiva da un
deploy, e un aggiornamento notturno sbagliato di quel deploy, o un commit a metà, avrebbe spento il monitoraggio di
tutto il server proprio quando serviva. Adesso dei deploy legge solo due cose, che il deploy fornisce ( vedi
[cosa fornisce un deploy glisweb](#cosa-fornisce-un-deploy-glisweb) ), e per il resto non ne usa né il codice né il
database.

file                       | cosa fa
---------------------------|-----------------------------------------------------------------------------------
`src/uptime.php`           | la logica: siti, profili, controlli, isteresi, consenso, registro, avvisi
`src/giro.php`             | un giro completo
`bin/check`                | il comando del giro: lo lancia il timer, a mano si usa per provare
`bin/install`              | installa e toglie timer systemd e alias Apache della vista
`www/vista.php`            | la vista del server, servita agli altri server del cluster col token
`esempi/uptime.yaml`       | un esempio di configurazione del cluster, coi segnaposto
`tests/`                   | i test, PHPUnit
`/etc/glisweb/uptime.yaml` | la configurazione del cluster, su ogni server, **mai versionata**
`/var/lib/glisweb-check-24x7/` | i dati del server: la sua vista ( `stato.json` ) e il registro ( `registro.jsonl` )

architettura
============

il giro di un minuto
--------------------
Ogni server ha un timer systemd, `glisweb-check-24x7.timer`, che una volta al minuto lancia `bin/check`. Il giro:

1. legge `/etc/glisweb/uptime.yaml`;
2. chiede agli altri server la loro **vista**: i siti che ospitano e lo stato che vedono;
3. raccoglie i siti da controllare di questo server, ci aggiunge quelli degli altri e gli esterni;
4. li controlla, quattro alla volta, e applica l'**isteresi**;
5. decide lo stato di ogni sito col **consenso** fra i server;
6. scrive la vista, il registro e il `DOWNTIME.md`, e manda gli avvisi.

Non è un demone: un giro che si pianta non ne blocca altri, e systemd non lancia il successivo finché il precedente
non è finito.

quali siti si controllano
-------------------------
Un deploy glisweb è una document root `<radice>/<progetto>/<cartella>` con `_src/_config.php`, dove la radice è di
default `/var/www` e la cartella è `dev`, `stable` o `test`: la produzione di solito sta in `stable`. Per ogni
deploy il giro prende gli host dei virtual host HTTPS di Apache ( o nginx ) che hanno quella document root, col
`ServerName`: gli alias restano fuori, perché di solito sono le varianti col `www`. Poi tiene un host solo se:

1. il suo profilo è fra quelli da controllare ( vedi sotto ), o il suo `etc/uptime/<host>.yaml` dice `attivo: true`.
   Il profilo lo ricava `uptimeProfilo()` con la stessa regola del bootstrap glisweb ( `_src/_config/_015.site.php`
   e `site2regexp()` ), sulla configurazione del deploy letta come la legge il bootstrap, shadow compresi. Un host a
   cui nessun sito risponde vale come `DEV`, come nel bootstrap;
2. non è nell'elenco `escludi:` del cluster, che vince su tutto;
3. si risolve nel **DNS pubblico** verso un IP pubblico. La verifica non legge `/etc/hosts`, e vale anche per gli
   host forzati con `attivo: true`: un host che gli altri server non raggiungono darebbe solo falsi down.

quali profili si controllano
----------------------------
I profili da controllare sono un elenco di modelli alla `fnmatch()`: `PROD*` vuol dire `PROD`, `PRODWMS`,
`PRODSITO`...; `DEV` il solo `DEV`; `*` tutti. La scelta sta su tre livelli, e il più specifico vince:

livello  | dove                                      | cosa
---------|-------------------------------------------|--------------------------------------------------------------
cluster  | `profili:` di `/etc/glisweb/uptime.yaml`  | i profili da controllare su tutti i deploy del server; di default `[ "*" ]`, tutti
deploy   | `profili:` di `etc/uptime.yaml` nel deploy | sostituisce l'elenco del cluster per gli host di quel deploy
host     | `attivo:` di `etc/uptime/<host>.yaml`     | `true` lo controlla qualunque sia il profilo, `false` mai

Col default `[ "*" ]` non serve configurare niente: si controlla ogni host servito, e un deploy di sviluppo che fa
da produzione per il cliente ( profilo `DEV`, ma usato davvero ) rientra senza cambiargli il profilo, che
cambierebbe anche il comportamento dell'applicazione. I livelli servono a restringere: un cluster che vuole solo la
produzione scrive `profili: [ "PROD*" ]` nello yaml; un deploy che, da lì, vuole anche il suo ambiente di prova
scrive

```yaml
# etc/uptime.yaml
profili: [ "PROD*", "TEST*" ]
```

e un host singolo si accende o si spegne con `attivo: true` o `false` nel suo `etc/uptime/<host>.yaml`.

Gli host vengono dai virtual host e non dai domini della configurazione, che decidono solo il profilo: un dominio
`PROD` può stare su un altro server.

Un controllo misura codice HTTP, testo atteso, tempo di risposta e giorni di validità del certificato. Fallisce
per un errore di rete o di TLS, per il timeout, per un codice diverso da quello atteso o per il testo assente.

isteresi
--------
Per ogni server, un sito è **giù** al terzo fallimento consecutivo ( `soglia` ) e torna su al primo controllo
riuscito. Allo stesso modo è **lento** quando risponde oltre `lento` secondi per `soglia` giri di fila, e torna
normale al primo giro sotto: una prova sola non basta, perché un deploy senza memcache alla prima richiesta a
freddo risponde in 6-7 secondi e alle successive in due.

consenso fra i server
---------------------
Lo stato che conta non è quello di un server solo:

stato              | quando
-------------------|----------------------------------------------------------------------------------------
su                 | il server lo vede su, oppure lo vede giù ma un altro server che lo controlla lo vede su
giù                | il server lo vede giù e almeno un altro server lo vede giù
non confermato     | il server lo vede giù e nessun altro server risponde

**Un down è confermato solo se lo vedono almeno due server.** Se un altro server vede su il sito, il problema è del
primo server, non del sito.

la vista e lo scambio fra i server
----------------------------------
Ogni server scrive la sua vista in `stato.json`, nella cartella dei dati, e la pubblica da solo: `bin/install`
aggiunge ad Apache l'alias globale `/glisweb-check-24x7/`, che punta a `www/` del progetto, e gli altri server la
leggono da

```
https://<un host qualsiasi del server>/glisweb-check-24x7/vista.php
```

presentando il token del cluster nell'header `X-Glisweb-Uptime`. Senza il token giusto la risposta è un 403 vuoto:
la vista contiene i nomi dei siti, e non si espone. L'alias è globale, quindi vale per ogni virtual host del server,
e la richiesta non passa da nessun deploy: risponde anche se il deploy che ospita quell'host è rotto o ha il
database giù. Basta che risponda Apache.

Un server pubblica solo i siti che ospita, così un sito tolto non resta in giro. Gli esterni non si pubblicano: ogni
server li legge dalla sua configurazione, che è uguale su tutti, e pubblica solo l'impronta della sezione ( vedi
[i siti esterni](#i-siti-esterni) ).

registro e DOWNTIME.md
----------------------
Nella cartella dei dati ( `/var/lib/glisweb-check-24x7/`, o quella del campo `dati:` ) ogni server tiene:

- `stato.json`, la sua vista: i siti che ospita e, per ogni sito controllato, fallimenti, giri lenti, stato locale,
  stato deciso e ultimo esito;
- `registro.jsonl`, in sola aggiunta: un evento `inizio` e uno `fine` per ogni periodo di down, con durata, server
  che l'hanno visto e conferma, e un evento `lento` e uno `normale` per ogni periodo di lentezza.

Da quel registro il giro riscrive il `DOWNTIME.md` nella cartella del progetto del sito ( quella che contiene il
deploy ), se il progetto sta su questo server: una riga per periodo di down, dal più recente, con inizio, fine,
durata, conferma e motivo. Il file è generato e non si modifica a mano. La lentezza non ci entra. Gli esterni non
hanno un progetto sul server, e restano solo nel registro.

cosa fornisce un deploy glisweb
===============================
Il controllo non dipende dai deploy, ma ne legge due cose, che fanno parte del framework glisweb e sono descritte
anche nel suo manuale ( capitolo `125.uptime` ):

1. **`/status/uptime`**: la pagina pubblica che risponde `OK` con 200 se il deploy funziona, e `KO` con 503 se il
   deploy ha un database configurato e la connessione non c'è. Non dice altro. Su un deploy che ce l'ha ( il file
   `_src/_api/_status/_uptime.php` esiste ) si controlla quella; su un deploy non ancora aggiornato si controlla la
   home, e vale qualunque risposta sotto il 500, perché la home di un gestionale può essere un redirect al login o
   un 404;
2. **`etc/uptime/<host>.yaml`**: la configurazione di un sito, nel suo deploy, versionata col progetto ( vedi
   [la configurazione di un sito](#la-configurazione-di-un-sito) ).

Se il framework cambia la regola con cui dà il profilo a un host ( `site2regexp()` ), va cambiata anche
`uptimeRegexp()` qui, che ne è la copia, insieme al suo test.

installazione
=============
L'installazione la fa chi amministra il server, una volta per server. Servono PHP 7.3 o più con le estensioni
`curl` e `yaml`, `composer`, `git`, systemd e, per la vista, Apache.

1. **Prendere il codice** in `/opt`:

   ```
   git clone https://github.com/istricesrl/glisweb-check-24x7.git /opt/glisweb-check-24x7
   cd /opt/glisweb-check-24x7
   COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev
   ```

2. **Scrivere `/etc/glisweb/uptime.yaml`**, uguale su tutti i server tranne `server:` ( vedi
   [la configurazione del cluster](#la-configurazione-del-cluster); un esempio in `esempi/uptime.yaml` ). Lo legge
   anche Apache, per la vista, e contiene token e credenziali SMTP: `bin/install` gli dà `root:www-data` e `640`.

3. **Provare a secco**: controlla tutto e stampa, senza scrivere e senza avvisare. Va guardato l'elenco dei siti
   controllati, degli scartati col motivo, e delle viste ricevute dagli altri server.

   ```
   /opt/glisweb-check-24x7/bin/check --secco
   ```

4. **Provare gli avvisi**: manda una mail di prova agli indirizzi di `avvisa.noi`, e dice perché se non parte. Va
   verificato che arrivi davvero, perché il giro non ritenta un avviso fallito.

   ```
   /opt/glisweb-check-24x7/bin/check --prova-avviso
   ```

5. **Installare** timer e alias della vista, e controllarli:

   ```
   /opt/glisweb-check-24x7/bin/install install
   systemctl list-timers glisweb-check-24x7.timer
   curl -s -o /dev/null -w '%{http_code}\n' https://<host del server>/glisweb-check-24x7/vista.php
   curl -s -H 'X-Glisweb-Uptime: <token>' https://<host del server>/glisweb-check-24x7/vista.php | head
   ```

   Il primo `curl` deve dare `403`, il secondo la vista in JSON ( dopo il primo giro: prima è un `404` ).
   `bin/install` crea la cartella dei dati, installa le dipendenze se mancano, scrive
   `glisweb-check-24x7.service` e `.timer` e li avvia, scrive l'alias in
   `/etc/apache2/conf-available/glisweb-check-24x7.conf`, lo abilita e ricarica Apache **solo se il configtest
   passa**; altrimenti lo toglie e si ferma. Su un server senza Apache stampa le istruzioni per servire la vista a
   mano.

6. **Ripetere su ogni server del cluster**, ognuno col suo `server:`.

migrazione dal controllo che stava nel framework
------------------------------------------------
`bin/install install` fa da solo la migrazione: se trova il vecchio timer `glisweb-uptime.timer` lo ferma e ne
toglie le unità, e se lo yaml ha ancora il campo `deploy:` copia `stato.json` e `registro.jsonl` da
`<deploy>/var/uptime/` nella cartella dei dati, se lì non ci sono ancora, così il registro non riparte da zero.
Dopo, a mano:

- togliere `deploy:` dallo yaml, che non serve più e si ignora;
- cambiare gli indirizzi di `cluster:` da `https://<host>/status/uptime` a
  `https://<host>/glisweb-check-24x7/vista.php`, su tutti i server. Finché un server non è migrato, gli altri non ne
  ricevono la vista, e i suoi down risultano non confermati.

aggiornare
----------

```
cd /opt/glisweb-check-24x7
git pull
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev
bin/check --secco
```

Il timer usa il codice nuovo dal giro successivo, senza riavvii. Se è cambiato `bin/install`, rilanciarlo con
`install`: riscrive unità e alias.

disinstallare
-------------

```
/opt/glisweb-check-24x7/bin/install remove
```

Ferma il timer e toglie unità systemd e alias Apache. `/etc/glisweb/uptime.yaml` e la cartella dei dati restano:
il registro è storia, e si tolgono a mano solo se lo si vuole davvero.

configurazione
==============

la configurazione del cluster
-----------------------------
`/etc/glisweb/uptime.yaml` non è versionato e sta su ogni server. Campo per campo:

campo          | obbligatorio | cosa
---------------|--------------|------------------------------------------------------------------------------------
`server`       | sì           | il nome di questo server, così come compare in `cluster:`
`token`        | sì           | il segreto condiviso fra i server, uguale su tutti; chi lo presenta vede la vista
`cluster`      | sì           | per ogni server, l'indirizzo della sua vista: `https://<un suo host>/glisweb-check-24x7/vista.php`
`smtp`         | sì           | il server per gli avvisi: `host` ( default `localhost` ), `port` ( 25 ), `user`, `pasw`, `from`
`avvisa`       | sì           | gli avvisi di default: `noi`, l'elenco degli indirizzi nostri
`radice`       | no           | la cartella dei progetti, di default `/var/www/`
`dati`         | no           | la cartella di vista e registro, di default `/var/lib/glisweb-check-24x7/`
`paralleli`    | no           | i controlli contemporanei, di default 4
`escludi`      | no           | gli host da non controllare, qualunque cosa dicano deploy e host
`profili`      | no           | i modelli dei profili da controllare, di default `[ "*" ]`, tutti; vedi [quali profili si controllano](#quali-profili-si-controllano)
`esterni`      | no           | i siti esterni, vedi sotto

Il token si genera una volta sola, per esempio con `openssl rand -hex 32`, e si copia uguale su tutti i server; non
va scritto in nessun file versionato né in nessun documento. Il campo `deploy:` delle versioni nel framework non
serve più e si ignora. Un esempio, coi segnaposto, è in `esempi/uptime.yaml`.

la configurazione di un deploy
------------------------------
`etc/uptime.yaml` nel deploy, versionato col progetto, ha per ora una chiave sola, `profili:`, che sostituisce
quella del cluster per gli host del deploy ( vedi [quali profili si controllano](#quali-profili-si-controllano) ).
Senza il file, o senza la chiave, vale l'elenco del cluster.

la configurazione di un sito
----------------------------
Un sito di un deploy si personalizza con `etc/uptime/<host>.yaml` nel deploy, versionato col progetto: il file
sovrascrive i default ( `UPTIME_DEFAULT` in `src/uptime.php` ) o dichiara un host che i virtual host non mostrano.
L'host si controlla se il suo profilo è fra quelli da controllare, o se il file dice `attivo: true`. Se due deploy servono lo stesso host vince il primo in ordine alfabetico,
a meno che l'host abbia un suo file in un altro deploy.

campo          | default            | cosa
---------------|--------------------|-------------------------------------------------------------------------
`url`          | `https://<host>/status/uptime` | la pagina controllata; su un deploy non aggiornato la home
`testo`        | `OK`               | il testo che la risposta deve contenere; `null` per nessuno
`codice`       | `200`              | il codice atteso; `null` per qualunque risposta sotto il 500
`timeout`      | `10`               | secondi oltre i quali il controllo fallisce
`lento`        | `3`                | secondi oltre i quali, per `soglia` giri di fila, il sito è lento
`certificato`  | `14`               | giorni di validità sotto i quali si avvisa noi, una volta al giorno
`soglia`       | `3`                | giri consecutivi per dire giù, o lento
`attivo`       | —                  | `true` lo controlla qualunque sia il profilo, `false` lo toglie dal controllo
`avvisa`       | quello del cluster | gli avvisi del sito, vedi sotto

```yaml
lento: 5
avvisa:
  referente: [ "<indirizzo del referente>" ]
  abilitato: true
  ritardo: 10
```

Il campo `avvisa:` di un sito si fonde con quello del cluster, chiave per chiave:

chiave         | default | cosa
---------------|---------|----------------------------------------------------------------------------------
`noi`          | cluster | gli indirizzi nostri; di solito non si tocca
`referente`    | —       | gli indirizzi del referente del sito
`abilitato`    | `false` | senza, il referente non riceve niente anche se c'è
`ritardo`      | `10`    | minuti di down confermato prima di avvisare il referente

i siti esterni
--------------
Un sito che non sta su un server del cluster si elenca in `esterni:` di `/etc/glisweb/uptime.yaml`, per host, con
gli stessi campi di un sito; `url` è obbligatorio, e un sito senza si scarta. Non hanno `/status/uptime`: si guarda
la pagina indicata, col codice 200 e, se c'è, il testo atteso. Valgono isteresi, lentezza, certificato e consenso
come per gli altri, perché li controllano tutti i server: per questo la sezione deve essere uguale su tutti.

Uguale vuol dire scritta una volta e copiata: la sezione si modifica su un server, e lo yaml si copia sugli altri
con lo stesso contenuto. A ogni giro ogni server pubblica nella vista l'impronta della sua sezione, cioè l'md5 con
le chiavi ordinate ( `uptimeImpronta()` ), e la confronta con quella degli altri. Se diverge avvisa noi, una volta
al giorno; `--secco` stampa l'impronta e i server da cui diverge.

L'elenco degli esterni sta **solo** negli yaml dei server: non va mai scritto nel repository, che è pubblico.

avvisi
======
Gli avvisi partono via SMTP diretto con PHPMailer, non da una coda: quella delle mail di un sito vive nel suo
database, proprio quello che potrebbe essere giù. Con la porta 587 si usa STARTTLS, con la 465 TLS implicito.
Avvisa solo uno dei server che vedono giù il sito, il primo in ordine alfabetico, così la mail non arriva doppia.

quando                                         | a chi
-----------------------------------------------|-------------------------------------------------------
il sito è giù, confermato o non confermato     | noi, subito
un down non confermato viene confermato        | noi
il down confermato dura da `ritardo` minuti    | il referente, solo se `abilitato`
il sito torna su                               | chi era stato avvisato: noi, e il referente se lo era stato
certificato in scadenza                        | noi, una volta al giorno
un server non risolve nessuno dei suoi siti    | noi, una volta al giorno
la sezione `esterni:` diverge fra i server     | noi, una volta al giorno
risposta lenta                                 | nessuno: solo il registro

Il referente non riceve mai un down non confermato. Un sito `DEV` o `TEST` acceso ( vedi
[quali profili si controllano](#quali-profili-si-controllano) ) ha gli stessi avvisi di uno di produzione.

esercizio
=========

il journal
----------
Il giro scrive nel journal solo gli eventi ( `inizio`, `fine`, `lento`, `normale` col nome del sito ) e gli errori,
per esempio un avviso non partito, col motivo. Quando va tutto bene non scrive niente.

```
journalctl -u glisweb-check-24x7.service --since today
journalctl -u glisweb-check-24x7.service -p err --since "1 hour ago"
systemctl list-timers glisweb-check-24x7.timer
```

prove a mano
------------
- `bin/check --secco` stampa una riga per sito ( host, stato, fallimenti, tempo, giorni del certificato, errore ),
  poi gli host scartati col motivo ( `profilo DEV`, `profilo nessuno`, `dns` ), le viste ricevute dagli altri
  server, l'impronta degli esterni, gli eventi e gli avvisi che il giro produrrebbe. Non scrive niente e non manda
  niente;
- `bin/check --prova-avviso` manda la mail di prova a `avvisa.noi`;
- `bin/check --config FILE` legge un'altra configurazione: serve a provare senza toccare quella vera.

togliere un sito
----------------
- per un sito di un deploy: `attivo: false` nel suo `etc/uptime/<host>.yaml`, versionato, se la scelta riguarda il
  sito;
- per una scelta del server: l'host in `escludi:` di `/etc/glisweb/uptime.yaml`;
- per un esterno: lo si toglie da `esterni:` e si copia lo yaml sugli altri server.

Se il sito era giù, il primo giro in cui non c'è più chiude il periodo con un evento `fine` marcato `rimosso`, e
la durata arriva fino a lì.

problemi noti e casi limite
===========================

vhost di prova solo locali
--------------------------
Un vhost messo in `/etc/hosts` verso `127.0.0.1` col certificato snakeoil risponde solo dal server stesso, e il
controllo lo vedrebbe giù per il certificato. Si scarta perché non si risolve nel DNS pubblico ( `uptimePubblico()`
interroga il DNS con `dns_get_record()`, che non legge `/etc/hosts` ). Di solito è già fuori anche per il profilo.

resolver del server giù
-----------------------
Se il DNS del server non risponde, nessun sito si risolve, e il controllo si spegnerebbe in silenzio. Il giro se ne
accorge quando il server ha siti da controllare e non ne risolve nessuno, e avvisa noi una volta al giorno.

SMTP rotto
----------
Un avviso che non parte si scrive nel journal, col motivo, e **non si ritenta**: un SMTP rotto altrimenti
manderebbe una mail al minuto appena torna. Per questo all'installazione si fa `--prova-avviso`, e conviene
guardare ogni tanto il journal con `-p err`.

deploy non aggiornati
---------------------
Un deploy senza `/status/uptime` si controlla sulla home, accettando qualunque risposta sotto il 500: si accorge che
il server e PHP non rispondono, non che il database è giù. Si risolve aggiornando il deploy.

host serviti da un altro server
-------------------------------
Un dominio `PROD` della configurazione che il server non serve non si controlla da lì: il giro guarda i virtual
host, non i domini. Se sta su un altro server del cluster lo controlla quello; se sta fuori va negli esterni.

la vista non arriva
-------------------
Se un altro server non riceve la vista di questo, i down che vede lui restano non confermati. Le cause, in ordine:
l'indirizzo in `cluster:` è ancora quello vecchio di `/status/uptime`; l'alias non è attivo
( `apache2ctl -S`, `a2query -c glisweb-check-24x7` ); il token è diverso fra i due yaml; la cartella dei dati non è
leggibile dal gruppo `www-data`. `bin/check --secco` sull'altro server dice quali viste riceve.

il server da cui parte il controllo
-----------------------------------
Se il controllo di un server si ferma, gli altri vedono la sua vista sparire: i loro down diventano non confermati
e l'avviso va solo a noi, che è il segnale. A differenza di quando stava nel framework, non si ferma più per un
deploy rotto o per un aggiornamento notturno: servono PHP, systemd e, per la vista, Apache.

sviluppo e test
===============
Il clone di sviluppo sta in `/usr/local/src/glisweb-check-24x7`, fuori dalle document root e dalle cartelle dei
progetti; quello in `/opt` è solo per l'esercizio, e si aggiorna con `git pull`. Si lavora sul ramo `main`.

```
cd /usr/local/src/glisweb-check-24x7
composer install
vendor/bin/phpunit
```

I test sono due gruppi:

- `tests/UptimeTest.php` prova le funzioni: configurazione, DNS pubblico, profili e modelli dei profili, raccolta
  dei siti da deploy finti in una cartella temporanea coi tre livelli della scelta dei profili, esterni, impronta, virtual host, isteresi, lentezza, consenso, registro e
  `DOWNTIME.md`, avvisi falliti. Chiede al DNS pubblico solo per `localhost`, un dominio `.invalid` ed
  `example.com`;
- `tests/GiroTest.php` è d'integrazione: avvia il server PHP integrato su una porta libera di `127.0.0.1` col
  router di `tests/server/`, e prova la vista ( 403 senza token, 404 prima del primo giro, 200 dopo ), i controlli
  ( su, codice sbagliato, testo assente, timeout ) e il giro completo con `bin/check` su una configurazione
  temporanea: un sito che cade e viene confermato da un secondo server, uno che resta su, un terzo server che non
  risponde, la divergenza degli esterni, il registro, `--secco` che non scrive, il ritorno su. L'SMTP punta a una
  porta chiusa, quindi gli avvisi falliscono e si leggono nell'errore standard.

Ogni modifica porta il suo test, nello stesso commit, e aggiorna questo manuale. Il repository è **pubblico**: niente
segreti, niente token, niente nomi o indirizzi di clienti, niente elenco degli esterni, nemmeno negli esempi e nei
test, dove si usano `example.com` e i segnaposto.

decisioni
=========
Le specifiche di partenza sono di Fabio Mosti, del 04/11/2026. Quello che si è deciso strada facendo, e perché:

decisione                                                    | perché
-------------------------------------------------------------|------------------------------------------------------
fuori dal framework, in `/opt`, con timer suo ( 05/11/2026 )  | un controllo di uptime non deve dipendere da quello che controlla
vista servita da un alias Apache su `www/`, non da un deploy | risponde anche con un deploy rotto; l'alias globale vale per ogni host del server, e non serve un vhost nuovo né un certificato
dati in `/var/lib/glisweb-check-24x7/`                       | sono stato del servizio, non del codice: sopravvivono a un `git pull` e a una reinstallazione
regola dei profili copiata ( `uptimeRegexp()` ) e non inclusa | includere `_string.tools.php` di un deploy rimetterebbe la dipendenza; il test ne fissa la forma
avvisi con PHPMailer via composer, non con `sendMail()`      | stessa libreria del framework, senza il framework
si controlla per url, qualunque sia il profilo: default `*` ( 05/11/2026 ) | decisione di Fabio: un cliente può voler controllare anche DEV e TEST, e un DEV può fare da produzione; il 04/11 erano solo i `PROD*`
profili da controllare scelti per cluster, deploy e host ( 05/11/2026 ) | massima flessibilità, per chi vuole restringere
`attivo: true` non salta il controllo del DNS pubblico        | un host che gli altri server non raggiungono darebbe solo falsi down
host dai virtual host e non dai domini della configurazione  | i domini `PROD` non sono sempre quelli serviti dal server
deploy cercati in `dev`, `stable` e `test`                   | la produzione sta di solito in `stable`
`/status/uptime` pubblico solo OK/KO, vista solo col token   | i nomi dei clienti non si espongono
controlli quattro alla volta                                 | lanciati tutti insieme, venti siti dello stesso server rispondevano in 8-10 secondi
home dei deploy non aggiornati: va bene sotto il 500         | la home di un gestionale è spesso un redirect o un 404
scarto degli host non pubblici nel DNS                       | i vhost di prova solo locali davano falsi down
lento con l'isteresi, non con la mediana                     | stesso schema del down, e non triplica le richieste; un DEV a freddo dava falsi lenti
il lento non manda mail                                      | è un'informazione, non un'emergenza
avvisi via SMTP diretto, senza ritentativi                   | la coda vive nel database del sito; un ritentativo al minuto sarebbe peggio
siti esterni nello yaml del cluster, uguale su tutti         | li controllano tutti i server e vale il consenso; l'elenco non sta nel repository pubblico
`DOWNTIME.md` nella cartella del progetto                    | è uno dei file canonici di un progetto glisweb, e lo legge chi amministra il server
test con PHPUnit e il server PHP integrato                   | il progetto non ha il framework, quindi nemmeno la sua suite Codeception
