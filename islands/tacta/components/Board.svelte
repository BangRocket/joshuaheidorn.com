<script>
  import { store, submitMove } from "../lib/state.svelte.js";
  import Card from "./Card.svelte";

  const CELL = 66;

  // Bounds across placed cards + legal targets, so the board auto-centers.
  const placed = $derived(Object.values(store.cells));
  const legal = $derived(
    store.you?.your_turn
      ? (store.you.legal ?? []).filter((m) =>
          store.selected === "top" ? m.draw_end === "top" : m.draw_end === "bottom",
        )
      : [],
  );
  // Deduplicate legal cells (first orientation wins for a given cell).
  const legalCells = $derived(
    Object.values(
      Object.fromEntries(legal.map((m) => [`${m.x},${m.y}`, m])),
    ),
  );

  const xs = $derived([...placed, ...legalCells, { x: 0, y: 0 }].map((c) => c.x));
  const ys = $derived([...placed, ...legalCells, { x: 0, y: 0 }].map((c) => c.y));
  const minX = $derived(Math.min(...xs));
  const minY = $derived(Math.min(...ys));
  const width = $derived((Math.max(...xs) - minX + 1) * CELL);
  const height = $derived((Math.max(...ys) - minY + 1) * CELL);

  function px(x) { return (x - minX) * CELL; }
  function py(y) { return (y - minY) * CELL; }
</script>

<div class="board-scroll">
  <div class="board" style="width:{width}px;height:{height}px">
    <!-- starting card -->
    <div class="cell" style="left:{px(0)}px;top:{py(0)}px">
      <div class="tcard tcard-start" style="width:{CELL - 2}px;height:{CELL - 2}px">★</div>
    </div>

    {#each placed as m (m.z)}
      <div class="cell" style="left:{px(m.x)}px;top:{py(m.y)}px;z-index:{m.z}">
        <Card cardId={m.card_id} deck={store.deck} rotation={m.rotation} mirror={m.mirror} size={CELL - 2} />
      </div>
    {/each}

    {#each legalCells as m (`${m.x},${m.y}`)}
      <button
        class="cell legal"
        style="left:{px(m.x)}px;top:{py(m.y)}px;width:{CELL - 2}px;height:{CELL - 2}px"
        onclick={() => submitMove(m)}
        aria-label="Place here"
      ></button>
    {/each}
  </div>
</div>
