<script>
  import { store, selectCard, rotateCard, flipCard } from "../lib/state.svelte.js";
  import { highlightCells } from "../lib/cards.js";
  import Board from "./Board.svelte";
  import Card from "./Card.svelte";

  const me = $derived(store.you);
  const myTurn = $derived(me?.your_turn ?? false);
  const currentName = $derived(
    store.players.find((p) => p.seat === store.currentSeat)?.name ?? "—",
  );
  const legalCount = $derived(
    myTurn ? highlightCells(me?.legal, store.selected, store.rotation, store.mirror).length : 0,
  );
</script>

<div class="game">
  <header class="bar">
    <span class="turn" class:me={myTurn}>{myTurn ? "Your turn" : `${currentName}'s turn`}</span>
    <span class="scores">
      {#each store.players as p}
        <span class="score tcard-{p.color}">{store.scores[p.color] ?? 0}</span>
      {/each}
    </span>
  </header>

  {#if store.error}<p class="err">{store.error}</p>{/if}

  <Board />

  {#if myTurn && me?.hand}
    <footer class="hand">
      {#each [["top", me.hand.top], ["bottom", me.hand.bottom]] as [which, id]}
        {#if id}
          <button class="handcard" class:sel={store.selected === which} onclick={() => selectCard(which)}>
            <Card
              cardId={id}
              deck={store.deck}
              rotation={store.selected === which ? store.rotation : 0}
              mirror={store.selected === which ? store.mirror : false}
              size={58}
            />
            <small>{which}</small>
          </button>
        {/if}
      {/each}
      <div class="controls">
        <button class="btn-sm" onclick={rotateCard}>⟳ Rotate</button>
        <button class="btn-sm" class:on={store.mirror} onclick={flipCard}>⇄ Flip</button>
        <span class="hint">
          {#if legalCount > 0}
            {legalCount} legal spot{legalCount === 1 ? "" : "s"} — click a highlighted cell.
          {:else}
            No legal spot in this orientation — rotate or flip the card.
          {/if}
        </span>
      </div>
    </footer>
  {/if}
</div>
