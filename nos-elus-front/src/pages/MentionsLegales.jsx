import { useEffect } from "react";
import { S } from "../utils/constants";
import Card from "../components/Card";

const MentionsLegales = () => {
  useEffect(() => { window.scrollTo(0, 0); }, []);

  const articleStyle = { fontFamily: S.font, fontSize: 13, color: S.textMuted, lineHeight: 1.7 };
  const titleStyle = { fontFamily: S.font, fontSize: 15, fontWeight: 800, color: S.gold, marginBottom: 10 };

  return (
    <div style={{ maxWidth: 680, margin: "0 auto", paddingTop: 40, animation: "slideUp 0.5s cubic-bezier(0.16,1,0.3,1)" }}>
      <h2 style={{ fontFamily: S.fontTitle, fontSize: 28, color: S.gold, textAlign: "center", marginBottom: 8 }}>
        Mentions Légales
      </h2>
      <p style={{ fontFamily: S.font, fontSize: 12, color: S.textMuted, textAlign: "center", marginBottom: 32 }}>
        Conformément à la loi n° 2004-575 du 21 juin 2004 pour la confiance en l'économie numérique (LCEN)
      </p>

      <Card>
        <h3 style={titleStyle}>Éditeur du site</h3>
        <p style={articleStyle}>
          <strong style={{ color: S.textMain }}>Nom :</strong> Projet Nos Élus
        </p>
        <p style={{ ...articleStyle, marginTop: 6 }}>
          <strong style={{ color: S.textMain }}>Nature :</strong> Projet citoyen collaboratif, indépendant et non affilié à un parti politique.
        </p>
        <p style={{ ...articleStyle, marginTop: 6 }}>
          <strong style={{ color: S.textMain }}>Contact :</strong> Noselusforms@protonmail.com
        </p>
        <p style={{ ...articleStyle, marginTop: 6 }}>
          <strong style={{ color: S.textMain }}>Directeur de publication :</strong> Projet Nos Élus (publication collective)
        </p>
        <p style={{ ...articleStyle, marginTop: 10, fontSize: 12, fontStyle: "italic" }}>
          Conformément à l'article 6-III-2 de la LCEN, l'éditeur d'un site dont l'activité est non professionnelle
          peut ne pas divulguer son identité personnelle. Le site est édité à titre non lucratif dans un objectif
          d'information citoyenne. Le code source est disponible en open source sur <a href="https://github.com/Nos-elus/Nos-elus.com" target="_blank" rel="noopener noreferrer" style={{ color: S.gold }}>GitHub</a>.
        </p>
      </Card>

      <Card>
        <h3 style={titleStyle}>Hébergeur</h3>
        <p style={articleStyle}>
          <strong style={{ color: S.textMain }}>FlokiNET ehf.</strong>
        </p>
        <p style={{ ...articleStyle, marginTop: 4 }}>
          Bergstaðastræti 37<br />
          101 Reykjavík, Islande<br />
          <a href="https://flokinet.is" target="_blank" rel="noopener noreferrer" style={{ color: S.gold }}>flokinet.is</a>
        </p>
        <p style={{ ...articleStyle, marginTop: 8, fontSize: 12, fontStyle: "italic" }}>
          L'hébergeur est établi en Islande, sous la juridiction islandaise (IMMI —
          Icelandic Modern Media Initiative), reconnue pour ses protections étendues
          de la liberté de la presse et de l'expression.
        </p>
      </Card>

      <Card>
        <h3 style={titleStyle}>Nature du site</h3>
        <p style={articleStyle}>
          nos-elus.com est un <strong style={{ color: S.textMain }}>agrégateur de données publiques</strong>.
          Le site ne produit ni ne génère de contenu original. Il compile et présente des informations
          issues de sources officielles et ouvertes relatives aux élus de la République française.
        </p>
        <p style={{ ...articleStyle, marginTop: 8 }}>
          Les données sont réutilisées conformément à la Licence Ouverte Etalab v2.0 et aux conditions
          d'utilisation des sources citées dans les CGU.
        </p>
      </Card>

      <Card>
        <h3 style={titleStyle}>CNIL — Protection des données</h3>
        <p style={articleStyle}>
          Ce site est <strong style={{ color: S.textMain }}>dispensé de déclaration auprès de la CNIL</strong>
          {" "}conformément à la délibération n° 2006-138 du 9 mai 2006, dans la mesure où il ne collecte
          aucune donnée personnelle sur les visiteurs en dehors du formulaire de contact. Les messages de contact
          sont stockés de façon chiffrée (AES-256) sur le serveur d'hébergement et ne sont jamais transmis à des tiers.
        </p>
        <p style={{ ...articleStyle, marginTop: 8 }}>
          Aucun cookie tiers, aucun système de tracking, aucun compte utilisateur ne sont mis en œuvre.
          Pour plus d'informations, consultez notre{" "}
          <a href="/confidentialite" style={{ color: S.gold, textDecoration: "none" }}>
            Politique de Confidentialité
          </a>.
        </p>
      </Card>

      <Card>
        <h3 style={titleStyle}>Propriété intellectuelle</h3>
        <p style={articleStyle}>
          Le site nos-elus.com est un projet <strong style={{ color: S.textMain }}>open source</strong> publié
          sous licence <strong style={{ color: S.textMain }}>CC BY-NC-SA 4.0</strong> (Creative Commons Attribution — Pas d'Utilisation Commerciale — Partage dans les Mêmes Conditions).
          Toute utilisation commerciale est strictement interdite. Le code source est disponible sur <a href="https://github.com/Nos-elus/Nos-elus.com" target="_blank" rel="noopener noreferrer" style={{ color: S.gold }}>GitHub</a>.
        </p>
        <p style={{ ...articleStyle, marginTop: 8 }}>
          Les données publiques agrégées sont la propriété de leurs sources
          respectives et sont réutilisées sous licence ouverte.
          La marque "Nos Élus" et les éléments graphiques originaux sont la propriété du projet.
        </p>
      </Card>

      <Card>
        <h3 style={titleStyle}>Droit applicable et juridiction</h3>
        <p style={articleStyle}>
          Les présentes mentions légales sont soumises au droit français.
          En cas de litige, les tribunaux français seront seuls compétents.
        </p>
      </Card>
    </div>
  );
};

export default MentionsLegales;
