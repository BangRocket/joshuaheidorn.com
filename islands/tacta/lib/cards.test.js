import { describe, it, expect } from "vitest";
import { edgeAt, parseCardId, highlightCells } from "./cards.js";

// A layout with distinct shapes per side (N/E/S/W).
const layout = {
  edges: {
    N: { shape: "triangle", dots: 1 },
    E: { shape: "square", dots: 0 },
    S: { shape: "rectangle", dots: 2 },
    W: { shape: "square", dots: 1 },
  },
};

describe("parseCardId", () => {
  it("splits color and 1-based index", () => {
    expect(parseCardId("red-7")).toEqual({ color: "red", index: 6 });
  });
});

describe("edgeAt", () => {
  it("identity at rotation 0, no mirror", () => {
    expect(edgeAt(layout, "N", 0, false).shape).toBe("triangle");
    expect(edgeAt(layout, "E", 0, false).shape).toBe("square");
  });

  it("clockwise rotation moves the north edge onto the east face", () => {
    expect(edgeAt(layout, "E", 1, false).shape).toBe("triangle");
  });

  it("mirror swaps east and west", () => {
    expect(edgeAt(layout, "E", 0, true).shape).toBe("square"); // canonical W
    expect(edgeAt(layout, "E", 0, true).dots).toBe(1);
    expect(edgeAt(layout, "N", 0, true).shape).toBe("triangle"); // N/S unaffected
  });
});

describe("highlightCells", () => {
  // Same cell (0,-1) is legal in two different orientations; (1,0) only at rot 2;
  // (0,1) only for the bottom card.
  const legal = [
    { card_id: "red-1", draw_end: "top", x: 0, y: -1, rotation: 0, mirror: false },
    { card_id: "red-1", draw_end: "top", x: 0, y: -1, rotation: 1, mirror: true },
    { card_id: "red-1", draw_end: "top", x: 1, y: 0, rotation: 2, mirror: false },
    { card_id: "red-5", draw_end: "bottom", x: 0, y: 1, rotation: 0, mirror: false },
  ];

  it("returns only cells legal in the chosen card + orientation", () => {
    const cells = highlightCells(legal, "top", 0, false);
    expect(cells).toEqual([
      { card_id: "red-1", draw_end: "top", x: 0, y: -1, rotation: 0, mirror: false },
    ]);
  });

  it("a different orientation lights up different placements", () => {
    expect(highlightCells(legal, "top", 1, true).map((m) => `${m.x},${m.y}`)).toEqual(["0,-1"]);
    expect(highlightCells(legal, "top", 2, false).map((m) => `${m.x},${m.y}`)).toEqual(["1,0"]);
  });

  it("an orientation with no legal placement highlights nothing", () => {
    expect(highlightCells(legal, "top", 3, false)).toEqual([]);
  });

  it("respects the selected hand card (top vs bottom)", () => {
    expect(highlightCells(legal, "bottom", 0, false).map((m) => m.card_id)).toEqual(["red-5"]);
  });

  it("tolerates a missing legal list", () => {
    expect(highlightCells(undefined, "top", 0, false)).toEqual([]);
  });
});
