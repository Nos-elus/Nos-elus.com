import { Radar, RadarChart, PolarGrid, PolarAngleAxis, PolarRadiusAxis, ResponsiveContainer } from "recharts";
import { S } from "../utils/constants";

const RadarProfile = ({ radar, nom }) => {
  const data = [
    { subject: "Intégrité", value: radar.integrite },
    { subject: "Transparence", value: radar.transparence },
    { subject: "Assiduité", value: radar.assiduite },
    { subject: "Cohérence", value: radar.coherence },
    { subject: "Bilan", value: radar.bilan },
  ];
  return (
    <div style={{
      background: S.card, borderRadius: 16, padding: "20px", marginBottom: 24,
      border: `1px solid ${S.border}`,
    }}>
      <div style={{ fontFamily: S.font, fontSize: 12, fontWeight: 800, color: S.textMuted, textTransform: "uppercase", letterSpacing: "0.1em", textAlign: "center", marginBottom: 8 }}>
        📊 Profil Radar
      </div>
      <ResponsiveContainer width="100%" height={250}>
        <RadarChart data={data}>
          <PolarGrid stroke="#2a2a42" />
          <PolarAngleAxis dataKey="subject" tick={{ fill: S.textMuted, fontSize: 11, fontWeight: 700 }} />
          <PolarRadiusAxis domain={[0, 10]} tick={false} axisLine={false} />
          <Radar name={nom} dataKey="value" stroke={S.gold} fill={S.gold} fillOpacity={0.2} strokeWidth={2} />
        </RadarChart>
      </ResponsiveContainer>
    </div>
  );
};

export default RadarProfile;
