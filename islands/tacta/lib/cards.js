// Pure helpers for rendering cards from the server's deck layouts. No game logic.
export const SIDES = ["N", "E", "S", "W"];
const INDEX = { N: 0, E: 1, S: 2, W: 3 };
const FROM_INDEX = ["N", "E", "S", "W"];

export const COLORS = ["blue", "green", "orange", "pink", "purple", "red"];

export function parseCardId(cardId) {
  const [color, n] = cardId.split("-");
  return { color, index: Number(n) - 1 };
}

/**
 * The edge shown on a world-facing side after mirror (swap E/W) + clockwise
 * rotation — mirrors the PHP PlacedCard::edgeAt transform, for rendering only.
 */
export function edgeAt(layout, worldSide, rotation, mirror) {
  let i = (INDEX[worldSide] - rotation + 4) % 4;
  if (mirror) {
    if (i === 1) i = 3;
    else if (i === 3) i = 1;
  }
  return layout.edges[FROM_INDEX[i]];
}

/**
 * The server's `you.legal` lists every legal orientation of both outermost cards.
 * Highlight only the placements for the *chosen* hand card AND the player's current
 * rotation/mirror — so the player's flip/twist choice is honoured and a card is never
 * silently re-oriented to fit. An orientation with no legal placement returns [].
 */
export function highlightCells(legal, selected, rotation, mirror) {
  const drawEnd = selected === "bottom" ? "bottom" : "top";
  return (legal ?? []).filter(
    (m) => m.draw_end === drawEnd && m.rotation === rotation && m.mirror === mirror,
  );
}
