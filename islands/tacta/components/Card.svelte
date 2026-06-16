<script>
  import { edgeAt, parseCardId, SIDES } from "../lib/cards.js";
  // props: cardId, deck (layouts), rotation, mirror, size
  let { cardId, deck, rotation = 0, mirror = false, size = 64 } = $props();

  const SHAPE = { triangle: "▲", square: "■", rectangle: "▬" };
  const parsed = $derived(parseCardId(cardId));
  const layout = $derived(deck ? deck[parsed.index] : null);
</script>

<div class="tcard tcard-{parsed.color}" style="width:{size}px;height:{size}px">
  {#if layout}
    {#each SIDES as side}
      {@const e = edgeAt(layout, side, rotation, mirror)}
      <span class="edge edge-{side.toLowerCase()}" class:filled={e.dots > 0}>
        {SHAPE[e.shape]}{#if e.dots > 0}<sup>{e.dots}</sup>{/if}
      </span>
    {/each}
    <span class="suit">{layout.value}</span>
  {/if}
</div>
