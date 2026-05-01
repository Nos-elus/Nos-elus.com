// Données mock — sera remplacé par des appels API en production
export const ELUS = [
  {
    id: 1, nom: "Marine Le Pen", parti: "RN", emoji: "🦅", couleur: "#1a237e",
    alias: ["La Blonde", "MLP", "La Mère Le Pen"],
    photo_url: "/photos/marine-le-pen.png",
    fonction: "Députée du Pas-de-Calais",
    mandats: ["Députée du Pas-de-Calais (2012-présent)", "Députée européenne (2004-2017)", "Conseillère régionale Nord-Pas-de-Calais (1998-2004)", "Conseillère régionale Hauts-de-France (2015-2017)"],
    affaires: [
      { titre: "Emplois fictifs PE", statut: "Condamné", date: "2024", detail: "Détournement de fonds publics — condamnée en première instance", gravite: 4 },
      { titre: "Prêt russe au FN", statut: "En cours", date: "2014", detail: "Financement du parti par une banque russe", gravite: 3 },
    ],
    affiliations: [
      { nom: "Jordan Bardella", lien: "Président du RN", emoji: "🤝" },
      { nom: "Louis Aliot", lien: "Vice-président du RN", emoji: "🔗" },
    ],
    votes: [
      { sujet: "Réforme des retraites 2023", position: "Contre" },
      { sujet: "Loi immigration 2023", position: "Pour" },
    ],
    patrimoine: "Déclaration HATVP 2022 disponible",
    patrimoine_detail: {
      annee: 2022, immobilier: 420000, mobilier: 38000, revenus_annuels: 89916, total: 458000,
      fortune_estimee: 1000000, fortune_source: "Capital 2023",
      salaires: [
        { poste: "Députée du Pas-de-Calais", montant_mensuel: 7493, actif: true },
      ],
      salaire_cumul_mensuel: 7493,
      evolution: [{ annee: 2017, total: 310000 }, { annee: 2022, total: 458000 }],
      sources: ["Résidence principale (Pas-de-Calais)", "Compte-titres"],
    },
    historique_partis: [{ parti: "FN", annee: 1992 }, { parti: "RN", annee: 2018 }],

    radar: { integrite: 3, transparence: 3, assiduite: 7, coherence: 6, bilan: 4 },
  },
  {
    id: 2, nom: "Nicolas Sarkozy", parti: "LR", emoji: "🏛️", couleur: "#0d47a1",
    alias: ["Sarko", "Le Petit Nicolas", "L'Hyperactif"],
    photo_url: "/photos/nicolas-sarkozy.jpg",
    fonction: "Ancien Président de la République",
    mandats: ["Président de la République (2007-2012)", "Ministre de l'Intérieur (2002-2004, 2005-2007)", "Maire de Neuilly-sur-Seine (1983-2002)", "Député des Hauts-de-Seine (1988-2002)", "Président de l'UMP (2004-2007)"],
    affaires: [
      { titre: "Affaire Bygmalion", statut: "Condamné", date: "2021", detail: "Financement illégal de campagne — 1 an ferme", gravite: 5 },
      { titre: "Affaire des écoutes", statut: "Condamné", date: "2021", detail: "Corruption et trafic d'influence — 3 ans dont 1 ferme", gravite: 5 },
      { titre: "Affaire Libyenne", statut: "En cours", date: "2013", detail: "Financement libyen présumé de la campagne 2007", gravite: 4 },
    ],
    affiliations: [
      { nom: "Brice Hortefeux", lien: "Proche historique", emoji: "🤝" },
      { nom: "Claude Guéant", lien: "Ancien dir. cabinet", emoji: "🔗" },
    ],
    votes: [],
    patrimoine: "Déclaration HATVP 2013 disponible",
    patrimoine_detail: {
      annee: 2013, immobilier: 1800000, mobilier: 700000, revenus_annuels: 3500000, total: 2500000,
      fortune_estimee: 55000000, fortune_source: "Le Point 2023",
      salaires: [
        { poste: "Ancien Président de la République (retraite)", montant_mensuel: 6220, actif: true },
        { poste: "Conférences internationales", montant_mensuel: 150000, actif: true },
        { poste: "Conseil auprès d'entreprises", montant_mensuel: 80000, actif: true },
      ],
      salaire_cumul_mensuel: 236220,
      evolution: [{ annee: 2008, total: 980000 }, { annee: 2013, total: 2500000 }],
      sources: ["Appartement Paris 16e", "Résidence secondaire Cap Nègre", "Contrats de conférences", "Activités de conseil international"],
    },
    historique_partis: [{ parti: "RPR", annee: 1983 }, { parti: "UMP", annee: 2002 }, { parti: "LR", annee: 2015 }],

    radar: { integrite: 2, transparence: 2, assiduite: 8, coherence: 5, bilan: 6 },
  },
  {
    id: 3, nom: "François Hollande", parti: "PS", emoji: "🌹", couleur: "#e91e63",
    alias: ["Flamby", "Pépère", "Monsieur Normal"],
    photo_url: "/photos/francois-hollande.png",
    fonction: "Ancien Président / Député",
    mandats: ["Président de la République (2012-2017)", "Député de Corrèze (1997-2012)", "Député de Corrèze (2024-présent)", "Maire de Tulle (2001-2008)", "Premier secrétaire du PS (1997-2008)", "Président du Conseil général de Corrèze (2008-2012)"],
    affaires: [],
    affiliations: [
      { nom: "Julie Gayet", lien: "Conjointe", emoji: "💑" },
      { nom: "Manuel Valls", lien: "Ancien 1er ministre", emoji: "🔗" },
    ],
    votes: [
      { sujet: "Loi Travail (El Khomri)", position: "Pour (49.3)" },
      { sujet: "Mariage pour tous", position: "Pour" },
    ],
    patrimoine: "Déclaration HATVP 2017 disponible",
    patrimoine_detail: {
      annee: 2022, immobilier: 850000, mobilier: 35000, revenus_annuels: 350000, total: 1200000,
      fortune_estimee: 3500000, fortune_source: "BFM Business 2023",
      salaires: [
        { poste: "Député de Corrèze", montant_mensuel: 7493, actif: true },
        { poste: "Ancien Président de la République (retraite)", montant_mensuel: 6220, actif: true },
        { poste: "Conférences", montant_mensuel: 15000, actif: true },
      ],
      salaire_cumul_mensuel: 28713,
      evolution: [{ annee: 2012, total: 540000 }, { annee: 2017, total: 772000 }, { annee: 2022, total: 1200000 }],
      sources: ["Résidence Corrèze", "Appartement Paris", "Droits d'auteur (livres)", "Conférences internationales"],
    },
    historique_partis: [{ parti: "PS", annee: 1979 }],

    radar: { integrite: 7, transparence: 5, assiduite: 6, coherence: 4, bilan: 5 },
  },
  {
    id: 4, nom: "Jean-Luc Mélenchon", parti: "LFI", emoji: "✊", couleur: "#c62828",
    alias: ["Méluche", "Le Tribun", "JLM"],
    photo_url: "/photos/jean-luc-melenchon.png",
    fonction: "Député des Bouches-du-Rhône",
    mandats: ["Député des Bouches-du-Rhône (2017-2022)", "Député de l'Essonne (2012-2017)", "Ministre délégué à l'Enseignement professionnel (2000-2002)", "Sénateur de l'Essonne (1986-2000)"],
    affaires: [
      { titre: "Perquisition LFI", statut: "Relaxé", date: "2019", detail: "Rébellion lors d'une perquisition au siège de LFI", gravite: 2 },
    ],
    affiliations: [
      { nom: "Mathilde Panot", lien: "Présidente groupe LFI", emoji: "🤝" },
      { nom: "Manuel Bompard", lien: "Coordinateur LFI", emoji: "🔗" },
    ],
    votes: [
      { sujet: "Réforme des retraites 2023", position: "Contre" },
      { sujet: "Loi immigration 2023", position: "Contre" },
    ],
    patrimoine: "Déclaration HATVP 2022 disponible",
    patrimoine_detail: {
      annee: 2022, immobilier: 600000, mobilier: 45000, revenus_annuels: 89916, total: 800000,
      fortune_estimee: 1500000, fortune_source: "Capital 2022",
      salaires: [
        { poste: "Député des Bouches-du-Rhône", montant_mensuel: 7493, actif: true },
      ],
      salaire_cumul_mensuel: 7493,
      evolution: [{ annee: 2017, total: 550000 }, { annee: 2022, total: 800000 }],
      sources: ["Appartement Paris 18e", "Appartement Marseille", "Livret A", "Droits d'auteur (livres)"],
    },
    historique_partis: [{ parti: "PS", annee: 1977 }, { parti: "LFI", annee: 2016 }],

    radar: { integrite: 6, transparence: 4, assiduite: 8, coherence: 7, bilan: 5 },
  },
  {
    id: 5, nom: "Emmanuel Macron", parti: "Renaissance", emoji: "🟡", couleur: "#ff8f00",
    alias: ["Manu", "Jupiter", "Le Président des Riches"],
    photo_url: "/photos/emmanuel-macron.jpg",
    fonction: "Président de la République",
    mandats: ["Président de la République (2017-présent)", "Ministre de l'Économie (2014-2016)", "Secrétaire général adjoint de l'Élysée (2012-2014)"],
    affaires: [
      { titre: "Affaire McKinsey", statut: "En cours", date: "2022", detail: "Recours massif à des cabinets de conseil — enquête du Sénat", gravite: 3 },
      { titre: "Affaire Uber Files", statut: "En cours", date: "2022", detail: "Liens privilégiés avec Uber quand ministre de l'Économie", gravite: 3 },
    ],
    affiliations: [
      { nom: "Brigitte Macron", lien: "Conjointe", emoji: "💑" },
      { nom: "Gabriel Attal", lien: "Ancien 1er ministre", emoji: "🤝" },
    ],
    votes: [],
    patrimoine: "Déclaration HATVP 2022 disponible",
    patrimoine_detail: {
      annee: 2022, immobilier: 0, mobilier: 310000, revenus_annuels: 181680, total: 500000,
      fortune_estimee: 3000000, fortune_source: "Le Point 2022",
      salaires: [
        { poste: "Président de la République", montant_mensuel: 15140, actif: true },
      ],
      salaire_cumul_mensuel: 15140,
      evolution: [{ annee: 2014, total: 3000000 }, { annee: 2017, total: 620000 }, { annee: 2022, total: 500000 }],
      sources: ["Assurances-vie", "Comptes courants", "Revenus Rothschild (avant 2014)"],
    },
    historique_partis: [{ parti: "PS", annee: 2006 }, { parti: "En Marche", annee: 2016 }, { parti: "LREM", annee: 2018 }, { parti: "Renaissance", annee: 2022 }],

    radar: { integrite: 5, transparence: 3, assiduite: 9, coherence: 4, bilan: 5 },
  },
  {
    id: 6, nom: "Éric Zemmour", parti: "Reconquête", emoji: "⚔️", couleur: "#37474f",
    alias: ["Z", "Le Polémiste", "Zemmour le Terrible"],
    photo_url: "/photos/eric-zemmour.jpg",
    fonction: "Ancien candidat présidentiel",
    mandats: [],
    affaires: [
      { titre: "Condamnation incitation haine", statut: "Condamné", date: "2022", detail: "Provocation à la haine raciale — multiples condamnations", gravite: 4 },
    ],
    affiliations: [
      { nom: "Marion Maréchal", lien: "Ex vice-présidente", emoji: "🔗" },
      { nom: "Sarah Knafo", lien: "Compagne", emoji: "💑" },
    ],
    votes: [],
    patrimoine: "Non déclaré (non élu)",
    patrimoine_detail: {
      annee: 2022, immobilier: 1200000, mobilier: 350000, revenus_annuels: 800000, total: 2000000,
      fortune_estimee: 4000000, fortune_source: "Le Point 2022",
      salaires: [
        { poste: "Droits d'auteur (livres)", montant_mensuel: 25000, actif: true },
        { poste: "Chroniqueur / éditorialiste", montant_mensuel: 15000, actif: false },
      ],
      salaire_cumul_mensuel: 25000,
      evolution: [{ annee: 2018, total: 1200000 }, { annee: 2022, total: 2000000 }],
      sources: ["Appartement Paris", "Droits d'auteur best-sellers", "Placements financiers"],
    },

    radar: { integrite: 3, transparence: 3, assiduite: 2, coherence: 6, bilan: 2 },
  },
  {
    id: 7, nom: "Édouard Philippe", parti: "Horizons", emoji: "🌊", couleur: "#00838f",
    alias: ["Edouard la Barbe", "Le Havrais", "Barbe Grise"],
    photo_url: "/photos/edouard-philippe.png",
    fonction: "Maire du Havre / Ancien 1er Ministre",
    mandats: ["Premier ministre (2017-2020)", "Maire du Havre (2010-2017, 2020-présent)", "Député de la Seine-Maritime (2012-2017)"],
    affaires: [],
    affiliations: [
      { nom: "Emmanuel Macron", lien: "Président", emoji: "🤝" },
      { nom: "Gérald Darmanin", lien: "Allié politique", emoji: "🔗" },
    ],
    votes: [],
    patrimoine: "Déclaration HATVP 2020 disponible",
    patrimoine_detail: {
      annee: 2020, immobilier: 450000, mobilier: 55000, revenus_annuels: 72000, total: 600000,
      fortune_estimee: 1200000, fortune_source: "Capital 2023",
      salaires: [
        { poste: "Maire du Havre", montant_mensuel: 6000, actif: true },
        { poste: "Premier ministre", montant_mensuel: 15900, actif: false },
        { poste: "Droits d'auteur (livres)", montant_mensuel: 5000, actif: true },
      ],
      salaire_cumul_mensuel: 11000,
      evolution: [{ annee: 2017, total: 480000 }, { annee: 2020, total: 600000 }],
      sources: ["Appartement Le Havre", "Parts SCI", "Droits d'auteur (romans policiers)"],
    },
    historique_partis: [{ parti: "UMP", annee: 1995 }, { parti: "LR", annee: 2015 }, { parti: "Horizons", annee: 2021 }],

    radar: { integrite: 8, transparence: 6, assiduite: 8, coherence: 7, bilan: 7 },
  },
  {
    id: 8, nom: "François Fillon", parti: "LR", emoji: "🧊", couleur: "#283593",
    alias: ["Fifi", "Mister Costard", "Penelopegate"],
    photo_url: "/photos/francois-fillon.jpg",
    fonction: "Ancien Premier Ministre",
    mandats: ["Premier ministre (2007-2012)", "Député de Paris (2012-2017)", "Député de la Sarthe (1981-2007)", "Ministre des Affaires sociales (2002-2004)", "Ministre de l'Éducation nationale (2004-2005)"],
    affaires: [
      { titre: "Penelopegate", statut: "Condamné", date: "2020", detail: "Emplois fictifs de Penelope Fillon — 5 ans dont 2 ferme", gravite: 5 },
      { titre: "Costumes de luxe", statut: "Classé", date: "2017", detail: "Acceptation de costumes offerts par un avocat", gravite: 2 },
    ],
    affiliations: [
      { nom: "Penelope Fillon", lien: "Conjointe", emoji: "💑" },
      { nom: "Bruno Retailleau", lien: "Soutien politique", emoji: "🔗" },
    ],
    votes: [],
    patrimoine: "Déclaration HATVP 2012 disponible",
    patrimoine_detail: {
      annee: 2017, immobilier: 900000, mobilier: 120000, revenus_annuels: 320000, total: 1200000,
      fortune_estimee: 4000000, fortune_source: "Le Canard Enchaine 2017",
      salaires: [
        { poste: "Député de Paris", montant_mensuel: 7493, actif: false },
        { poste: "Consultant international", montant_mensuel: 50000, actif: true },
      ],
      salaire_cumul_mensuel: 50000,
      evolution: [{ annee: 2007, total: 1800000 }, { annee: 2012, total: 2480000 }, { annee: 2017, total: 1200000 }],
      sources: ["Domaine de Solesmes (Sarthe)", "Appartement Paris 7e", "Emplois fictifs Penelope (900k remboursement)", "Conseil international"],
    },
    historique_partis: [{ parti: "RPR", annee: 1981 }, { parti: "UMP", annee: 2002 }, { parti: "LR", annee: 2015 }],

    radar: { integrite: 2, transparence: 2, assiduite: 7, coherence: 5, bilan: 5 },
  },
  {
    id: 9, nom: "Rachida Dati", parti: "LR / Renaissance", emoji: "⚡", couleur: "#6a1b9a",
    alias: ["La Lionne", "Rachida la Battante"],
    photo_url: "/photos/rachida-dati.jpg",
    fonction: "Ministre de la Culture",
    mandats: ["Ministre de la Culture (2024-présent)", "Garde des Sceaux (2007-2009)", "Maire du 7e arrondissement de Paris (2008-2024)", "Députée européenne (2009-2019)"],
    affaires: [
      { titre: "Affaire Ghosn/Renault", statut: "En cours", date: "2020", detail: "Soupçons de corruption passive — mise en examen", gravite: 4 },
    ],
    affiliations: [
      { nom: "Nicolas Sarkozy", lien: "Mentor politique", emoji: "🤝" },
      { nom: "Emmanuel Macron", lien: "Président", emoji: "🔗" },
    ],
    votes: [],
    patrimoine: "Déclaration HATVP 2024 disponible",
    patrimoine_detail: {
      annee: 2024, immobilier: 320000, mobilier: 35000, revenus_annuels: 160000, total: 400000,
      fortune_estimee: 800000, fortune_source: "Le Point 2024",
      salaires: [
        { poste: "Ministre de la Culture", montant_mensuel: 10135, actif: true },
        { poste: "Maire du 7e arrondissement de Paris", montant_mensuel: 2800, actif: false },
        { poste: "Avocate (cabinet)", montant_mensuel: 12000, actif: false },
      ],
      salaire_cumul_mensuel: 10135,
      evolution: [{ annee: 2009, total: 210000 }, { annee: 2024, total: 400000 }],
      sources: ["Appartement Paris 7e", "Honoraires d'avocate (activite suspendue)"],
    },
    historique_partis: [{ parti: "UMP", annee: 2003 }, { parti: "LR", annee: 2015 }, { parti: "LR / Renaissance", annee: 2024 }],

    radar: { integrite: 4, transparence: 3, assiduite: 7, coherence: 3, bilan: 5 },
  },
  {
    id: 10, nom: "Sandrine Rousseau", parti: "EELV", emoji: "🌿", couleur: "#2e7d32",
    alias: ["L'Écoféministe", "Madame Barbecue"],
    photo_url: "/photos/sandrine-rousseau.png",
    fonction: "Députée de Paris",
    mandats: ["Députée de Paris (2022-présent)", "Vice-présidente de l'université de Lille (2016-2020)"],
    affaires: [],
    affiliations: [
      { nom: "Yannick Jadot", lien: "Ex-rival primaire", emoji: "🔗" },
      { nom: "Marine Tondelier", lien: "Secrétaire nationale EELV", emoji: "🤝" },
    ],
    votes: [
      { sujet: "Réforme des retraites 2023", position: "Contre" },
      { sujet: "Loi immigration 2023", position: "Contre" },
    ],
    patrimoine: "Déclaration HATVP 2022 disponible",
    patrimoine_detail: {
      annee: 2022, immobilier: 160000, mobilier: 12000, revenus_annuels: 89916, total: 200000,
      fortune_estimee: 300000, fortune_source: "HATVP 2022",
      salaires: [
        { poste: "Deputee de Paris", montant_mensuel: 7493, actif: true },
        { poste: "Vice-presidente universite de Lille", montant_mensuel: 4500, actif: false },
      ],
      salaire_cumul_mensuel: 7493,
      evolution: [{ annee: 2022, total: 200000 }],
      sources: ["Appartement Paris (copropriete)", "Livrets epargne"],
    },

    radar: { integrite: 7, transparence: 6, assiduite: 6, coherence: 5, bilan: 4 },
  },
  {
    id: 11, nom: "Gérald Darmanin", parti: "Renaissance", emoji: "🛡️", couleur: "#e65100",
    alias: ["Darma", "Le Bulldozer", "Gégé"],
    photo_url: "/photos/gerald-darmanin.png",
    fonction: "Ministre de la Justice",
    mandats: ["Ministre de la Justice (2024-présent)", "Ministre de l'Intérieur (2020-2024)", "Maire de Tourcoing (2014-2020)", "Député du Nord (2012-2017)"],
    affaires: [
      { titre: "Accusations de viol", statut: "Classé sans suite", date: "2018", detail: "Plainte classée sans suite puis relancée — non-lieu", gravite: 3 },
    ],
    affiliations: [
      { nom: "Emmanuel Macron", lien: "Président", emoji: "🤝" },
      { nom: "Nicolas Sarkozy", lien: "Ancien mentor", emoji: "🔗" },
    ],
    votes: [],
    patrimoine: "Déclaration HATVP 2024 disponible",
    patrimoine_detail: {
      annee: 2024, immobilier: 220000, mobilier: 35000, revenus_annuels: 121620, total: 300000,
      fortune_estimee: 600000, fortune_source: "Capital 2024",
      salaires: [
        { poste: "Ministre de la Justice", montant_mensuel: 10135, actif: true },
        { poste: "Maire de Tourcoing", montant_mensuel: 4500, actif: false },
      ],
      salaire_cumul_mensuel: 10135,
      evolution: [{ annee: 2020, total: 380000 }, { annee: 2024, total: 300000 }],
      sources: ["Appartement Tourcoing", "Comptes epargne"],
    },
    historique_partis: [{ parti: "UMP", annee: 2008 }, { parti: "LR", annee: 2015 }, { parti: "Renaissance", annee: 2020 }],

    radar: { integrite: 4, transparence: 4, assiduite: 8, coherence: 5, bilan: 5 },
  },
  {
    id: 12, nom: "François Bayrou", parti: "MoDem", emoji: "🟠", couleur: "#ef6c00",
    alias: ["Le Béarnais", "Bayrou l'Éternel", "Le Centriste"],
    photo_url: "/photos/francois-bayrou.png",
    fonction: "Premier Ministre",
    mandats: ["Premier ministre (2024-présent)", "Maire de Pau (2014-présent)", "Ministre de l'Éducation nationale (1993-1997)", "Député des Pyrénées-Atlantiques (1986-2012)", "Député européen (1999-2002)"],
    affaires: [
      { titre: "Emplois fictifs MoDem", statut: "Relaxé", date: "2024", detail: "Assistants parlementaires européens — relaxé en première instance", gravite: 3 },
    ],
    affiliations: [
      { nom: "Emmanuel Macron", lien: "Président", emoji: "🤝" },
      { nom: "Marielle de Sarnez", lien: "Bras droit historique (†)", emoji: "🕊️" },
    ],
    votes: [],
    patrimoine: "Déclaration HATVP 2024 disponible",
    patrimoine_detail: {
      annee: 2024, immobilier: 600000, mobilier: 55000, revenus_annuels: 190800, total: 800000,
      fortune_estimee: 2000000, fortune_source: "Le Point 2024",
      salaires: [
        { poste: "Premier ministre", montant_mensuel: 15900, actif: true },
        { poste: "Maire de Pau", montant_mensuel: 4500, actif: false },
        { poste: "Droits d'auteur (livres)", montant_mensuel: 3000, actif: true },
      ],
      salaire_cumul_mensuel: 18900,
      evolution: [{ annee: 2014, total: 620000 }, { annee: 2024, total: 800000 }],
      sources: ["Maison Pau", "Appartement Paris", "Vignoble familial (Bearn)", "Droits d'auteur"],
    },

    radar: { integrite: 6, transparence: 5, assiduite: 7, coherence: 6, bilan: 5 },
  },
];
