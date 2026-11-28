#!/usr/bin/env python3
"""Voorraadapp-opdrachten voor Claude, via SSH naar de server.

Gebruik:
  python3 vapp.py bestellingen
  python3 vapp.py bestelling <pad/naar/order.json>
  python3 vapp.py project '{"project":"PRJ-289"}'
  python3 vapp.py taken '{"eigenaar":"julian"}'

Testkopie (eigen map en database op de server, niet de echte app):
  VAPP_DOEL=test python3 vapp.py taken

Alleen de opdrachten van inc/cli.php zijn toegestaan; er wordt niets anders op de server uitgevoerd.
"""
import json, os, subprocess, sys

HOST = 'root@188.166.16.61'
SLEUTEL = os.path.expanduser('~/.ssh/wattwatt_voorraad')
CLI = 'php /var/www/voorraad-test/public_html/inc/cli.php' if os.environ.get('VAPP_DOEL') == 'test' else 'php /var/www/voorraad/public_html/inc/cli.php'
OPDRACHTEN = {'locatie', 'installatie', 'installaties', 'ticket_installatie', 'werkbon_concept', 'werkbonnen', 'begroting', 'termijnen', 'factuur_voorstel', 'facturen', 'brein_lijst', 'taak_opschoonvoorstel', 'taak_opschoonvoorstellen', 'oplevering_concept', 'vraag_antwoord', 'claude_log', 'tweestaps_uit', 'brein_slot', 'brein_slot_vrij', 'brein_stand', 'whatsapp', 'whatsapp_in', 'whatsapp_chats', 'whatsapp_indelen', 'bespreekpunt', 'bespreekpunten', 'klant', 'oplevering', 'afspraak', 'afspraken', 'bestellijst', 'bestelling', 'bestellingen', 'dagopdracht', 'dagstatus', 'factuur', 'geleverd', 'help', 'instructies', 'materiaallijst', 'mens', 'mensen', 'migreer', 'mijlpaal', 'mijlpalen', 'notitie_verwerkt', 'notities', 'pipedrive_import', 'plan_voorstel', 'plan_zet', 'planning', 'product', 'project', 'project_bericht', 'project_berichten', 'project_info', 'project_opruimen', 'project_samenvatting', 'projecten', 'taak', 'taak_klaar', 'taak_samenvoegen', 'taak_status', 'taak_vervalt', 'taken', 'ticket', 'tickets', 'voorstellen', 'zoek'}


def main():
    if len(sys.argv) < 2 or sys.argv[1] not in OPDRACHTEN:
        print(json.dumps({'fout': 'gebruik: vapp.py <opdracht> [json-bestand of json-tekst]', 'opdrachten': sorted(OPDRACHTEN)}))
        return 2
    opdracht = sys.argv[1]
    invoer = ''
    if len(sys.argv) > 2:
        arg = sys.argv[2]
        invoer = open(arg, encoding='utf-8').read() if os.path.isfile(arg) else arg
        json.loads(invoer)  # eerst controleren dat het geldige JSON is
    # Via HTTP (api_brein.php) als VAPP_URL en VAPP_SLEUTEL gezet zijn: dan is geen SSH-sleutel nodig en kan het brein ook in de cloud draaien
    url = os.environ.get('VAPP_URL')
    if url and os.environ.get('VAPP_SLEUTEL'):
        import urllib.request, urllib.error
        req = urllib.request.Request(url.rstrip('/') + '/api_brein.php?opdracht=' + opdracht, data=invoer.encode('utf-8'), method='POST',
                                     headers={'Authorization': 'Bearer ' + os.environ['VAPP_SLEUTEL'], 'Content-Type': 'application/json',
                                              # Cloudflare weigert de standaard 'Python-urllib' (fout 1010); daarom een eigen naam
                                              'User-Agent': 'WattWatt-brein/1.0 (+vapp.py)', 'Accept': 'application/json'})
        try:
            with urllib.request.urlopen(req, timeout=120) as resp:
                sys.stdout.write(resp.read().decode('utf-8'))
                return 0
        except urllib.error.HTTPError as e:
            sys.stdout.write(e.read().decode('utf-8', 'replace'))
            return 1
    r = subprocess.run(['ssh', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=20', '-i', SLEUTEL, HOST, f'{CLI} {opdracht}'],
                       input=invoer, capture_output=True, text=True, timeout=120)
    sys.stdout.write(r.stdout)
    if r.returncode != 0:
        sys.stderr.write(r.stderr)
    return r.returncode


if __name__ == '__main__':
    sys.exit(main())
