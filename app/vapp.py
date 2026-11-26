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
OPDRACHTEN = {'klant', 'oplevering', 'afspraak', 'afspraken', 'bestellijst', 'bestelling', 'bestellingen', 'dagopdracht', 'dagstatus', 'factuur', 'geleverd', 'help', 'instructies', 'materiaallijst', 'mens', 'mensen', 'migreer', 'mijlpaal', 'mijlpalen', 'notitie_verwerkt', 'notities', 'pipedrive_import', 'plan_voorstel', 'plan_zet', 'planning', 'product', 'project', 'project_bericht', 'project_berichten', 'project_info', 'project_opruimen', 'project_samenvatting', 'projecten', 'taak', 'taak_klaar', 'taak_samenvoegen', 'taak_status', 'taak_vervalt', 'taken', 'ticket', 'tickets', 'voorstellen', 'zoek'}


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
    r = subprocess.run(['ssh', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=20', '-i', SLEUTEL, HOST, f'{CLI} {opdracht}'],
                       input=invoer, capture_output=True, text=True, timeout=120)
    sys.stdout.write(r.stdout)
    if r.returncode != 0:
        sys.stderr.write(r.stderr)
    return r.returncode


if __name__ == '__main__':
    sys.exit(main())
