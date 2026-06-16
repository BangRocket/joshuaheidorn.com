<script>
  import { store } from "../lib/state.svelte.js";
  const ranked = $derived(
    [...store.players]
      .map((p) => ({ ...p, score: store.scores[p.color] ?? 0 }))
      .sort((a, b) => b.score - a.score),
  );
  const top = $derived(ranked.length ? ranked[0].score : 0);
</script>

<div class="over">
  <h1>Game over</h1>
  <ol class="ranking">
    {#each ranked as p}
      <li class:winner={p.score === top}>
        <span class="dot tcard-{p.color}"></span>{p.name} — <b>{p.score}</b>
      </li>
    {/each}
  </ol>
  <a class="btn" href="/tacta">New game</a>
</div>
