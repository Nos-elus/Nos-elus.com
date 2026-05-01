export const IconCasserole = ({size=16, color="#FF6B6B"}) => (
  <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke={color} strokeWidth="2" strokeLinecap="round">
    <path d="M3 14h18v2a4 4 0 01-4 4H7a4 4 0 01-4-4v-2z"/>
    <path d="M3 14c0-3 2-6 9-6s9 3 9 6"/>
    <path d="M12 8V4"/>
    <path d="M9 6V3"/><path d="M15 6V3"/>
  </svg>
);

export const IconStar = ({size=16, color="#00b894"}) => (
  <svg width={size} height={size} viewBox="0 0 24 24" fill={color} stroke="none">
    <path d="M12 2l3.09 6.26L22 9.27l-5 4.87L18.18 21 12 17.27 5.82 21 7 14.14l-5-4.87 6.91-1.01z"/>
  </svg>
);

export const IconNetwork = ({size=16, color="#6c5ce7"}) => (
  <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke={color} strokeWidth="2" strokeLinecap="round">
    <circle cx="12" cy="5" r="3"/>
    <circle cx="5" cy="19" r="3"/>
    <circle cx="19" cy="19" r="3"/>
    <path d="M12 8v3"/>
    <path d="M8.5 14l-2 3"/>
    <path d="M15.5 14l2 3"/>
    <path d="M7 11h10" strokeDasharray="2 2"/>
  </svg>
);

export const IconVS = ({size=16, color="#0984e3"}) => (
  <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke={color} strokeWidth="2" strokeLinecap="round">
    <path d="M6 3l6 9-6 9"/>
    <path d="M18 3l-6 9 6 9"/>
  </svg>
);

export const IconCoffre = ({size=16, color="#fdcb6e"}) => (
  <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke={color} strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
    <rect x="2" y="7" width="20" height="14" rx="2"/>
    <path d="M16 7V5a4 4 0 00-8 0v2"/>
    <circle cx="12" cy="14" r="2"/>
    <path d="M12 16v2"/>
  </svg>
);

export const IconMandat = ({size=16, color="#6c5ce7"}) => (
  <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke={color} strokeWidth="2" strokeLinecap="round">
    <path d="M3 21h18"/>
    <path d="M5 21V7l7-4 7 4v14"/>
    <path d="M9 21v-6h6v6"/>
    <path d="M9 10h.01"/><path d="M15 10h.01"/>
  </svg>
);

export const IconVote = ({size=16, color="#00b894", up=true}) => (
  <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke={color} strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round"
    style={up ? {} : {transform:"rotate(180deg)"}}>
    <path d="M14 9V5a3 3 0 00-6 0v4"/>
    <path d="M4 14h2l1-5h10l1 5h2"/>
    <path d="M6 14v5a2 2 0 002 2h8a2 2 0 002-2v-5"/>
  </svg>
);

export const IconSearch = ({size=16, color="#aaa"}) => (
  <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke={color} strokeWidth="2.5" strokeLinecap="round">
    <circle cx="11" cy="11" r="7"/>
    <path d="M21 21l-4.35-4.35"/>
  </svg>
);

export const IconCalendar = ({size=16, color="#aaa"}) => (
  <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke={color} strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
    <rect x="3" y="4" width="18" height="18" rx="2"/>
    <path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/>
  </svg>
);

export const IconChart = ({size=16, color="#aaa"}) => (
  <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke={color} strokeWidth="2" strokeLinecap="round">
    <path d="M12 2a10 10 0 110 20 10 10 0 010-20z"/>
    <path d="M12 2v10l7 4"/>
  </svg>
);

export const IconBallot = ({size=16, color="#aaa"}) => (
  <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke={color} strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
    <rect x="3" y="3" width="18" height="18" rx="2"/>
    <path d="M12 8v8"/><path d="M8 12h8"/>
  </svg>
);

export const IconDice = ({size=16, color="#fdcb6e"}) => (
  <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke={color} strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
    <rect x="2" y="2" width="20" height="20" rx="3"/>
    <circle cx="8" cy="8" r="1.5" fill={color} stroke="none"/>
    <circle cx="16" cy="8" r="1.5" fill={color} stroke="none"/>
    <circle cx="12" cy="12" r="1.5" fill={color} stroke="none"/>
    <circle cx="8" cy="16" r="1.5" fill={color} stroke="none"/>
    <circle cx="16" cy="16" r="1.5" fill={color} stroke="none"/>
  </svg>
);

export const IconTrophy = ({size=16, color="#fdcb6e"}) => (
  <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke={color} strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
    <path d="M6 9H3a1 1 0 01-1-1V5a1 1 0 011-1h3"/>
    <path d="M18 9h3a1 1 0 001-1V5a1 1 0 00-1-1h-3"/>
    <path d="M6 4h12v6a6 6 0 01-12 0V4z"/>
    <path d="M10 16h4"/><path d="M12 16v4"/><path d="M8 20h8"/>
  </svg>
);
