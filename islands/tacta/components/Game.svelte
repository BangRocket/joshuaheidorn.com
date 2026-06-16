<script>
  import { store, selectCard } from "../lib/state.svelte.js";
  import Board from "./Board.svelte";
  import Card from "./Card.svelte";

  const me = $derived(store.you);
  const myTurn = $derived(me?.your_turn ?? false);
  const currentName = $derived(
    store.players.find((p) => p.seat === store.currentSeat)?.name ?? "—",
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
      <span class="hint">Pick a card, then click a highlighted cell:</span>
      {#each [["top", me.hand.top], ["bottom", me.hand.bottom]] as [which, id]}
        {#if id}
          <button class="handcard" class:sel={store.selected === which} onclick={() => selectCard(which)}>
            <Card cardId={id} deck={store.deck} size={58} />
            <small>{which}</small>
          </button>
        {/if}
      {/each}
    </footer>
  {/if}
</div>
