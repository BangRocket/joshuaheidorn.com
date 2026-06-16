import { describe, it, expect } from "vitest";
import { edgeAt, parseCardId } from "./cards.js";

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
