import { useEffect } from "react";
import { S } from "../utils/constants";
import Card from "../components/Card";

const CGU = () => {
  useEffect(() => { window.scrollTo(0, 0); }, []);

  const articleStyle = { fontFamily: S.font, fontSize: 13, color: S.textMuted, lineHeight: 1.7 };
  const titleStyle = { fontFamily: S.font, fontSize: 15, fontWeight: 800, color: S.gold, marginBottom: 10 };

  return (
    <div style={{ maxWidth: 680, margin: "0 auto", paddingTop: 40, animation: "slideUp 0.5s cubic-bezier(0.16,1,0.3,1)" }}>
      <h2 style={{ fontFamily: S.fontTitle, fontSize: 28, color: S.gold, textAlign: "center", marginBottom: 8 }}>
        Conditions Générales d'Utilisation
      </h2>
      <p style={{ fontFamily: S.font, fontSize: 12, color: S.textMuted, textAlign: "center", marginBottom: 32 }}>
        Version en vigueur au 25 mars 2026
      </p>

      <Card>
        <h3 style={titleStyle}>Article 1 — Objet</h3>
        <p style={articleStyle}>
          nos-elus.com est une plateforme d'agrégation de données publiques relatives aux élus et anciens élus
          de la République française. Le site collecte, compile et présente des informations issues
          de sources officielles et publiques, sans produire ni créer de contenu original.
          Les présentes CGU définissent les conditions d'utilisation du service.
        </p>
      </Card>

      <Card>
        <h3 style={titleStyle}>Article 2 — Sources des données</h3>
        <p style={articleStyle}>
          Toutes les données présentées sur nos-elus.com proviennent exclusivement de sources publiques et ouvertes :
        </p>
        <ul style={{ ...articleStyle, marginTop: 8, paddingLeft: 18 }}>
          <li>Répertoire National des Élus (RNE) — data.gouv.fr</li>
          <li>NosDéputés.fr (Assemblée Nationale open data)</li>
          <li>Haute Autorité pour la Transparence de la Vie Publique (HATVP)</li>
          <li>Wikipedia / Wikimedia Commons</li>
          <li>Journal Officiel de la République Française</li>
          <li>Sénat Open Data</li>
        </ul>
        <p style={{ ...articleStyle, marginTop: 8 }}>
          L'éditeur ne produit ni ne certifie aucun contenu. Il se limite à agréger et présenter
          des données déjà accessibles au public.
        </p>
      </Card>

      <Card>
        <h3 style={titleStyle}>Article 3 — Limitation de responsabilité</h3>
        <p style={articleStyle}>
          Les informations présentées sur nos-elus.com sont fournies <strong style={{ color: S.textMain }}>"en l'état"</strong>,
          sans aucune garantie d'exactitude, d'exhaustivité ou d'actualité. L'éditeur s'efforce de maintenir
          les données à jour mais ne peut garantir l'absence d'erreurs, d'omissions ou de données obsolètes.
        </p>
        <p style={{ ...articleStyle, marginTop: 8 }}>
          En aucun cas l'éditeur ne saurait être tenu responsable de préjudices directs ou indirects
          résultant de l'utilisation des informations présentées. L'utilisateur est seul responsable
          de l'usage qu'il fait des données consultées.
        </p>
      </Card>

      <Card>
        <h3 style={titleStyle}>Article 4 — Présomption d'innocence</h3>
        <p style={articleStyle}>
          nos-elus.com respecte strictement le principe de présomption d'innocence. Le site distingue
          clairement et systématiquement les statuts juridiques associés à toute affaire mentionnée :
        </p>
        <ul style={{ ...articleStyle, marginTop: 8, paddingLeft: 18 }}>
          <li><strong style={{ color: S.textMain }}>Condamné(e)</strong> — décision judiciaire définitive</li>
          <li><strong style={{ color: S.textMain }}>Mis(e) en examen</strong> — enquête judiciaire en cours</li>
          <li><strong style={{ color: S.textMain }}>Relaxé(e) / acquitté(e)</strong> — décision de non-culpabilité</li>
          <li><strong style={{ color: S.textMain }}>Classé sans suite</strong> — procédure close sans poursuites</li>
        </ul>
        <p style={{ ...articleStyle, marginTop: 8 }}>
          Toute mention d'affaire judiciaire est accompagnée de son statut précis et de sa source vérifiable.
        </p>
      </Card>

      <Card>
        <h3 style={titleStyle}>Article 5 — Droit de rectification</h3>
        <p style={articleStyle}>
          Tout élu, ancien élu ou personne concernée par les données publiées sur ce site dispose d'un
          droit de demander la correction ou la suppression de données inexactes, incomplètes ou obsolètes.
        </p>
        <p style={{ ...articleStyle, marginTop: 8 }}>
          Les demandes doivent être adressées par email à l'adresse de contact indiquée dans les Mentions Légales,
          en précisant les données contestées et en fournissant les éléments justificatifs permettant
          d'établir l'inexactitude. L'éditeur s'engage à traiter les demandes dans un délai de 30 jours.
        </p>
      </Card>

      <Card>
        <h3 style={titleStyle}>Article 6 — Propriété intellectuelle et réutilisation</h3>
        <p style={articleStyle}>
          Les données publiques issues de data.gouv.fr et des administrations françaises sont réutilisées
          conformément à la <strong style={{ color: S.textMain }}>Licence Ouverte Etalab v2.0</strong>,
          qui autorise leur libre réutilisation, y compris à des fins commerciales, sous réserve de
          mentionner les sources.
        </p>
        <p style={{ ...articleStyle, marginTop: 8 }}>
          La mise en forme, la présentation et les éléments graphiques originaux du site nos-elus.com
          sont la propriété de l'éditeur. Toute reproduction sans autorisation est interdite.
        </p>
      </Card>

      <Card>
        <h3 style={titleStyle}>Article 7 — Éléments satiriques et liberté d'expression</h3>
        <p style={articleStyle}>
          Certains éléments de nos-elus.com (machine à sous, "casseroles", système de karma, scores ludiques)
          relèvent de la <strong style={{ color: S.textMain }}>satire politique</strong> et de la liberté
          d'expression, tradition protégée en droit français et par la Convention Européenne des Droits
          de l'Homme (article 10).
        </p>
        <p style={{ ...articleStyle, marginTop: 8 }}>
          Ces éléments ludiques sont clairement identifiables comme tels et ne constituent pas des
          affirmations factuelles. Ils visent à encourager l'engagement citoyen par une approche
          pédagogique et accessible des données publiques.
        </p>
      </Card>

      <Card>
        <h3 style={titleStyle}>Article 8 — Non-affiliation</h3>
        <p style={articleStyle}>
          nos-elus.com est un site citoyen indépendant. Il n'est affilié à aucun parti politique,
          mouvement politique, institution publique, ou élu. L'éditeur n'entretient aucune relation
          financière ou éditoriale avec les personnes figurant dans la base de données.
          Le site ne soutient ni ne combat aucune formation politique.
        </p>
      </Card>

      <Card>
        <h3 style={titleStyle}>Article 9 — Scores, radars et classements</h3>
        <p style={articleStyle}>
          Les scores radar, indicateurs de karma et classements présentés sur nos-elus.com sont des
          indicateurs calculés algorithmiquement à partir de données publiques. Ils constituent
          des outils pédagogiques de visualisation et <strong style={{ color: S.textMain }}>n'ont aucune
          valeur officielle</strong>.
        </p>
        <p style={{ ...articleStyle, marginTop: 8 }}>
          Ces indicateurs ne reflètent pas un jugement de valeur de l'éditeur sur les personnes concernées.
          La méthodologie de calcul est disponible sur demande.
        </p>
      </Card>

      <Card>
        <h3 style={titleStyle}>Article 10 — Modification des CGU</h3>
        <p style={articleStyle}>
          L'éditeur se réserve le droit de modifier les présentes Conditions Générales d'Utilisation
          à tout moment, notamment pour se conformer à l'évolution législative et réglementaire.
          Les utilisateurs sont invités à consulter régulièrement cette page.
          La poursuite de l'utilisation du site après modification vaut acceptation des nouvelles CGU.
        </p>
      </Card>
    </div>
  );
};

export default CGU;
