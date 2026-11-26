// lib/app-tickets.js — stuurt een melding van het storingsformulier naar de Watt Watt operatie-app
// (servicebord), in plaats van of naast Pipedrive. Watt Watt stopt met Pipedrive Projects (besluit 26-11-2026).
//
// Instellingen (Vercel > Settings > Environment Variables):
//   WW_APP_TICKET_URL     bijv. https://voorraad.ruben-06c.workers.dev/api_ticket.php (later https://app.wattwatt.nl/api_ticket.php)
//   WW_APP_TICKET_SLEUTEL dezelfde waarde als 'ticket_sleutel' in voorraad-config.php op de server
// Zonder deze twee slaat de koppeling zichzelf over, net als de Pipedrive-koppeling zonder token.

const URGENTIE = { P1: "acuut", P2: "spoed", P3: "normaal", P4: "laag" };

function tekstBlok(gegevens, triage, fotos, belofte) {
  const r = [];
  if (gegevens.symptoomLabel) r.push(`Wat de klant merkt: ${gegevens.symptoomLabel}`);
  if (gegevens.sindsLabel) r.push(`Sinds: ${gegevens.sindsLabel}`);
  if (gegevens.omvangLabel) r.push(`Omvang: ${gegevens.omvangLabel}`);
  if (gegevens.foutcode) r.push(`Foutcode: ${gegevens.foutcode}`);
  if (gegevens.veiligheidLabels?.length) r.push(`VEILIGHEID: ${gegevens.veiligheidLabels.join(", ")}`);
  if (gegevens.tekst) r.push("", gegevens.tekst);
  if (gegevens.toelichting) r.push("", `Toelichting: ${gegevens.toelichting}`);
  if (gegevens.bedrijf) r.push("", `Bedrijf: ${gegevens.bedrijf}`);
  const redenen = triage?.redenen || [];
  if (redenen.length) r.push("", "Waarom deze urgentie en afhandeling:", ...redenen.map((x) => `- ${x}`));
  if (belofte) r.push("", `Beloofd aan klant: ${typeof belofte === "string" ? belofte : belofte.tekst || JSON.stringify(belofte)}`);
  const links = (fotos || []).filter((f) => f.url).map((f) => `- ${f.naam}: ${f.url.startsWith("http") ? f.url : (process.env.NEXT_PUBLIC_SITE_URL || "https://wattwatt-service.vercel.app") + f.url}`);
  if (links.length) r.push("", "Foto's:", ...links);
  return r.join("\n").trim();
}

export async function pushNaarApp({ gegevens, triage, fotos = [], belofte, nr }) {
  const url = process.env.WW_APP_TICKET_URL;
  const sleutel = process.env.WW_APP_TICKET_SLEUTEL;
  if (!url || !sleutel) return { overgeslagen: "geen WW_APP_TICKET_URL of WW_APP_TICKET_SLEUTEL" };

  const body = {
    ticketnummer: nr,
    ref: gegevens.formId || nr,
    naam: gegevens.naam,
    telefoon: gegevens.telefoon,
    email: gegevens.email,
    adres: gegevens.adres,
    apparaat: gegevens.productLabel,            // "Energieopslag" | "Zonnepanelen" | "Laadpaal" | "Onbekend"
    urgentie: URGENTIE[triage?.urgentie] || "normaal",
    afhandeling: triage?.afhandeling || "",     // "Op afstand", "Eerst op afstand proberen", ...
    foutcode: gegevens.foutcode,
    wat_merkt: gegevens.symptoomLabel,
    sinds: gegevens.sindsLabel,
    omschrijving: tekstBlok(gegevens, triage, fotos, belofte),
  };
  const res = await fetch(url, {
    method: "POST",
    headers: { "Content-Type": "application/json", "X-WW-Sleutel": sleutel },
    body: JSON.stringify(body),
    signal: AbortSignal.timeout(20_000),
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok || !data.ok) throw new Error(`app ${res.status}: ${data.fout || "onbekende fout"}`);
  return { nummer: data.nummer, nieuw: data.nieuw };
}
