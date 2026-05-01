import { S } from "../utils/constants";

const Card = ({ children, highlight }) => (
  <div style={{
    background: S.card, borderRadius: 12, padding: "16px 20px", marginBottom: 10,
    border: `1px solid ${highlight ? highlight + "22" : S.border}`,
    transition: "transform 0.2s",
  }}
  onMouseEnter={e => e.currentTarget.style.transform = "translateY(-1px)"}
  onMouseLeave={e => e.currentTarget.style.transform = "translateY(0)"}
  >{children}</div>
);

export default Card;
