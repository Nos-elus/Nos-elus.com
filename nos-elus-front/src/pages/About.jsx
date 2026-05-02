import { S } from "../utils/constants";
import Card from "../components/Card";

const About = () => (
  <div style={{ maxWidth: 680, margin: "0 auto", paddingTop: 40, animation: "slideUp 0.5s cubic-bezier(0.16,1,0.3,1)" }}>
    <h2 style={{ fontFamily: S.fontTitle, fontSize: 28, color: S.gold, textAlign: "center", marginBottom: 32 }}>
      À propos
    </h2>

    <Card>
      <h3 style={{ fontFamily: S.font, fontSize: 16, fontWeight: 800, color: S.textMain, marginBottom: 12 }}>🗳️ C'est quoi nos-elus.com ?</h3>
      <p style={{ fontFamily: S.font, fontSize: 13, color: S.textMuted, lineHeight: 1.7 }}>
        nos-elus.com est une plateforme citoyenne qui permet à tout le monde de rechercher un élu
        de la République et de consulter de manière ludique et transparente :
        ses affaires judiciaires, son réseau politique, ses votes clés et son patrimoine déclaré.
      </p>
    </Card>

    <Card>
      <h3 style={{ fontFamily: S.font, fontSize: 16, fontWeight: 800, color: S.textMain, marginBottom: 12 }}>📋 Sources</h3>
      <p style={{ fontFamily: S.font, fontSize: 13, color: S.textMuted, lineHeight: 1.7, marginBottom: 12 }}>
        Toutes les informations proviennent de sources publiques et vérifiables. Certaines données gouvernementales peuvent être obsolètes.
      </p>
      <div style={{ display: "flex", flexDirection: "column", gap: 6 }}>
        {[
          ["Assemblée nationale — Open Data", "https://data.assemblee-nationale.fr", "Scrutins, votes nominatifs, députés, commissions, questions écrites"],
          ["Sénat — Open Data", "https://data.senat.fr", "Sénateurs, activité parlementaire, questions, rapports"],
          ["NosDéputés.fr (Regards Citoyens)", "https://www.nosdeputes.fr", "Synthèse activité parlementaire, statistiques de présence"],
          ["Parlement européen", "https://www.europarl.europa.eu", "Eurodéputés, fiches, photos officielles"],
          ["HowTheyVote.eu", "https://howtheyvote.eu", "Votes nominatifs des eurodéputés (source GitHub open data)"],
          ["HATVP", "https://www.hatvp.fr", "Déclarations de patrimoine et d'intérêts des élus"],
          ["Répertoire National des Élus (data.gouv.fr)", "https://www.data.gouv.fr/fr/datasets/repertoire-national-des-elus-1/", "Liste officielle des élus de la République"],
          ["Résultats élections municipales 2020 (data.gouv.fr)", "https://www.data.gouv.fr", "Nuances politiques par commune"],
          ["DILA — Open Data CONSTIT", "https://echanges.dila.gouv.fr/OPENDATA/CONSTIT/", "Décisions du Conseil constitutionnel (QPC, DC)"],
          ["API PISTE / JUDILIBRE", "https://piste.gouv.fr", "Décisions de justice (Cour de cassation)"],
          ["LegifrSS (proxy Legifrance)", "https://legifrss.org", "Veille juridique, lois et décisions du Conseil constitutionnel"],
          ["Wikidata", "https://www.wikidata.org", "Données biographiques, mandats historiques, affiliations"],
          ["Wikimedia Commons", "https://commons.wikimedia.org", "Photos libres de droit"],
          ["Service-Public.fr — Annuaire de l'administration", "https://www.service-public.gouv.fr/", "Annuaire officiel des mairies, préfectures et services publics"],
          ["la-mairie.com", "https://www.la-mairie.com", "Coordonnées des mairies (téléphone, email, adresse)"],
          ["Conseil constitutionnel", "https://www.conseil-constitutionnel.fr", "Membres, décisions, compositions"],
        ].map(([name, url, desc], i) => (
          <div key={i} style={{ fontFamily: S.font, fontSize: 12, color: S.textDim, lineHeight: 1.5 }}>
            <a href={url} target="_blank" rel="noreferrer" style={{ color: S.gold, textDecoration: "none", fontWeight: 700 }}>{name}</a>
            <span style={{ color: S.textDim }}> — {desc}</span>
          </div>
        ))}
      </div>
    </Card>

    <Card>
      <h3 style={{ fontFamily: S.font, fontSize: 16, fontWeight: 800, color: S.textMain, marginBottom: 12 }}>⚖️ Mentions légales</h3>
      <p style={{ fontFamily: S.font, fontSize: 13, color: S.textMuted, lineHeight: 1.7 }}>
        Ce site respecte la présomption d'innocence. Les statuts juridiques sont clairement
        distingués : mis en examen, condamné, relaxé, classé sans suite.
        Aucune opinion n'est exprimée — uniquement des faits sourcés.
      </p>
      <p style={{ fontFamily: S.font, fontSize: 13, color: S.textMuted, lineHeight: 1.7, marginTop: 8 }}>
        Hébergeur : FlokiNET ehf. — Bergstaðastræti 37, 101 Reykjavík, Islande
      </p>
    </Card>
    <div style={{ textAlign: "center", marginTop: 32 }}>
      <img src="/Fabrique_en_france.svg" alt="Fabriqué en France" style={{ height: 40, opacity: 0.85 }} />
    </div>
  </div>
);

export default About;
