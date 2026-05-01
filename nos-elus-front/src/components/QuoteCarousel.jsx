import { useState, useEffect, useCallback, useRef } from "react";
import { S, QUOTES } from "../utils/constants";

const QuoteCarousel = () => {
  const [quoteIndex, setQuoteIndex] = useState(Math.floor(Math.random() * QUOTES.length));
  const [quoteFade, setQuoteFade] = useState(true);
  const timerRef = useRef(null);

  const goTo = useCallback((direction) => {
    setQuoteFade(false);
    setTimeout(() => {
      setQuoteIndex(p =>
        direction === "next"
          ? (p + 1) % QUOTES.length
          : (p - 1 + QUOTES.length) % QUOTES.length
      );
      setQuoteFade(true);
    }, 300);
  }, []);

  // Auto-advance toutes les 15s
  useEffect(() => {
    clearInterval(timerRef.current);
    timerRef.current = setInterval(() => goTo("next"), 11000);
    return () => clearInterval(timerRef.current);
  }, [goTo]);

  const handleNav = (dir) => {
    clearInterval(timerRef.current);
    goTo(dir);
    timerRef.current = setInterval(() => goTo("next"), 11000);
  };

  const arrowStyle = {
    background: "none", border: "none", color: S.textDim, fontSize: 24,
    cursor: "pointer", padding: "4px 8px", borderRadius: 99, transition: "color 0.2s",
    lineHeight: 1, fontFamily: S.font, userSelect: "none",
  };

  return (
    <div style={{ minHeight: 70, display: "flex", alignItems: "center", justifyContent: "center", gap: 12, marginBottom: 28 }}>
      <button onClick={() => handleNav("prev")} style={arrowStyle}
        onMouseEnter={e => e.target.style.color = S.gold} onMouseLeave={e => e.target.style.color = S.textDim}
        aria-label="Citation précédente">‹</button>
      <p style={{
        fontSize: 16, color: S.textMuted, fontStyle: "italic", maxWidth: 420, lineHeight: 1.6,
        transition: "opacity 0.3s ease", opacity: quoteFade ? 1 : 0, textAlign: "center", flex: 1,
      }}>
        &laquo; {QUOTES[quoteIndex].text} &raquo;
        <span style={{ display: "block", marginTop: 4, fontSize: 13, fontStyle: "normal", color: S.textDim, fontWeight: 700 }}>
          — {QUOTES[quoteIndex].author}
        </span>
      </p>
      <button onClick={() => handleNav("next")} style={arrowStyle}
        onMouseEnter={e => e.target.style.color = S.gold} onMouseLeave={e => e.target.style.color = S.textDim}
        aria-label="Citation suivante">›</button>
    </div>
  );
};

export default QuoteCarousel;
