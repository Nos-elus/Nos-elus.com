import { useEffect } from "react";
import { S } from "../utils/constants";
import Card from "../components/Card";

const Confidentialite = () => {
  useEffect(() => { window.scrollTo(0, 0); }, []);

  const articleStyle = { fontFamily: S.font, fontSize: 13, color: S.textMuted, lineHeight: 1.7 };
  const titleStyle = { fontFamily: S.font, fontSize: 15, fontWeight: 800, color: S.gold, marginBottom: 10 };

  return (
    <div style={{ maxWidth: 680, margin: "0 auto", paddingTop: 40, animation: "slideUp 0.5s cubic-bezier(0.16,1,0.3,1)" }}>
      <h2 style={{ fontFamily: S.fontTitle, fontSize: 28, color: S.gold, textAlign: "center", marginBottom: 8 }}>
        Politique de Confidentialité
      </h2>
      <p style={{ fontFamily: S.font, fontSize: 12, color: S.textMuted, textAlign: "center", marginBottom: 32 }}>
        Conformément au Règlement Général sur la Protection des Données (RGPD — UE 2016/679) — Dernière mise à jour : 30 avril 2026
      </p>

      <Card>
        <h3 style={titleStyle}>Article 1 — Responsable du traitement</h3>
        <p style={articleStyle}>
          <strong style={{ color: S.textMain }}>Identité :</strong> Projet Nos Élus — projet citoyen collaboratif
        </p>
        <p style={{ ...articleStyle, marginTop: 6 }}>
          <strong style={{ color: S.textMain }}>Contact RGPD :</strong> Noselusforms@protonmail.com
        </p>
        <p style={{ ...articleStyle, marginTop: 6, fontSize: 12, fontStyle: "italic" }}>
          Conformément à l'article 6-III-2 de la LCEN, l'éditeur d'un site à activité non professionnelle
          peut préserver son anonymat. Les demandes RGPD sont traitées via l'adresse email ci-dessus.
        </p>
      </Card>

      <Card>
        <h3 style={titleStyle}>Article 2 — Données des visiteurs</h3>
        <p style={articleStyle}>
          <strong style={{ color: S.textMain }}>nos-elus.com ne collecte aucune donnée personnelle de manière automatique.</strong>
        </p>
        <ul style={{ ...articleStyle, marginTop: 8, paddingLeft: 18 }}>
          <li>Pas de cookies tiers ni de traceurs publicitaires</li>
          <li>Pas de système d'analyse de trafic avec données personnelles</li>
          <li>Pas de compte utilisateur ni d'inscription</li>
        </ul>
        <p style={{ ...articleStyle, marginTop: 8 }}>
          <strong style={{ color: S.textMain }}>Formulaire de contact :</strong> le formulaire de contact collecte
          votre nom et email uniquement pour répondre à votre message. Ces données sont stockées de façon
          <strong style={{ color: S.textMain }}> chiffrée (AES-256-CBC) sur le serveur d'hébergement en Islande</strong>,
          accessibles uniquement à l'équipe du projet. Elles ne sont jamais transmises à des tiers et sont supprimées
          après traitement. L'adresse IP n'est pas enregistrée en clair — seul un hash cryptographique
          irréversible (SHA-256) est utilisé pour la protection anti-spam.
        </p>
        <p style={{ ...articleStyle, marginTop: 8 }}>
          <strong style={{ color: S.textMain }}>Votes citoyens :</strong> les votes like/dislike et les votes présidentielle 2027
          sont stockés via un <strong style={{ color: S.textMain }}>hash cryptographique irréversible (SHA-256 avec sel)</strong> de
          l'adresse IP. Il est techniquement impossible de retrouver l'adresse IP d'origine à partir du hash.
          Aucune donnée nominative n'est conservée.
        </p>
        <p style={{ ...articleStyle, marginTop: 8 }}>
          <strong style={{ color: S.textMain }}>Chiffrement :</strong> toutes les communications entre votre navigateur
          et le serveur sont chiffrées via <strong style={{ color: S.textMain }}>HTTPS (TLS 1.3)</strong> avec un certificat
          Let's Encrypt. Aucune donnée ne transite en clair sur le réseau.
        </p>
        <p style={{ ...articleStyle, marginTop: 8 }}>
          Le site utilise uniquement le <strong style={{ color: S.textMain }}>localStorage</strong> du
          navigateur à des fins purement techniques (mise en cache des résultats de recherche).
          Ces données restent strictement locales sur votre appareil.
        </p>
      </Card>

      <Card>
        <h3 style={titleStyle}>Article 3 — Données relatives aux élus</h3>
        <p style={articleStyle}>
          Les données présentées sur les élus constituent des <strong style={{ color: S.textMain }}>données
          publiques</strong> au sens de l'article L. 312-1 du Code des relations entre le public et
          l'administration (CRPA). Elles ont été rendues publiques par les personnes concernées dans
          le cadre de leurs fonctions électives ou par les autorités compétentes.
        </p>
        <p style={{ ...articleStyle, marginTop: 8 }}>
          Le traitement de ces données est fondé sur l'<strong style={{ color: S.textMain }}>intérêt
          légitime</strong> de l'éditeur et du public à l'information citoyenne, conformément à
          l'article 6.1.f du RGPD. Cet intérêt légitime est établi par :
        </p>
        <ul style={{ ...articleStyle, marginTop: 8, paddingLeft: 18 }}>
          <li>Le statut public des personnes concernées (élus mandatés par les citoyens)</li>
          <li>Le caractère public des données issues de sources officielles</li>
          <li>L'objectif d'information citoyenne et de transparence démocratique</li>
          <li>L'absence d'utilisation à des fins commerciales ou de profilage</li>
        </ul>
      </Card>

      <Card>
        <h3 style={titleStyle}>Article 4 — Photos et images</h3>
        <p style={articleStyle}>
          Les photos utilisées pour illustrer les profils d'élus proviennent exclusivement de sources
          publiques sous licences libres :
        </p>
        <ul style={{ ...articleStyle, marginTop: 8, paddingLeft: 18 }}>
          <li>Wikimedia Commons (licences Creative Commons)</li>
          <li>Parlement européen (photos officielles)</li>
          <li>Assemblée Nationale, Sénat (photos officielles)</li>
          <li>Conseil constitutionnel (photos officielles)</li>
          <li>NosDéputés.fr (données ouvertes)</li>
        </ul>
        <p style={{ ...articleStyle, marginTop: 8 }}>
          Les crédits photographiques sont conservés et disponibles sur demande.
        </p>
      </Card>

      <Card>
        <h3 style={titleStyle}>Article 5 — Droits des personnes concernées</h3>
        <p style={articleStyle}>
          Conformément aux articles 15 à 21 du RGPD, toute personne dont les données sont traitées dispose des droits suivants :
        </p>
        <ul style={{ ...articleStyle, marginTop: 8, paddingLeft: 18 }}>
          <li><strong style={{ color: S.textMain }}>Droit d'accès (art. 15)</strong> — connaître les données traitées vous concernant</li>
          <li><strong style={{ color: S.textMain }}>Droit de rectification (art. 16)</strong> — faire corriger des données inexactes</li>
          <li><strong style={{ color: S.textMain }}>Droit à l'effacement (art. 17)</strong> — obtenir la suppression de vos données</li>
          <li><strong style={{ color: S.textMain }}>Droit à la limitation (art. 18)</strong> — restreindre le traitement de vos données</li>
          <li><strong style={{ color: S.textMain }}>Droit d'opposition (art. 21)</strong> — vous opposer au traitement de vos données</li>
        </ul>
        <p style={{ ...articleStyle, marginTop: 8 }}>
          Ces droits s'exercent par email à <strong style={{ color: S.textMain }}>Noselusforms@protonmail.com</strong> ou
          via le <a href="/contact" style={{ color: S.gold, textDecoration: "none" }}>formulaire de contact</a>.
          L'éditeur s'engage à répondre dans un délai maximum de <strong style={{ color: S.textMain }}>30 jours</strong>.
        </p>
        <p style={{ ...articleStyle, marginTop: 8 }}>
          En cas de réponse insatisfaisante, vous pouvez introduire une réclamation auprès de la{" "}
          <strong style={{ color: S.textMain }}>CNIL</strong> (Commission Nationale de l'Informatique
          et des Libertés) — cnil.fr.
        </p>
      </Card>

      <Card>
        <h3 style={titleStyle}>Article 6 — Transferts et sous-traitance</h3>
        <p style={articleStyle}>
          <strong style={{ color: S.textMain }}>Aucun transfert de données hors de l'Union Européenne</strong> n'est
          effectué. Le site est hébergé par FlokiNET ehf., établi en Islande (juridiction IMMI).
        </p>
        <p style={{ ...articleStyle, marginTop: 8 }}>
          <strong style={{ color: S.textMain }}>Aucun profilage automatisé</strong> ni décision automatique
          n'est effectué à partir des données des visiteurs. Les scores et indicateurs calculés
          concernent les données publiques des élus et non les visiteurs du site.
        </p>
      </Card>

      <Card>
        <h3 style={titleStyle}>Article 7 — Mise à jour de cette politique</h3>
        <p style={articleStyle}>
          Cette politique de confidentialité peut être modifiée pour refléter l'évolution du site
          ou des obligations légales. La date de dernière mise à jour est indiquée en haut de cette page.
        </p>
      </Card>
    </div>
  );
};

export default Confidentialite;
