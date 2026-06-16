<script>
  import { store, createGame, joinGame, startGame } from "../lib/state.svelte.js";
  import { COLORS } from "../lib/cards.js";

  let name = $state("");
  let color = $state("");
  let codeInput = $state(store.code ?? "");

  const taken = $derived(new Set(store.players.map((p) => p.color)));
  const isHost = $derived(store.you?.seat === 0);
  const canStart = $derived(isHost && store.players.length >= 2);

  async function create() {
    const code = await createGame();
    codeInput = code;
  }
  function doJoin() {
    if (name.trim() && color) joinGame(codeInput.trim().toUpperCase(), name.trim(), color);
  }
</script>

<div class="lobby">
  <h1>Tacta</h1>
  {#if store.error}<p class="err">{store.error}</p>{/if}

  {#if !store.joined}
    <div class="panel">
      <button class="btn" onclick={create}>Create a game</button>
      <p class="hint">…or enter a code to join:</p>
      <input class="in" placeholder="ROOM CODE" bind:value={codeInput} maxlength="6" />
      <input class="in" placeholder="Your name" bind:value={name} maxlength="64" />
      <div class="colors">
        {#each COLORS as c}
          <button
            class="swatch tcard-{c}"
            class:sel={color === c}
            disabled={taken.has(c)}
            onclick={() => (color = c)}
            aria-label={c}
          ></button>
        {/each}
      </div>
      <button class="btn" onclick={doJoin} disabled={!name.trim() || !color}>Join</button>
    </div>
  {:else}
    <div class="panel">
      <p>Room code: <b class="code">{store.code}</b> — share it to invite players.</p>
      <ul class="seats">
        {#each store.players as p}
          <li><span class="dot tcard-{p.color}"></span>{p.name}{#if p.is_host} (host){/if}</li>
        {/each}
      </ul>
      {#if isHost}
        <button class="btn" onclick={startGame} disabled={!canStart}>
          {canStart ? "Start game" : "Need 2+ players"}
        </button>
      {:else}
        <p class="hint">Waiting for the host to start…</p>
      {/if}
    </div>
  {/if}
</div>
