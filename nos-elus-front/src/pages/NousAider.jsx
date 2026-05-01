import { Link } from "react-router-dom";
import { S } from "../utils/constants";

const TIPEEE = "https://fr.tipeee.com/nos-elus";

const Section = ({ title, icon, color, children }) => (
  <div style={{
    background: S.card, borderRadius: 18, border: `1px solid ${color}33`,
    padding: "28px 28px 24px", marginBottom: 24,
  }}>
    <div style={{
      display: "flex", alignItems: "center", gap: 10, marginBottom: 20,
      borderBottom: `1px solid ${color}22`, paddingBottom: 14,
    }}>
      <span style={{ fontSize: 26 }}>{icon}</span>
      <h2 style={{ fontFamily: S.fontTitle, fontSize: 20, color, margin: 0, letterSpacing: "0.03em" }}>
        {title}
      </h2>
    </div>
    {children}
  </div>
);

const Task = ({ icon, title, desc, badge, color = S.textMuted, difficulty }) => (
  <div style={{
    display: "flex", gap: 14, padding: "14px 0",
    borderBottom: `1px solid ${S.border}`,
  }}>
    <div style={{
      width: 40, height: 40, borderRadius: 10, flexShrink: 0,
      background: `${color}18`, display: "flex", alignItems: "center",
      justifyContent: "center", fontSize: 20,
    }}>{icon}</div>
    <div style={{ flex: 1 }}>
      <div style={{ display: "flex", alignItems: "center", gap: 8, flexWrap: "wrap", marginBottom: 4 }}>
        <span style={{ fontFamily: S.font, fontSize: 14, fontWeight: 800, color: S.textMain }}>{title}</span>
        {badge && <span style={{
          fontSize: 10, fontWeight: 800, padding: "2px 8px", borderRadius: 99,
          background: `${color}22`, color, fontFamily: S.font, textTransform: "uppercase",
        }}>{badge}</span>}
        {difficulty && <span style={{
          fontSize: 10, fontWeight: 700, padding: "2px 8px", borderRadius: 99,
          background: "rgba(255,255,255,0.05)", color: S.textDim, fontFamily: S.font,
        }}>{difficulty}</span>}
      </div>
      <p style={{ fontFamily: S.font, fontSize: 13, color: S.textMuted, margin: 0, lineHeight: 1.6 }}>{desc}</p>
    </div>
  </div>
);

export default function NousAider() {
  return (
    <div style={{ paddingTop: 48, paddingBottom: 80, maxWidth: 780, margin: "0 auto" }}>

      {/* Header */}
      <div style={{ textAlign: "center", marginBottom: 48 }}>
        <h1 style={{ fontFamily: S.fontTitle, fontSize: "clamp(28px,6vw,44px)", color: S.gold, margin: "0 0 12px" }}>
          Nous aider
        </h1>
        <p style={{ color: S.textMuted, fontSize: 15, maxWidth: 520, margin: "0 auto", lineHeight: 1.7 }}>
          nos-elus.com est un projet citoyen indépendant. Voici comment vous pouvez contribuer même sans compétences techniques.
        </p>
      </div>

      {/* Café en tête */}
      <div style={{
        background: `linear-gradient(135deg, rgba(253,203,110,0.12), rgba(253,203,110,0.04))`,
        border: `2px solid ${S.gold}55`, borderRadius: 20,
        padding: "28px 32px", marginBottom: 32, textAlign: "center",
      }}>
        <div style={{ fontSize: 40, marginBottom: 12 }}>☕</div>
        <h2 style={{ fontFamily: S.fontTitle, fontSize: 22, color: S.gold, margin: "0 0 10px" }}>
          Offrir un café
        </h2>
        <p style={{ color: S.textMuted, fontSize: 14, maxWidth: 460, margin: "0 auto 20px", lineHeight: 1.7 }}>
          Le site tourne sur nos deniers personnels. Un café finance les serveurs,
          le nom de domaine et les nuits sans dormir passées à vérifier des données.
        </p>
        <a href={TIPEEE} target="_blank" rel="noopener noreferrer" style={{
          display: "inline-flex", alignItems: "center", gap: 8,
          background: `linear-gradient(135deg, ${S.gold}, #e17055)`,
          color: "#0f0f1a", borderRadius: 12, padding: "12px 28px",
          fontFamily: S.fontTitle, fontSize: 15, fontWeight: 900,
          textDecoration: "none", transition: "opacity 0.2s",
        }}
        onMouseEnter={e => e.currentTarget.style.opacity = "0.85"}
        onMouseLeave={e => e.currentTarget.style.opacity = "1"}
        >
          ☕ Soutenir sur Tipeee
        </a>
      </div>

      {/* Actions physiques */}
      <Section title="Actions physiques" icon="🏛️" color={S.purple}>
        <Task
          icon="📄" color={S.purple}
          badge="Impact fort" difficulty="⏱ 1-2h"
          title="Récupérer une déclaration de patrimoine en préfecture"
          desc="Les élus locaux non soumis à la HATVP (maires de communes < 20 000 hab., conseillers) déposent leurs déclarations à la préfecture. Ces documents sont consultables sur place sur simple demande. Photographiez et envoyez-nous via le formulaire contact."
        />
      </Section>

      {/* Signalements et corrections */}
      <Section title="Signaler et corriger" icon="✏️" color={S.gold}>
        <Task
          icon="🚨" color={S.gold}
          badge="Priorité" difficulty="⏱ 2 min"
          title="Signaler une erreur sur une fiche élu"
          desc="Parti politique incorrect, mandat manquant, photo erronée, date fausse... Utilisez le bouton Signaler sur chaque fiche ou le formulaire Contact. Plus la source est précise (lien article, lien officiel), plus vite on corrige."
        />
        <Task
          icon="📸" color={S.gold}
          badge="Utile" difficulty="⏱ 2 min"
          title="Envoyer une photo d'élu manquante"
          desc="Des milliers de maires n'ont pas de photo sur leur fiche. Si vous avez une photo libre de droits ou prise par vos soins (photo de presse locale, photo officielle mairie), envoyez-la via le formulaire Contact avec le nom et la commune."
        />
        <Task
          icon="⚠️" color={S.gold}
          badge="Impact fort" difficulty="⏱ 5 min"
          title="Signaler une affaire judiciaire non référencée"
          desc="Condamnation, mise en examen, procès en cours non visible sur nos-elus.com ? Envoyez le lien vers une source fiable (presse régionale, jugement public, communiqué parquet). On vérifie et on ajoute."
        />
      </Section>

      {/* Veille et recherche */}
      <Section title="Veille et recherche" icon="🔍" color={S.green}>
        <Task
          icon="📰" color={S.green}
          badge="Régulier" difficulty="⏱ 5 min/semaine"
          title="Surveiller la presse locale"
          desc="France Bleu, Ouest-France, Le Dauphiné, La Voix du Nord... La presse régionale couvre des affaires et des décisions locales invisibles nationalement. Un lien envoyé via Contact suffit."
        />
        <Task
          icon="📜" color={S.green}
          badge="Avancé" difficulty="⏱ variable"
          title="Consulter le Recueil des Actes Administratifs (RAA)"
          desc="Chaque préfecture publie un RAA (souvent sur son site) avec arrêtés, délégations de signature, nominations. Source précieuse pour les mandats et fonctions non référencés dans les bases nationales."
        />
        <Task
          icon="🗳️" color={S.green}
          badge="Post-élection" difficulty="⏱ 10 min"
          title="Transmettre les résultats électoraux locaux"
          desc="Après une élection locale (municipale partielle, sénatoriale...), les résultats officiels mettent des semaines à remonter dans nos bases. Si vous avez le résultat exact (avec pourcentages), envoyez-le avec la source."
        />
        <Task
          icon="🌐" color={S.green}
          badge="Utile" difficulty="⏱ 10 min"
          title="Transmettre des URLs officielles de communes"
          desc="Site officiel de mairie, page Facebook officielle, compte Twitter d'un élu... Ces URLs complètent les fiches et permettent aux citoyens de contacter directement leurs élus."
        />
      </Section>

      {/* Diffusion */}
      <Section title="Faire connaître" icon="📣" color="#e17055">
        <Task
          icon="🔗" color="#e17055"
          badge="Simple" difficulty="⏱ 1 min"
          title="Partager une fiche élu"
          desc="Chaque fiche a des boutons de partage (X, Facebook, LinkedIn, lien direct). Partager une fiche lors d'un débat public, d'une élection ou d'une actualité augmente la visibilité du projet."
        />
        <Task
          icon="🗣️" color="#e17055"
          badge="Simple" difficulty="⏱ variable"
          title="En parler autour de vous"
          desc="Lors des prochaines élections, en conseil municipal, dans une association... La connaissance des élus locaux reste faible. nos-elus.com est un outil pédagogique autant qu'informatif."
        />
        <Task
          icon="✍️" color="#e17055"
          badge="Avancé" difficulty="⏱ variable"
          title="Écrire un article ou un thread"
          desc="Journaliste, blogueur, créateur de contenu ? Une analyse basée sur nos données, un thread sur un palmarès, une vidéo sur les indemnités locales... On peut vous fournir des exports de données."
        />
      </Section>

      {/* Contact */}
      <div style={{
        textAlign: "center", padding: "24px 16px",
        background: "rgba(255,255,255,0.02)", borderRadius: 16,
        border: `1px solid ${S.border}`,
      }}>
        <p style={{ color: S.textMuted, fontSize: 14, marginBottom: 16 }}>
          Pour toute contribution, utilisez le formulaire de contact.
          Précisez votre département et la nature de l'information.
        </p>
        <Link to="/contact" style={{
          display: "inline-flex", alignItems: "center", gap: 6,
          background: "transparent", border: `1px solid ${S.gold}`,
          color: S.gold, borderRadius: 10, padding: "10px 22px",
          fontFamily: S.font, fontSize: 14, fontWeight: 700, textDecoration: "none",
          transition: "all 0.2s",
        }}
        onMouseEnter={e => { e.currentTarget.style.background = `${S.gold}18`; }}
        onMouseLeave={e => { e.currentTarget.style.background = "transparent"; }}
        >
          ✉️ Envoyer une contribution
        </Link>
      </div>

    </div>
  );
}
